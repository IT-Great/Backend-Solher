<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Http\Controllers\TransactionController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CancelUnpaidTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $transactionId;

    public function __construct($transactionId)
    {
        $this->transactionId = $transactionId;
    }

    public function handle()
    {
        DB::transaction(function () {
            // 👇 lockForUpdate() sangat krusial di sini untuk mencegah Race Condition
            // jika pengguna membayar TEPAT di detik ke 15:00 bersamaan dengan job ini berjalan.
            $transaction = Transaction::with('details', 'user', 'payment')
                ->lockForUpdate()
                ->find($this->transactionId);

            if (! $transaction) return;

            // Jika setelah 15 menit statusnya masih pending / belum dibayar, HANGUSKAN!
            if (in_array($transaction->status, ['pending', 'awaiting_payment'])) {

                Log::info("TTL Checkout Expired: Membatalkan Transaksi ID {$transaction->id} dan merilis stok kembali ke katalog.");

                // 1. Batalkan Transaksi & Status Pengiriman
                $transaction->update([
                    'status' => 'cancelled',
                    'shipping_status' => 'cancelled'
                ]);

                // 2. Batalkan Status Invoice/Payment
                if ($transaction->payment) {
                    $transaction->payment->update(['status' => 'EXPIRED']);
                }

                // 3. Kembalikan Poin Loyalitas Pelanggan (Jika mereka menggunakan poin)
                if ($transaction->points_used > 0 && $transaction->user) {
                    $transaction->user->increment('point', $transaction->points_used);
                }

                // 4. 🔥 RILIS KEMBALI STOK PRODUK KE KATALOG 🔥
                $transactionController = app(TransactionController::class);
                foreach ($transaction->details as $detail) {
                    $transactionController->restoreProductStock($detail->product_id, $detail->quantity);
                }
            }
        });
    }
}
