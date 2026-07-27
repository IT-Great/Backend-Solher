<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Mail\RefundResultMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendRefundResultJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $transaction;
    protected $action;

    /**
     * Create a new job instance.
     */
    public function __construct(Transaction $transaction, string $action)
    {
        $this->transaction = $transaction;
        $this->action = $action; // Menerima parameter 'approve' atau 'reject'
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Memastikan relasi user sudah diload (jika belum) agar tidak error saat membaca email
            $this->transaction->loadMissing('user');

            Mail::to($this->transaction->user->email)->send(new RefundResultMail($this->transaction, $this->action));
        } catch (\Exception $e) {
            Log::error("Queue Error (Refund Result {$this->action}) ke {$this->transaction->user->email}: " . $e->getMessage());
        }
    }
}
