<?php

namespace App\Jobs;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use App\Mail\AbandonedCheckoutMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendAbandonedCheckoutReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $transactionId;

    public function __construct(int $transactionId)
    {
        $this->transactionId = $transactionId;
    }

    public function handle(): void
    {
        $cacheKey = "abandoned_reminder_sent_{$this->transactionId}";
        if (Cache::has($cacheKey)) {
            return;
        }

        $transaction = Transaction::with(['user', 'details.product', 'payment'])
            ->find($this->transactionId);

        if (!$transaction || !$transaction->user || empty($transaction->user->email)) {
            return;
        }

        // Hanya kirim jika transaksi MASIH belum dibayar dan link pembayaran tersedia
        if (
            in_array($transaction->status, ['pending', 'awaiting_payment']) &&
            $transaction->payment &&
            $transaction->payment->status === 'pending' &&
            !empty($transaction->payment->checkout_url)
        ) {
            try {
                Mail::to($transaction->user->email)->send(
                    new AbandonedCheckoutMail($transaction, $transaction->payment->checkout_url)
                );

                // Tandai sudah dikirim selama 48 jam (Idempotent)
                Cache::put($cacheKey, true, now()->addHours(48));

                Log::info("Abandoned Checkout Reminder terkirim untuk Order: {$transaction->order_id}");
            } catch (\Throwable $e) {
                Log::error("Gagal mengirim Abandoned Checkout Reminder [{$transaction->order_id}]: " . $e->getMessage());
            }
        }
    }
}
