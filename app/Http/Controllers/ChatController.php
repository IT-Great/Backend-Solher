<?php

// namespace App\Http\Controllers;

// use App\Models\User;
// use App\Models\Message;
// use App\Events\MessageSent;
// use Illuminate\Http\Request;

// class ChatController extends Controller
// {
//     // Mengambil daftar admin untuk halaman customer
//     public function getAdmins()
//     {
//         $admins = User::where('usertype', '!=', 'user')->get();
//         return response()->json($admins);
//     }

//     // Mengambil histori chat dengan user tertentu
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

//     // Mengirim pesan
//     public function sendMessage(Request $request)
//     {
//         $request->validate([
//             'receiver_id' => 'required|exists:users,id',
//             'message' => 'required|string'
//         ]);

//         $message = Message::create([
//             'sender_id' => auth()->id(),
//             'receiver_id' => $request->receiver_id,
//             'message' => $request->message
//         ]);

//         // Pantulkan (Broadcast) ke Pusher
//         broadcast(new MessageSent($message))->toOthers();

//         return response()->json($message->load('sender', 'receiver'));
//     }
// }

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Message;
use App\Events\MessageSent;
use App\Events\MessageRead;
use App\Events\UserTyping;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function getAdmins()
    {
        $admins = User::where('usertype', '!=', 'user')->get();
        return response()->json($admins);
    }

    public function getMessages($userId)
    {
        $myId = auth()->id();

        $messages = Message::where(function ($q) use ($myId, $userId) {
            $q->where('sender_id', $myId)->where('receiver_id', $userId);
        })->orWhere(function ($q) use ($myId, $userId) {
            $q->where('sender_id', $userId)->where('receiver_id', $myId);
        })->with('sender', 'receiver')->orderBy('created_at', 'asc')->get();

        return response()->json($messages);
    }

    // public function sendMessage(Request $request)
    // {
    //     $request->validate([
    //         'receiver_id' => 'required|exists:users,id',
    //         'message' => 'required|string'
    //     ]);

    //     $message = Message::create([
    //         'sender_id' => auth()->id(),
    //         'receiver_id' => $request->receiver_id,
    //         'message' => $request->message
    //     ]);

    //     broadcast(new MessageSent($message))->toOthers();

    //     return response()->json($message->load('sender', 'receiver'));
    // }

    public function sendMessage(Request $request)
    {
        // 1. Validasi: Boleh teks saja, boleh file saja, boleh dua-duanya.
        $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'message' => 'nullable|string',
            'attachment' => 'nullable|file|mimes:jpeg,png,jpg,webp,mp4,mov,avi|max:10240' // Maksimal 10MB
        ]);

        if (!$request->message && !$request->hasFile('attachment')) {
            return response()->json(['error' => 'Message or attachment is required'], 422);
        }

        $attachmentPath = null;
        $attachmentType = null;

        // 2. Logika Upload File
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $mimeType = $file->getMimeType();

            // Deteksi apakah ini gambar atau video
            $attachmentType = str_contains($mimeType, 'video') ? 'video' : 'image';

            // Simpan ke storage/app/public/chat_attachments
            // Pastikan Anda sudah menjalankan `php artisan storage:link` di server VPS!
            $attachmentPath = $file->store('chat_attachments', 'public');
        }

        // 3. Simpan ke Database
        $message = Message::create([
            'sender_id' => auth()->id(),
            'receiver_id' => $request->receiver_id,
            'message' => $request->message,
            'attachment' => $attachmentPath,
            'attachment_type' => $attachmentType,
        ]);

        // 4. Pancarkan (Broadcast)
        broadcast(new MessageSent($message))->toOthers();

        return response()->json($message->load('sender', 'receiver'));
    }

    // [BARU] Menandai pesan telah dibaca
    public function markAsRead($senderId)
    {
        $myId = auth()->id();

        // Update semua pesan dari lawan bicara yang belum dibaca
        Message::where('sender_id', $senderId)
               ->where('receiver_id', $myId)
               ->where('is_read', false)
               ->update(['is_read' => true]);

        // Beri tahu lawan bicara bahwa pesannya sudah kita baca
        broadcast(new MessageRead($myId, $senderId))->toOthers();

        return response()->json(['status' => 'success']);
    }

    // [BARU] Mengirim sinyal typing
    public function typing(Request $request)
    {
        $request->validate(['receiver_id' => 'required|exists:users,id']);

        broadcast(new UserTyping(auth()->id(), $request->receiver_id))->toOthers();

        return response()->json(['status' => 'success']);
    }
}
