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
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\ChatMessageNotificationMail;
use Illuminate\Support\Facades\Cache;

class ChatController extends Controller
{
    // Mengambil daftar admin (Unified Inbox)
    public function getAdmins()
    {
        $supportUser = User::firstOrCreate(
            ['email' => 'support@solher.com'],
            [
                'first_name' => 'Solher',
                'last_name' => 'Care',
                'password' => bcrypt('password_rahasia_ai_123'),
                'usertype' => 'admin', 
                'phone' => '00000000000'
            ]
        );

        $unreadCount = Message::where('sender_id', $supportUser->id)
            ->where('receiver_id', auth()->id())
            ->where('is_read', false)
            ->count();

        $supportUser->unread_count = $unreadCount;
        $supportUser->is_official = true;

        return response()->json([$supportUser]);
    }

    // [PERBAIKAN] Mengambil pesan dengan identitas yang benar
    public function getMessages($userId)
    {
        $myId = auth()->id();
        $me = User::find($myId);

        // Jika yang sedang login adalah ADMIN MANUSIA, paksa dia menjadi Solher Care
        if ($me->usertype === 'admin') {
            $supportUser = User::where('email', 'support@solher.com')->first();
            $myId = $supportUser->id; // Admin mengambil alih ID Solher Care
        }

        $messages = Message::where(function ($q) use ($myId, $userId) {
            $q->where('sender_id', $myId)->where('receiver_id', $userId);
        })->orWhere(function ($q) use ($myId, $userId) {
            $q->where('sender_id', $userId)->where('receiver_id', $myId);
        })->with('sender', 'receiver')->orderBy('created_at', 'asc')->get();

        return response()->json($messages);
    }

    // [PERBAIKAN] Mengirim pesan dengan identitas yang benar
    public function sendMessage(Request $request)
    {
        $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'message' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:jpeg,png,jpg,webp,mp4,mov,avi|max:10240',
        ]);

        if (! $request->message && ! $request->hasFile('attachment')) {
            return response()->json(['error' => 'Message or attachment is required'], 422);
        }

        $attachmentPath = null;
        $attachmentType = null;

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $mimeType = $file->getMimeType();
            $attachmentType = str_contains($mimeType, 'video') ? 'video' : 'image';
            $attachmentPath = $file->store('chat_attachments', 'public');
        }

        $cleanMessage = $request->message;
        if (trim($cleanMessage) === '') {
            $cleanMessage = null;
        }

        $myId = auth()->id();
        $me = User::find($myId);

        // Jika yang mengetik balasan adalah ADMIN MANUSIA, ubah pengirimnya menjadi Solher Care
        $senderId = $myId;
        if ($me->usertype === 'admin') {
            $supportUser = User::where('email', 'support@solher.com')->first();
            $senderId = $supportUser->id;
        }

        $message = Message::create([
            'sender_id' => $senderId,
            'receiver_id' => $request->receiver_id,
            'message' => $cleanMessage,
            'attachment' => $attachmentPath ?? null,
            'attachment_type' => $attachmentType ?? null,
        ]);

        broadcast(new MessageSent($message))->toOthers();

        $receiver = User::find($request->receiver_id);

        // LOGIKA HYBRID (AI HANDOFF TO HUMAN)
        // Hanya memicu AI jika Penerimanya Solher Care DAN Pengirimnya BUKAN Admin
        if ($receiver->email === 'support@solher.com' && $cleanMessage && $me->usertype !== 'admin') {
            
            $chatMode = Cache::get('chat_mode_' . $myId, 'ai');

            if ($chatMode === 'ai') {
                \App\Jobs\GenerateAiReply::dispatch($myId, $cleanMessage);
            } else {
                try {
                    Mail::to('care@solherbag.com')->queue(new ChatMessageNotificationMail($me, $message));
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Gagal mengirim email chat: ' . $e->getMessage());
                }
            }
        } 

        return response()->json($message->load('sender', 'receiver'));
    }

    public function markAsRead($senderId)
    {
        $myId = auth()->id();
        $me = User::find($myId);

        // Jika yang membaca adalah ADMIN MANUSIA, paksa dia menjadi Solher Care
        if ($me->usertype === 'admin') {
            $supportUser = User::where('email', 'support@solher.com')->first();
            $myId = $supportUser->id;
        }

        Message::where('sender_id', $senderId)
            ->where('receiver_id', $myId)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        broadcast(new MessageRead($myId, $senderId))->toOthers();

        return response()->json(['status' => 'success']);
    }

    public function typing(Request $request)
    {
        $request->validate(['receiver_id' => 'required|exists:users,id']);
        
        $myId = auth()->id();
        $me = User::find($myId);
        $senderId = $myId;

        // Admin manusia juga mengetik atas nama Solher Care
        if ($me->usertype === 'admin') {
            $supportUser = User::where('email', 'support@solher.com')->first();
            $senderId = $supportUser->id;
        }

        broadcast(new UserTyping($senderId, $request->receiver_id))->toOthers();
        return response()->json(['status' => 'success']);
    }

    // (Biarkan fungsi cekStatusPesananLokal dan generateGeminiResponse tetap ada di bawah sini jika Anda menggunakannya secara langsung tanpa Job)
}
