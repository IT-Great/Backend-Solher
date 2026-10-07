<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use App\Mail\ProductRestockMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendProductRestockNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $product;
    protected $addedQuantity;

    /**
     * Waktu maksimal job ini dieksekusi sebelum gagal
     */
    public $timeout = 300;

    public function __construct(Product $product, $addedQuantity)
    {
        $this->product = $product;
        $this->addedQuantity = $addedQuantity;
    }

    public function handle()
    {
        // 1. Ambil SEMUA user yang ber-role 'user'
        // Kita menggunakan chunk(100) agar memori RAM server tidak meledak
        User::where('usertype', 'user')->chunk(100, function ($users) {
            foreach ($users as $user) {
                try {
                    // 2. Tembak Email secara iteratif
                    Mail::to($user->email)->send(new ProductRestockMail($this->product, $this->addedQuantity));
                } catch (\Exception $e) {
                    // Jika satu gagal (misal email salah), catat di log dan Lanjutkan ke user berikutnya
                    Log::error("Gagal mengirim restock email ke {$user->email}: " . $e->getMessage());
                    continue;
                }
            }
        });
    }
}
