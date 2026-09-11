<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Message;
use App\Events\UserTyping;
use App\Events\MessageRead;
use App\Events\MessageSent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use App\Mail\ChatMessageNotificationMail;

class ChatController extends Controller
{
    // 1. Mengambil daftar admin (Unified Inbox Solher Care)
    public function getAdmins()
    {
        $aiUser = User::firstOrCreate(
            ['email' => 'ai@solher.com'],
            ['first_name' => 'Solher', 'last_name' => 'AI', 'password' => bcrypt('password_rahasia_ai_123'), 'usertype' => 'admin', 'phone' => '00000000000']
        );

        $mainAdmin = User::where('email', '!=', 'ai@solher.com')->whereIn('usertype', ['admin', 'superadmin'])->first();

        if($mainAdmin) {
            $mainAdmin->first_name = "Solher";
            $mainAdmin->last_name = "Care";
            $mainAdmin->usertype = "Official Account";

            // Hitung unread
            $adminIds = User::whereIn('usertype', ['admin', 'superadmin'])->pluck('id')->toArray();
            $mainAdmin->unread_count = Message::whereIn('sender_id', $adminIds)
                ->where('receiver_id', auth()->id())->where('is_read', false)->count();

            return response()->json([$mainAdmin]);
        }

        return response()->json([$aiUser]);
    }

    // 2. Mengambil histori pesan (Pelanggan & Admin)
    public function getMessages($userId)
    {
        $myId = auth()->id();
        $me = User::find($myId);

        $adminIds = User::whereIn('usertype', ['admin', 'superadmin'])->pluck('id')->toArray();
        $aiUser = User::where('email', 'ai@solher.com')->first();
        if ($aiUser && !in_array($aiUser->id, $adminIds)) {
            $adminIds[] = $aiUser->id;
        }

        $isCustomer = !in_array($me->usertype, ['admin', 'superadmin']);

        if ($isCustomer) {
            $messages = Message::where(function ($q) use ($myId, $adminIds) {
                $q->where('sender_id', $myId)->whereIn('receiver_id', $adminIds);
            })->orWhere(function ($q) use ($myId, $adminIds) {
                $q->whereIn('sender_id', $adminIds)->where('receiver_id', $myId);
            })->with('sender')->orderBy('created_at', 'asc')->get();
        } else {
            $messages = Message::where(function ($q) use ($userId, $adminIds) {
                $q->whereIn('sender_id', $adminIds)->where('receiver_id', $userId);
            })->orWhere(function ($q) use ($userId, $adminIds) {
                $q->where('sender_id', $userId)->whereIn('receiver_id', $adminIds);
            })->with('sender')->orderBy('created_at', 'asc')->get();
        }

        return response()->json($messages);
    }

    // 3. Menyimpan Pesan, Logika AI Handoff, & Smart Email Notification
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
        if (trim($cleanMessage) === '') $cleanMessage = null;

        $myId = auth()->id();
        $me = User::find($myId);
        $receiver = User::find($request->receiver_id);

        $message = Message::create([
            'sender_id' => $myId,
            'receiver_id' => $request->receiver_id,
            'message' => $cleanMessage,
            'attachment' => $attachmentPath ?? null,
            'attachment_type' => $attachmentType ?? null,
        ]);

        broadcast(new MessageSent($message->load('sender')))->toOthers();

        // =========================================================================
        // 👇 IDENTIFIKASI MODE OBROLAN (AI vs HUMAN) 👇
        // =========================================================================
        // $isCustomer = !in_array($me->usertype, ['admin', 'superadmin']);
        // $isReceiverAdmin = in_array($receiver->usertype, ['admin', 'superadmin']) || $receiver->email === 'ai@solher.com';

        // $chatMode = 'human'; // Default asumsi manusia
        // if ($isCustomer && $isReceiverAdmin) {
        //     $chatMode = Cache::get('chat_mode_' . $myId, 'ai');

        //     // Jika bot sedang aktif, minta bot membalas
        //     if ($chatMode === 'ai' && $cleanMessage) {
        //         \App\Jobs\GenerateAiReply::dispatch($myId, $cleanMessage);
        //     }
        // }

        // =========================================================================
        // 👇 IDENTIFIKASI MODE OBROLAN (AI vs HUMAN) 👇
        // =========================================================================
        $isCustomer = !in_array($me->usertype, ['admin', 'superadmin', 'cs']);
        $isReceiverAdmin = in_array($receiver->usertype, ['admin', 'superadmin', 'cs']) || $receiver->email === 'ai@solher.com';

        $chatMode = 'human';
        if ($isCustomer && $isReceiverAdmin) {
            $chatMode = Cache::get('chat_mode_' . $myId, 'ai');

            if ($chatMode === 'ai' && $cleanMessage) {
                \App\Jobs\GenerateAiReply::dispatch($myId, $cleanMessage);
            } elseif ($chatMode === 'human') {
                // 👇 PERBAIKAN: Jika sedang dalam mode Human, perpanjang timer 24 jam dari sekarang!
                Cache::put('chat_mode_' . $myId, 'human', now()->addHours(24));
            }
        } elseif (!$isCustomer) {
            // 👇 PERBAIKAN: Jika yang membalas adalah Admin asli, otomatis set/perpanjang mode ke Human!
            Cache::put('chat_mode_' . $request->receiver_id, 'human', now()->addHours(24));
        }

        // =========================================================================
        // 👇 LOGIKA PENGIRIMAN EMAIL DENGAN SISTEM ANTI-SPAM (COOLDOWN 15 MENIT) 👇
        // =========================================================================
        $shouldSendEmail = true;

        // 1. Jangan kirim ke Kotak Masuk milik entitas Bot
        if ($receiver->email === 'ai@solher.com') {
            $shouldSendEmail = false;
        }

        // 2. Jangan ganggu Admin jika pelanggan sedang ditangani oleh Bot AI
        if ($isCustomer && $isReceiverAdmin && $chatMode === 'ai') {
            $shouldSendEmail = false;
        }

        if ($shouldSendEmail) {
            // Gunakan Redis Cache untuk menahan email agar tidak spamming.
            // Email hanya akan dikirim 1x setiap 15 menit untuk percakapan yang sama.
            $cooldownKey = "chat_email_cooldown_{$myId}_to_{$receiver->id}";

            if (!Cache::has($cooldownKey)) {
                try {
                    // Kirim ke antrean background
                    Mail::to($receiver->email)->queue(new ChatMessageNotificationMail($me, $message));

                    // Kunci jalur email selama 15 menit ke depan
                    Cache::put($cooldownKey, true, now()->addMinutes(15));

                    Log::info("Email notifikasi chat berhasil diantrekan ke: {$receiver->email}");
                } catch (\Exception $e) {
                    Log::error('Gagal mengirim email notifikasi chat: ' . $e->getMessage());
                }
            } else {
                // Email di-skip karena masih dalam masa cooldown 15 menit (Anti-Spam berfungsi)
            }
        }
        // =========================================================================

        return response()->json($message->load('sender', 'receiver'));
    }

    // Fungsi Pintar untuk Menandai Pesan Unified Inbox sebagai Dibaca
    public function markAsRead($senderId)
    {
        $myId = auth()->id();
        $me = User::find($myId);

        $adminIds = User::whereIn('usertype', ['admin', 'superadmin'])->pluck('id')->toArray();
        $aiUser = User::where('email', 'ai@solher.com')->first();
        if ($aiUser && !in_array($aiUser->id, $adminIds)) {
            $adminIds[] = $aiUser->id;
        }

        $isCustomer = !in_array($me->usertype, ['admin', 'superadmin']);

        if ($isCustomer) {
            Message::whereIn('sender_id', $adminIds)
                ->where('receiver_id', $myId)
                ->where('is_read', false)
                ->update(['is_read' => true]);
        } else {
            Message::where('sender_id', $senderId)
                ->whereIn('receiver_id', $adminIds)
                ->where('is_read', false)
                ->update(['is_read' => true]);
        }

        broadcast(new MessageRead($myId, $senderId))->toOthers();

        return response()->json(['status' => 'success']);
    }

    public function typing(Request $request)
    {
        broadcast(new UserTyping(auth()->id(), $request->receiver_id))->toOthers();
        return response()->json(['status' => 'success']);
    }
}
