<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Message;
use App\Events\MessageSent;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    // Mengambil daftar admin untuk halaman customer
    public function getAdmins()
    {
        $admins = User::where('usertype', '!=', 'user')->get();
        return response()->json($admins);
    }

    // Mengambil histori chat dengan user tertentu
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

    // Mengirim pesan
    public function sendMessage(Request $request)
    {
        $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'message' => 'required|string'
        ]);

        $message = Message::create([
            'sender_id' => auth()->id(),
            'receiver_id' => $request->receiver_id,
            'message' => $request->message
        ]);

        // Pantulkan (Broadcast) ke Pusher
        broadcast(new MessageSent($message))->toOthers();

        return response()->json($message->load('sender', 'receiver'));
    }
}
