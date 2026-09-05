<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Auto-Sync: Memasukkan pesanan ke dalam tabel notifikasi jika belum ada menggunakan Eloquent
        $transactions = Transaction::where('user_id', $user->id)->get();

        foreach ($transactions as $trx) {
            $statusStr = '';
            if ($trx->status === 'completed') {
                $statusStr = 'Telah Selesai';
            } elseif ($trx->status === 'processing') {
                $statusStr = 'Sedang Diproses';
            } else {
                $statusStr = 'Menunggu Pembayaran';
            }

            $title = 'Update Pesanan ' . $trx->order_id;
            $msg = 'Status pesanan Anda saat ini: ' . $statusStr;

            // Mengecek ketersediaan notifikasi menggunakan Eloquent
            $exists = Notification::where('user_id', $user->id)
                ->where('title', $title)
                ->where('message', $msg)
                ->exists();

            if (!$exists) {
                Notification::create([
                    'user_id' => $user->id,
                    'title' => $title,
                    'message' => $msg,
                    'link' => '/orders',
                    'is_read' => false,
                    'created_at' => $trx->updated_at ?? now(),
                    'updated_at' => now()
                ]);
            }
        }

        // Mengambil data notifikasi terbaru menggunakan Eloquent
        $notifs = Notification::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $notifs
        ]);
    }

    public function markAsRead(Request $request, $id)
    {
        Notification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->update(['is_read' => true]);

        return response()->json(['status' => 'success']);
    }

    public function markAllAsRead(Request $request)
    {
        Notification::where('user_id', $request->user()->id)
            ->update(['is_read' => true]);

        return response()->json(['status' => 'success']);
    }
}
