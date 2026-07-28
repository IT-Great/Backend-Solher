<?php

// namespace App\Http\Controllers;

// use App\Events\MessageRead;
// use App\Events\MessageSent;
// use App\Events\UserTyping;
// use App\Models\Message;
// use App\Models\User;
// use Illuminate\Http\Request;

// class ChatController extends Controller
// {
//     // public function getAdmins()
//     // {
//     //     $admins = User::where('usertype', '!=', 'user')->get();
//     //     return response()->json($admins);
//     // }

//     // Mengambil daftar admin (hanya Superadmin & Admin) untuk halaman customer
//     public function getAdmins()
//     {
//         // [PERBAIKAN] Gunakan whereIn untuk secara eksplisit memilih usertype yang diizinkan melayani chat
//         $admins = User::whereIn('usertype', ['admin', 'superadmin'])
//             ->orderBy('usertype', 'desc') // Opsional: Superadmin bisa ditaruh di atas
//             ->withCount(['messages as unread_count' => function ($query) {
//                 // Hitung pesan yang dikirim oleh admin ini, ditujukan ke user yang sedang login, dan belum dibaca
//                 $query->where('is_read', false)
//                     ->where('receiver_id', auth()->id());
//             }])
//             ->get();

//         return response()->json($admins);
//     }

//     public function getMessages($userId)
//     {
//         $myId = auth()->id();

//         $messages = Message::where(function ($q) use ($myId, $userId) {
//             $q->where('sender_id', $myId)->where('receiver_id', $userId);
//         })->orWhere(function ($q) use ($myId, $userId) {
//             $q->where('sender_id', $userId)->where('receiver_id', $myId);
//         })->with('sender', 'receiver')->orderBy('created_at', 'asc')->get();

//         return response()->json($messages);
//     }

//     // public function sendMessage(Request $request)
//     // {
//     //     $request->validate([
//     //         'receiver_id' => 'required|exists:users,id',
//     //         'message' => 'required|string'
//     //     ]);

//     //     $message = Message::create([
//     //         'sender_id' => auth()->id(),
//     //         'receiver_id' => $request->receiver_id,
//     //         'message' => $request->message
//     //     ]);

//     //     broadcast(new MessageSent($message))->toOthers();

//     //     return response()->json($message->load('sender', 'receiver'));
//     // }

//     public function sendMessage(Request $request)
//     {
//         // 1. Validasi: Boleh teks saja, boleh file saja, boleh dua-duanya.
//         $request->validate([
//             'receiver_id' => 'required|exists:users,id',
//             'message' => 'nullable|string',
//             'attachment' => 'nullable|file|mimes:jpeg,png,jpg,webp,mp4,mov,avi|max:10240', // Maksimal 10MB
//         ]);

//         if (! $request->message && ! $request->hasFile('attachment')) {
//             return response()->json(['error' => 'Message or attachment is required'], 422);
//         }

//         $attachmentPath = null;
//         $attachmentType = null;

//         // 2. Logika Upload File
//         if ($request->hasFile('attachment')) {
//             $file = $request->file('attachment');
//             $mimeType = $file->getMimeType();

//             // Deteksi apakah ini gambar atau video
//             $attachmentType = str_contains($mimeType, 'video') ? 'video' : 'image';

//             // Simpan ke storage/app/public/chat_attachments
//             // Pastikan Anda sudah menjalankan `php artisan storage:link` di server VPS!
//             $attachmentPath = $file->store('chat_attachments', 'public');
//         }

//         // [PERBAIKAN] Bersihkan nilai message. Jika kosong "", paksa menjadi null.
//         $cleanMessage = $request->message;
//         if (trim($cleanMessage) === '') {
//             $cleanMessage = null;
//         }

//         // 3. Simpan ke Database
//         $message = Message::create([
//             'sender_id' => auth()->id(),
//             'receiver_id' => $request->receiver_id,
//             'message' => $cleanMessage,
//             'attachment' => $attachmentPath ?? null,
//             'attachment_type' => $attachmentType ?? null,
//         ]);

//         // 4. Pancarkan (Broadcast)
//         broadcast(new MessageSent($message))->toOthers();

//         return response()->json($message->load('sender', 'receiver'));
//     }

//     // [BARU] Menandai pesan telah dibaca
//     public function markAsRead($senderId)
//     {
//         $myId = auth()->id();

//         // Update semua pesan dari lawan bicara yang belum dibaca
//         Message::where('sender_id', $senderId)
//             ->where('receiver_id', $myId)
//             ->where('is_read', false)
//             ->update(['is_read' => true]);

//         // Beri tahu lawan bicara bahwa pesannya sudah kita baca
//         broadcast(new MessageRead($myId, $senderId))->toOthers();

//         return response()->json(['status' => 'success']);
//     }

//     // [BARU] Mengirim sinyal typing
//     public function typing(Request $request)
//     {
//         $request->validate(['receiver_id' => 'required|exists:users,id']);

//         broadcast(new UserTyping(auth()->id(), $request->receiver_id))->toOthers();

//         return response()->json(['status' => 'success']);
//     }
// }

// namespace App\Http\Controllers;

// use App\Events\MessageRead;
// use App\Events\MessageSent;
// use App\Events\UserTyping;
// use App\Models\Message;
// use App\Models\User;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Mail; // [BARU] Import Mail Facade
// use App\Mail\ChatMessageNotificationMail; // [BARU] Import Mailable

// class ChatController extends Controller
// {
//     // Mengambil daftar admin (hanya Superadmin & Admin) untuk halaman customer
//     public function getAdmins()
//     {
//         $admins = User::whereIn('usertype', ['admin'])
//             ->orderBy('usertype', 'desc')
//             ->withCount(['messages as unread_count' => function ($query) {
//                 $query->where('is_read', false)
//                     ->where('receiver_id', auth()->id());
//             }])
//             ->get();

//         return response()->json($admins);
//     }

//     public function getMessages($userId)
//     {
//         $myId = auth()->id();

//         $messages = Message::where(function ($q) use ($myId, $userId) {
//             $q->where('sender_id', $myId)->where('receiver_id', $userId);
//         })->orWhere(function ($q) use ($myId, $userId) {
//             $q->where('sender_id', $userId)->where('receiver_id', $myId);
//         })->with('sender', 'receiver')->orderBy('created_at', 'asc')->get();

//         return response()->json($messages);
//     }

//     public function sendMessage(Request $request)
//     {
//         // 1. Validasi
//         $request->validate([
//             'receiver_id' => 'required|exists:users,id',
//             'message' => 'nullable|string',
//             'attachment' => 'nullable|file|mimes:jpeg,png,jpg,webp,mp4,mov,avi|max:10240',
//         ]);

//         if (! $request->message && ! $request->hasFile('attachment')) {
//             return response()->json(['error' => 'Message or attachment is required'], 422);
//         }

//         $attachmentPath = null;
//         $attachmentType = null;

//         // 2. Logika Upload File
//         if ($request->hasFile('attachment')) {
//             $file = $request->file('attachment');
//             $mimeType = $file->getMimeType();
//             $attachmentType = str_contains($mimeType, 'video') ? 'video' : 'image';
//             $attachmentPath = $file->store('chat_attachments', 'public');
//         }

//         // Bersihkan nilai message
//         $cleanMessage = $request->message;
//         if (trim($cleanMessage) === '') {
//             $cleanMessage = null;
//         }

//         // 3. Simpan ke Database
//         $message = Message::create([
//             'sender_id' => auth()->id(),
//             'receiver_id' => $request->receiver_id,
//             'message' => $cleanMessage,
//             'attachment' => $attachmentPath ?? null,
//             'attachment_type' => $attachmentType ?? null,
//         ]);

//         // 4. Pancarkan (Broadcast) via WebSockets
//         broadcast(new MessageSent($message))->toOthers();

//         // // ====================================================================
//         // // 👇 [BARU] 5. KIRIM EMAIL NOTIFIKASI SECARA BACKGROUND (QUEUE) 👇
//         // // ====================================================================
//         // try {
//         //     $receiver = User::find($request->receiver_id);
//         //     $sender = auth()->user();

//         //     if ($receiver && $receiver->email) {
//         //         // Menggunakan queue() agar pengiriman chat di browser tidak lag
//         //         Mail::to($receiver->email)->queue(new ChatMessageNotificationMail($sender, $message));
//         //     }
//         // } catch (\Exception $e) {
//         //     report($e);
//         //     \Illuminate\Support\Facades\Log::error('Gagal mengirim email chat: ' . $e->getMessage());
//         // }
//         // // ====================================================================

//         // ====================================================================
//         // 👇 [BARU] DETEKSI JIKA PENERIMA ADALAH AI 👇
//         // ====================================================================
//         $aiUserId = 811; // Ganti dengan ID user AI Anda

//         if ($request->receiver_id == $aiUserId && $cleanMessage) {
//             // Lemparkan tugas membalas ke Background Job
//             \App\Jobs\GenerateAiReply::dispatch(auth()->id(), $cleanMessage);
//         } else {
//             // Jika dikirim ke manusia biasa, kirim email notifikasi
//             try {
//                 $receiver = User::find($request->receiver_id);
//                 $sender = auth()->user();

//                 if ($receiver && $receiver->email) {
//                     Mail::to($receiver->email)->queue(new ChatMessageNotificationMail($sender, $message));
//                 }
//             } catch (\Exception $e) {
//                 report($e);
//                 \Illuminate\Support\Facades\Log::error('Gagal mengirim email chat: ' . $e->getMessage());
//             }
//         }
//         // ====================================================================

//         return response()->json($message->load('sender', 'receiver'));
//     }

//     // Menandai pesan telah dibaca
//     public function markAsRead($senderId)
//     {
//         $myId = auth()->id();

//         Message::where('sender_id', $senderId)
//             ->where('receiver_id', $myId)
//             ->where('is_read', false)
//             ->update(['is_read' => true]);

//         broadcast(new MessageRead($myId, $senderId))->toOthers();

//         return response()->json(['status' => 'success']);
//     }

//     // Mengirim sinyal typing
//     public function typing(Request $request)
//     {
//         $request->validate(['receiver_id' => 'required|exists:users,id']);

//         broadcast(new UserTyping(auth()->id(), $request->receiver_id))->toOthers();

//         return response()->json(['status' => 'success']);
//     }
// }

// namespace App\Http\Controllers;

// use App\Events\MessageRead;
// use App\Events\MessageSent;
// use App\Events\UserTyping;
// use App\Models\Message;
// use App\Models\User;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Mail;
// use App\Mail\ChatMessageNotificationMail;
// use Illuminate\Support\Facades\Cache; // [BARU] Import Cache

// class ChatController extends Controller
// {
//     // Mengambil daftar admin (Unified Inbox: Hanya 1 Kontak Resmi)
//     public function getAdmins()
//     {
//         // Buat 1 akun virtual representatif toko
//         $supportUser = User::firstOrCreate(
//             ['email' => 'support@solher.com'],
//             [
//                 'first_name' => 'Solher',
//                 'last_name' => 'Care',
//                 'password' => bcrypt('password_rahasia_ai_123'),
//                 'usertype' => 'admin',
//                 'phone' => '00000000000'
//             ]
//         );

//         // Hitung pesan yang belum dibaca dari Solher Care ke User
//         $unreadCount = Message::where('sender_id', $supportUser->id)
//             ->where('receiver_id', auth()->id())
//             ->where('is_read', false)
//             ->count();

//         $supportUser->unread_count = $unreadCount;
//         $supportUser->is_official = true;

//         return response()->json([$supportUser]);
//     }

//     public function getMessages($userId)
//     {
//         $myId = auth()->id();

//         $messages = Message::where(function ($q) use ($myId, $userId) {
//             $q->where('sender_id', $myId)->where('receiver_id', $userId);
//         })->orWhere(function ($q) use ($myId, $userId) {
//             $q->where('sender_id', $userId)->where('receiver_id', $myId);
//         })->with('sender', 'receiver')->orderBy('created_at', 'asc')->get();

//         return response()->json($messages);
//     }

//     public function sendMessage(Request $request)
//     {
//         $request->validate([
//             'receiver_id' => 'required|exists:users,id',
//             'message' => 'nullable|string',
//             'attachment' => 'nullable|file|mimes:jpeg,png,jpg,webp,mp4,mov,avi|max:10240',
//         ]);

//         if (! $request->message && ! $request->hasFile('attachment')) {
//             return response()->json(['error' => 'Message or attachment is required'], 422);
//         }

//         $attachmentPath = null;
//         $attachmentType = null;

//         if ($request->hasFile('attachment')) {
//             $file = $request->file('attachment');
//             $mimeType = $file->getMimeType();
//             $attachmentType = str_contains($mimeType, 'video') ? 'video' : 'image';
//             $attachmentPath = $file->store('chat_attachments', 'public');
//         }

//         $cleanMessage = $request->message;
//         if (trim($cleanMessage) === '') {
//             $cleanMessage = null;
//         }

//         $message = Message::create([
//             'sender_id' => auth()->id(),
//             'receiver_id' => $request->receiver_id,
//             'message' => $cleanMessage,
//             'attachment' => $attachmentPath ?? null,
//             'attachment_type' => $attachmentType ?? null,
//         ]);

//         broadcast(new MessageSent($message))->toOthers();

//         $receiver = User::find($request->receiver_id);

//         // ====================================================================
//         // [BARU] LOGIKA HYBRID (AI HANDOFF TO HUMAN)
//         // ====================================================================
//         if ($receiver->email === 'support@solher.com' && $cleanMessage) {

//             // Cek apakah chat ini sedang dalam mode manusia atau AI
//             // Defaultnya adalah 'ai'. Jika expired (24 jam), balik ke AI lagi.
//             $chatMode = Cache::get('chat_mode_' . auth()->id(), 'ai');

//             if ($chatMode === 'ai') {
//                 // Lempar ke AI
//                 \App\Jobs\GenerateAiReply::dispatch(auth()->id(), $cleanMessage);
//             } else {
//                 // Lempar Notifikasi ke Admin Asli (Manusia) bahwa ada user butuh bantuan
//                 try {
//                     $sender = auth()->user();
//                     // Kirim ke email asli perusahaan
//                     Mail::to('care@solherbag.com')->queue(new ChatMessageNotificationMail($sender, $message));
//                 } catch (\Exception $e) {
//                     \Illuminate\Support\Facades\Log::error('Gagal mengirim email chat: ' . $e->getMessage());
//                 }
//             }
//         }

//         return response()->json($message->load('sender', 'receiver'));
//     }

//     public function markAsRead($senderId)
//     {
//         $myId = auth()->id();

//         Message::where('sender_id', $senderId)
//             ->where('receiver_id', $myId)
//             ->where('is_read', false)
//             ->update(['is_read' => true]);

//         broadcast(new MessageRead($myId, $senderId))->toOthers();

//         return response()->json(['status' => 'success']);
//     }

//     public function typing(Request $request)
//     {
//         $request->validate(['receiver_id' => 'required|exists:users,id']);
//         broadcast(new UserTyping(auth()->id(), $request->receiver_id))->toOthers();
//         return response()->json(['status' => 'success']);
//     }

//     // (Biarkan fungsi cekStatusPesananLokal dan generateGeminiResponse jika Anda menaruhnya di sini sebelumnya.
//     // Tapi karena Anda memindahkannya ke Job, kita akan update Job-nya di bawah).
// }

namespace App\Jobs;

use App\Events\MessageSent;
use App\Events\UserTyping;
use App\Models\Message;
use App\Models\Product;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache; // [BARU] Import Cache

class GenerateAiReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $receiverId; // Ini adalah ID dari user pembeli
    public $userMessage;

    public function __construct($receiverId, $userMessage)
    {
        $this->receiverId = $receiverId;
        $this->userMessage = $userMessage;
    }

    public function handle()
    {
        // Cari ID dari akun virtual Support
        $supportUser = User::where('email', 'support@solher.com')->first();
        if (!$supportUser) return;

        $aiUserId = $supportUser->id;

        broadcast(new UserTyping($aiUserId, $this->receiverId))->toOthers();

        // [LOGIKA MINI-RAG PRODUK TETAP SAMA SEPERTI SEBELUMNYA]
        $keywords = explode(' ', $this->userMessage);
        $query = Product::query();
        foreach ($keywords as $word) {
            if (strlen($word) > 3) {
                $query->orWhere('name', 'LIKE', '%'.$word.'%')
                    ->orWhere('description', 'LIKE', '%'.$word.'%');
            }
        }
        $relatedProducts = $query->take(5)->get();

        $databaseContext = "DATA PRODUK SOLHER SAAT INI (REAL-TIME):\n";
        foreach ($relatedProducts as $item) {
            $harga = number_format($item->price, 0, ',', '.');
            $databaseContext .= "- {$item->name} | Rp {$harga} | Stok: {$item->stock}\n";
        }

        $hardcodedKnowledge = "
        INFORMASI PERUSAHAAN: Gycora Essence (Solher). WA: 082273736200. Email: care@solherbag.com.
        KEBIJAKAN RETUR: Maksimal 3 HARI setelah barang diterima. Wajib kirim Video Unboxing tanpa edit ke email. Refund max 30 hari.
        ";

        $systemPrompt = "Kamu adalah Solher Care, asisten virtual garda terdepan untuk butik tas Solher. Gunakan bahasa Indonesia santai (sapa pengguna 'Kak').
        TUGAS UTAMA:
        - Jawab pertanyaan seputar produk atau pesanan.
        - JIKA pengguna terang-terangan minta bicara dengan Admin Manusia, atau keluhannya sangat marah/kompleks, WAJIB panggil fungsi 'transfer_to_human'.

        " . $hardcodedKnowledge . "\n\n" . $databaseContext;

        $history = Message::where(function ($q) use ($aiUserId) {
            $q->where('sender_id', $this->receiverId)->where('receiver_id', $aiUserId);
        })
        ->orWhere(function ($q) use ($aiUserId) {
            $q->where('sender_id', $aiUserId)->where('receiver_id', $this->receiverId);
        })
        ->orderBy('created_at', 'desc')->take(10)->get()->reverse();

        $geminiContents = [];
        $lastRole = '';

        foreach ($history as $chat) {
            if (empty(trim($chat->message))) continue;
            $role = $chat->sender_id === $aiUserId ? 'model' : 'user';

            if ($role === $lastRole) {
                $lastIndex = count($geminiContents) - 1;
                $geminiContents[$lastIndex]['parts'][0]['text'] .= "\n".$chat->message;
            } else {
                $geminiContents[] = [
                    'role' => $role,
                    'parts' => [['text' => $chat->message]],
                ];
                $lastRole = $role;
            }
        }

        try {
            $apiKey = env('GEMINI_API_KEY');
            $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}";

            // ====================================================================
            // [BARU] DAFTARKAN ALAT HANDOFF KE MANUSIA
            // ====================================================================
            $tools = [
                [
                    'functionDeclarations' => [
                        [
                            'name' => 'transfer_to_human',
                            'description' => 'Panggil fungsi ini jika user meminta bicara dengan manusia/admin.',
                            'parameters' => ['type' => 'OBJECT', 'properties' => ['alasan' => ['type' => 'STRING']]]
                        ]
                        // (Anda bisa menambahkan tool 'lacak_pesanan_database' di sini juga jika mau)
                    ]
                ]
            ];

            $response = Http::post($url, [
                'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents' => $geminiContents,
                'tools' => $tools,
                'generationConfig' => ['temperature' => 0.4],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $parts = $data['candidates'][0]['content']['parts'][0] ?? [];

                // Cek Jika AI memutuskan untuk memanggil manusia
                if (isset($parts['functionCall'])) {
                    $functionName = $parts['functionCall']['name'];
                    if ($functionName === 'transfer_to_human') {

                        // Kunci Cache ke Mode Manusia selama 24 Jam
                        Cache::put('chat_mode_' . $this->receiverId, 'human', now()->addHours(24));

                        $aiReplyText = "Baik Kak, mohon ditunggu sebentar ya. Saya sedang menghubungkan Kakak dengan Admin manusia kami. Mereka akan segera membalas di obrolan ini 🙏";
                    }
                } else {
                    $aiReplyText = $parts['text'] ?? "Maaf kak, saya tidak mengerti.";
                }

                $aiMessage = Message::create([
                    'sender_id' => $aiUserId,
                    'receiver_id' => $this->receiverId,
                    'message' => $aiReplyText,
                    'is_read' => false,
                ]);

                broadcast(new MessageSent($aiMessage))->toOthers();

            } else {
                Log::error('Gemini API Error: ' . $response->body());
            }
        } catch (\Exception $e) {
            Log::error('Job AI Gagal: '.$e->getMessage());
        }
    }
}
