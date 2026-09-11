<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Message;
use App\Models\Product;
use App\Events\UserTyping;
use App\Events\MessageSent;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class GenerateAiReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $receiverId;
    public $userMessage;

    public function __construct($receiverId, $userMessage)
    {
        $this->receiverId = $receiverId;
        $this->userMessage = $userMessage;
    }

    public function handle()
    {
        // 1. Ambil ID AI Solher
        $aiUser = User::where('email', 'ai@solher.com')->first();
        if (!$aiUser) return;
        $aiUserId = $aiUser->id;

        // 👇 PERBAIKAN 1: Hapus toOthers() agar sinyal mengetik masuk ke Pelanggan
        broadcast(new UserTyping($aiUserId, $this->receiverId));

        // 2. RAG Produk
        $keywords = explode(' ', $this->userMessage);
        $query = Product::query();
        foreach ($keywords as $word) {
            if (strlen($word) > 3) {
                $query->orWhere('name', 'LIKE', '%'.$word.'%')->orWhere('description', 'LIKE', '%'.$word.'%');
            }
        }
        $relatedProducts = $query->take(5)->get();

        $databaseContext = "DATA PRODUK SOLHER:\n";
        foreach ($relatedProducts as $item) {
            $harga = number_format($item->price, 0, ',', '.');
            $databaseContext .= "- {$item->name} | Rp {$harga} | Stok: {$item->stock}\n";
        }

        $hardcodedKnowledge = "
        INFO: Solher Bag. WA: +62 888 388 8585 | Email: solherbag@gmail.com.
        RETUR: Maksimal 3 HARI sejak diterima. Wajib Video Unboxing utuh.
        ";

        $systemPrompt = "Kamu adalah Solher Care, asisten AI. Sapa pengguna 'Kak'.
        TUGAS MUTLAK:
        1. JIKA PENGGUNA MEMINTA BICARA DENGAN ADMIN ASLI/MANUSIA, WAJIB PANGGIL FUNGSI 'transfer_to_human'.\n" . $hardcodedKnowledge . "\n" . $databaseContext;

        // 3. Ambil Riwayat Chat
        $adminIds = User::whereIn('usertype', ['admin', 'superadmin'])->pluck('id')->toArray();
        $adminIds[] = $aiUserId;

        $history = Message::where(function ($q) use ($adminIds) {
            $q->where('sender_id', $this->receiverId)->whereIn('receiver_id', $adminIds);
        })->orWhere(function ($q) use ($adminIds) {
            $q->whereIn('sender_id', $adminIds)->where('receiver_id', $this->receiverId);
        })->orderBy('created_at', 'desc')->take(6)->get()->reverse();

        $geminiContents = [];
        $lastRole = '';

        foreach ($history as $chat) {
            if (empty(trim($chat->message))) continue;
            $role = $chat->sender_id === $this->receiverId ? 'user' : 'model';

            if ($role === $lastRole) {
                $lastIndex = count($geminiContents) - 1;
                $geminiContents[$lastIndex]['parts'][0]['text'] .= "\n".$chat->message;
            } else {
                $geminiContents[] = ['role' => $role, 'parts' => [['text' => $chat->message]]];
                $lastRole = $role;
            }
        }

        $geminiContents[] = ['role' => 'user', 'parts' => [['text' => $this->userMessage]]];

        try {
            $apiKey = env('GEMINI_API_KEY');
            $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key={$apiKey}";

            $tools = [
                ['functionDeclarations' => [
                    ['name' => 'transfer_to_human', 'description' => 'Panggil ini jika pengguna minta ngobrol sama manusia/admin asli.']
                ]]
            ];

            $payload = [
                'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents' => $geminiContents,
                'tools' => $tools,
                'generationConfig' => ['temperature' => 0.4],
            ];

            $response = Http::timeout(30)->post($url, $payload);

            if ($response->successful()) {
                $data = $response->json();
                $parts = $data['candidates'][0]['content']['parts'][0] ?? [];
                $aiReplyText = "";

                // if (isset($parts['functionCall'])) {
                //     $functionName = $parts['functionCall']['name'];

                //     if ($functionName === 'transfer_to_human') {
                //         Cache::put('chat_mode_' . $this->receiverId, 'human', now()->addHours(24));
                //         $aiReplyText = "Baik Kak, mohon ditunggu sebentar ya. Saya sedang menghubungkan Kakak dengan Admin Solher. Mereka akan segera membalas di obrolan ini 🙏";
                //     }
                // } else {
                //     $aiReplyText = $parts['text'] ?? "Maaf kak, saya gagal memproses jawaban.";
                // }

                if (isset($parts['functionCall'])) {
                    $functionName = $parts['functionCall']['name'];

                    if ($functionName === 'transfer_to_human') {
                        // 1. Ubah mode ke Human (Otomatis kembali ke AI jika tidak ada chat dalam 24 jam)
                        Cache::put('chat_mode_' . $this->receiverId, 'human', now()->addHours(24));

                        // 2. Berikan jawaban penenang ke customer
                        $aiReplyText = "Baik Kak, mohon ditunggu sebentar ya. Saya sedang menginfokan hal ini kepada tim Admin Solher. Mereka akan segera bergabung dan membalas di obrolan ini 🙏";

                        // 3. Tarik semua data Admin & CS
                        $admins = \App\Models\User::whereIn('usertype', ['superadmin', 'admin', 'cs'])->get();
                        $customer = \App\Models\User::find($this->receiverId);

                        // 4. Kirim Email Notifikasi ke semua Admin
                        if ($customer) {
                            foreach ($admins as $admin) {
                                \Illuminate\Support\Facades\Mail::to($admin->email)->queue(new \App\Mail\AdminChatRequestMail($customer));
                            }
                        }
                    }
                } else {
                    $aiReplyText = $parts['text'] ?? "Maaf kak, saya gagal memproses jawaban.";
                }

                if ($aiReplyText) {
                    $aiMessage = Message::create([
                        'sender_id' => $aiUserId,
                        'receiver_id' => $this->receiverId,
                        'message' => $aiReplyText,
                        'is_read' => false,
                    ]);

                    // 👇 PERBAIKAN 2 MUTLAK: Hapus toOthers() dan pastikan ada ->load('sender')
                    broadcast(new MessageSent($aiMessage->load('sender')));
                }

            } elseif ($response->status() === 503 || $response->status() === 429) {
                Log::warning('Solher AI Kena Limit/Sibuk. Coba 30 detik lagi...');
                $this->release(30);
            } else {
                Log::error('Gemini API Error: ' . $response->body());
            }

        } catch (\Exception $e) {
            Log::error('Job Solher AI Gagal: '.$e->getMessage());
        }
    }
}
