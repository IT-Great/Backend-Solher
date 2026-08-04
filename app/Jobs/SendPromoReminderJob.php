<?php

namespace App\Jobs;

use App\Models\PromoClaim;
use App\Mail\PromoReminderMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendPromoReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $email;
    public $promoCode;
    public $discountValue;

    public function __construct($email, $promoCode, $discountValue)
    {
        $this->email = $email;
        $this->promoCode = $promoCode;
        $this->discountValue = $discountValue;
    }

    public function handle()
    {
        // 1. Cek database apakah voucher ini masih ada
        $claim = PromoClaim::where('email', $this->email)
                           ->where('promo_code', $this->promoCode)
                           ->first();

        // 2. KECERDASAN OTOMATIS: 
        // Jika data tidak ada ATAU vouchernya sudah dipakai, JANGAN kirim email pengingat.
        if (!$claim || $claim->is_used) {
            return; // Batalkan pekerjaan (Job selesai dengan diam-diam)
        }

        // 3. Jika belum dipakai, kirimkan "Senggolan" Psikologis ke email pelanggan
        try {
            Mail::to($this->email)->send(new PromoReminderMail($this->promoCode, $this->discountValue));
        } catch (\Exception $e) {
            Log::error("Gagal mengirim email Drip Reminder ke {$this->email}: " . $e->getMessage());
        }
    }
}