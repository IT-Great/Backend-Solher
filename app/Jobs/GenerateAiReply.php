<?php

namespace App\Jobs;

use App\Events\MessageSent;
use App\Events\UserTyping;
use App\Models\Message;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenerateAiReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $receiverId; // ID pelanggan

    public $userMessage; // Teks dari pelanggan

    public function __construct($receiverId, $userMessage)
    {
        $this->receiverId = $receiverId;
        $this->userMessage = $userMessage;
    }

    // public function handle()
    // {
    //     // 1. Ambil ID AI (Sesuaikan dengan ID AI di database Anda)
    //     $aiUserId = 99;

    //     // 2. Pancarkan status "Typing..." agar UI Vue terlihat realistis
    //     broadcast(new UserTyping($aiUserId, $this->receiverId))->toOthers();

    //     // 3. Bangun System Prompt (Instruksi Utama AI)
    //     $systemPrompt = "Kamu adalah CS yang ramah untuk bisnis Solher.
    //     Gunakan bahasa Indonesia yang santai tapi sopan.
    //     Kamu bertugas menjual dan menjawab pertanyaan seputar produk Ethereal Glow Brush.
    //     Kebijakan Pengembalian: Maksimal 7 hari sejak barang diterima dengan segel utuh.
    //     Flow Pembelian: Pelanggan bisa langsung checkout melalui keranjang belanja.";

    //     // 4. Ambil 5 riwayat chat terakhir untuk memberikan konteks ke AI
    //     $history = Message::where(function($q) use ($aiUserId) {
    //             $q->where('sender_id', $this->receiverId)->where('receiver_id', $aiUserId);
    //         })
    //         ->orWhere(function($q) use ($aiUserId) {
    //             $q->where('sender_id', $aiUserId)->where('receiver_id', $this->receiverId);
    //         })
    //         ->orderBy('created_at', 'desc')
    //         ->take(5)
    //         ->get()
    //         ->reverse();

    //     $messagesForAi = [
    //         ['role' => 'system', 'content' => $systemPrompt]
    //     ];

    //     foreach ($history as $chat) {
    //         $role = $chat->sender_id === $aiUserId ? 'assistant' : 'user';
    //         $messagesForAi[] = ['role' => $role, 'content' => $chat->message ?? ''];
    //     }

    //     // Tambahkan pesan terbaru
    //     $messagesForAi[] = ['role' => 'user', 'content' => $this->userMessage];

    //     // 5. Panggil API AI (Contoh menggunakan OpenAI)
    //     try {
    //         $response = Http::withToken(env('OPENAI_API_KEY'))
    //             ->post('https://api.openai.com/v1/chat/completions', [
    //                 'model' => 'gpt-3.5-turbo', // atau gpt-4o
    //                 'messages' => $messagesForAi,
    //                 'max_tokens' => 300,
    //             ]);

    //         $aiReplyText = $response->json('choices.0.message.content');

    //         // 6. Simpan balasan AI ke Database
    //         $aiMessage = Message::create([
    //             'sender_id' => $aiUserId,
    //             'receiver_id' => $this->receiverId,
    //             'message' => $aiReplyText,
    //             'is_read' => false,
    //         ]);

    //         // 7. Pancarkan balasan kembali ke Frontend (Vue)
    //         broadcast(new MessageSent($aiMessage))->toOthers();

    //     } catch (\Exception $e) {
    //         \Illuminate\Support\Facades\Log::error('AI Error: ' . $e->getMessage());
    //     }
    // }

    public function handle()
    {
        // 1. Ambil ID AI (Sesuaikan dengan ID AI di database Anda)
        $aiUserId = 811;

        // 2. Pancarkan status "Typing..." agar UI Vue terlihat realistis
        broadcast(new UserTyping($aiUserId, $this->receiverId))->toOthers();

        // 3. Bangun System Prompt (Instruksi Utama AI)
        $systemPrompt = 'Kamu adalah Customer Service yang ramah bernama Solher AI.
Gunakan bahasa Indonesia yang santai, sopan, dan hangat.
Tugas utamamu adalah membantu pelanggan dan menjual produk tas dari toko Solher.
Saat ini kami menjual berbagai kategori tas, antara lain: Tas Selempang (Sling Bag), Ransel (Backpack), Totebag, dan Handbag.
Jika ada yang bertanya rekomendasi, tanyakan dulu tas tersebut akan digunakan untuk acara apa (misal: kuliah, kerja, atau hangout).
Kebijakan Pengembalian: Maksimal 7 hari sejak barang diterima dengan kondisi tag/segel utuh.
Flow Pembelian: Pelanggan bisa langsung menambahkan produk ke keranjang belanja dan melakukan checkout di website.';

        // // 4. Ambil 5 riwayat chat terakhir untuk memberikan konteks ke AI
        // // 👇 BAGIAN INI YANG SEBELUMNYA IKUT TER-COMMENT 👇
        // $history = Message::where(function($q) use ($aiUserId) {
        //         $q->where('sender_id', $this->receiverId)->where('receiver_id', $aiUserId);
        //     })
        //     ->orWhere(function($q) use ($aiUserId) {
        //         $q->where('sender_id', $aiUserId)->where('receiver_id', $this->receiverId);
        //     })
        //     ->orderBy('created_at', 'desc')
        //     ->take(5)
        //     ->get()
        //     ->reverse();

        // // 5. Format Riwayat Chat untuk Gemini API
        // $geminiContents = [];

        // foreach ($history as $chat) {
        //     // Di Gemini, role untuk AI adalah 'model', dan pelanggan adalah 'user'
        //     $role = $chat->sender_id === $aiUserId ? 'model' : 'user';

        //     // Skip pesan kosong (misal hanya kirim gambar tanpa teks) agar API tidak error
        //     if (!empty($chat->message)) {
        //         $geminiContents[] = [
        //             'role' => $role,
        //             'parts' => [['text' => $chat->message]]
        //         ];
        //     }
        // }

        // Tambahkan pesan terbaru dari pelanggan
        // $geminiContents[] = [
        //     'role' => 'user',
        //     'parts' => [['text' => $this->userMessage]]
        // ];

        // 4. Ambil 10 riwayat chat terakhir (ditingkatkan agar AI punya konteks utuh)
        $history = Message::where(function ($q) use ($aiUserId) {
            $q->where('sender_id', $this->receiverId)->where('receiver_id', $aiUserId);
        })
            ->orWhere(function ($q) use ($aiUserId) {
                $q->where('sender_id', $aiUserId)->where('receiver_id', $this->receiverId);
            })
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get()
            ->reverse();

        // 5. Format Riwayat Chat untuk Gemini API (Aturan Wajib Selang-Seling)
        $geminiContents = [];
        $lastRole = '';

        foreach ($history as $chat) {
            // Hindari memproses pesan yang kosong
            if (empty(trim($chat->message))) {
                continue;
            }

            $role = $chat->sender_id === $aiUserId ? 'model' : 'user';

            // Jika role-nya berurutan berulang (misal: user mengetik 3 kali berturut-turut),
            // Gabungkan pesannya dengan "enter" (\n) alih-alih membuat tumpukan role baru.
            if ($role === $lastRole) {
                $lastIndex = count($geminiContents) - 1;
                $geminiContents[$lastIndex]['parts'][0]['text'] .= "\n".$chat->message;
            } else {
                // Jika role berbeda, buat urutan blok baru yang sah
                $geminiContents[] = [
                    'role' => $role,
                    'parts' => [['text' => $chat->message]],
                ];
                $lastRole = $role;
            }
        }

        // ⚠️ CATATAN PENTING: Kode "$geminiContents[] = ['role' => 'user'..."
        // yang lama SUDAH DIHAPUS dari sini, karena pesan baru yang diketik pelanggan
        // secara otomatis sudah terangkut ke dalam query $history di atas.

        // 6. Panggil API Google Gemini
        try {
            $apiKey = env('GEMINI_API_KEY');
            // $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}";

            // $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key={$apiKey}";

            // $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}";

            $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key={$apiKey}";

            $response = Http::post($url, [
                // Menyematkan instruksi khusus sebagai CS
                'system_instruction' => [
                    'parts' => [['text' => $systemPrompt]],
                ],
                // Riwayat percakapan
                'contents' => $geminiContents,
                // Mengatur suhu agar jawaban tidak terlalu halusinasi
                'generationConfig' => [
                    'temperature' => 0.4,
                ],
            ]);

            // Jika API membalas dengan sukses
            if ($response->successful()) {
                $aiReplyText = $response->json('candidates.0.content.parts.0.text');

                // 7. Simpan balasan AI ke Database
                $aiMessage = Message::create([
                    'sender_id' => $aiUserId,
                    'receiver_id' => $this->receiverId,
                    'message' => $aiReplyText,
                    'is_read' => false,
                ]);

                // 8. Pancarkan balasan kembali ke Frontend (Vue)
                broadcast(new MessageSent($aiMessage))->toOthers();
            } else {
                Log::error('Gemini API Error: '.$response->body());
            }

        } catch (\Exception $e) {
            Log::error('Job AI Gagal: '.$e->getMessage());
        }
    }
}
