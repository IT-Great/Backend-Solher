<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use App\Models\Subscriber;
use App\Mail\NewProductAlertMail;
use Illuminate\Support\Facades\Mail;

class SendBatchedProductAlerts extends Command
{
    // Nama perintah yang akan dipanggil oleh server
    protected $signature = 'emails:send-batched-alerts';
    protected $description = 'Send a batched email for newly added products to all subscribers';

    public function handle()
    {
        // 1. Cari produk yang AKTIF dan BELUM pernah dikirim emailnya
        $newProducts = Product::where('status', 'active')
                              ->where('is_notified', false)
                              ->get();

        if ($newProducts->isEmpty()) {
            $this->info('No new products to announce. Robot is sleeping.');
            return;
        }

        // 2. Ambil semua subscriber aktif
        $subscribers = Subscriber::where('is_active', true)->pluck('email');

        if ($subscribers->isEmpty()) {
            $this->info('No active subscribers found.');
            return;
        }

        // 3. Tembakkan 1 Mailable berisi KUMPULAN PRODUK ke masing-masing email
        foreach ($subscribers as $email) {
            Mail::to($email)->queue(new NewProductAlertMail($newProducts));
        }

        // 4. [SANGAT PENTING] Tandai produk menjadi "SUDAH DI-EMAIL"
        // Agar 2 jam berikutnya robot tidak mengirimkan email produk ini lagi!
        Product::whereIn('id', $newProducts->pluck('id'))->update(['is_notified' => true]);

        $this->info('Batched emails queued successfully for ' . $newProducts->count() . ' products.');
    }
}
