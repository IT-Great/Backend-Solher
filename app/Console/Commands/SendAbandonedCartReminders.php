<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Cart;
use App\Mail\AbandonedCartMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendAbandonedCartReminders extends Command
{
    // Nama perintah yang akan dieksekusi di terminal
    protected $signature = 'carts:abandoned-reminder';
    protected $description = 'Send email reminders to users who abandoned their carts for more than 24 hours';

    public function handle()
    {
        $this->info('Mulai mengecek keranjang terbengkalai...');

        // CARI USER YANG MEMENUHI SYARAT:
        // 1. Punya keranjang yang tidak disentuh selama lebih dari 24 jam.
        // 2. Kolom reminder_sent_at masih NULL (belum pernah di-email).
        $timeThreshold = now()->subHours(24);

        $usersWithCarts = User::whereHas('carts', function ($query) use ($timeThreshold) {
            $query->where('updated_at', '<', $timeThreshold)
                  ->whereNull('reminder_sent_at');
        })->with(['carts' => function ($query) use ($timeThreshold) {
            // Tarik juga data relasi produknya sekalian agar tidak N+1 Query
            $query->where('updated_at', '<', $timeThreshold)
                  ->whereNull('reminder_sent_at')
                  ->with('product');
        }])->get();

        if ($usersWithCarts->isEmpty()) {
            $this->info('Tidak ada keranjang terbengkalai hari ini.');
            return;
        }

        $count = 0;

        foreach ($usersWithCarts as $user) {
            try {
                // 1. Kirim Email berisi SEMUA barang di keranjang user tersebut
                Mail::to($user->email)->send(new AbandonedCartMail($user, $user->carts));

                // 2. UPDATE STATUS: Tandai semua item di keranjang user ini bahwa email sudah dikirim
                // Agar besok tidak dikirimi email yang sama (Mencegah Spam)
                $cartIds = $user->carts->pluck('id');
                Cart::whereIn('id', $cartIds)->update(['reminder_sent_at' => now()]);

                $count++;
            } catch (\Exception $e) {
                Log::error("Gagal mengirim email abandoned cart ke {$user->email}: " . $e->getMessage());
            }
        }

        $this->info("Berhasil mengirim pengingat ke {$count} user.");
    }
}
