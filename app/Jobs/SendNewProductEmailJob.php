<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use App\Mail\NewProductAlertMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendNewProductEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $email;
    public $product;

    /**
     * Create a new job instance.
     */
    public function __construct(string $email, Product $product)
    {
        $this->email = $email;
        $this->product = $product;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Mengirim email menggunakan Mailable yang sudah Anda buat sebelumnya
            Mail::to($this->email)->send(new NewProductAlertMail($this->product));
        } catch (\Exception $e) {
            // Catat error jika pengiriman ke satu email gagal, agar tidak mematikan worker
            Log::error("Failed to send product alert to {$this->email}: " . $e->getMessage());

            // Jika ingin job dicoba ulang saat gagal, Anda bisa throw exception di sini.
            // Namun, untuk blast email marketing, biasanya lebih aman dicatat saja
            // agar tidak terjadi spam loop.
        }
    }
}
