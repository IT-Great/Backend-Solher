<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;
use App\Services\BiteshipService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ReconcileOrdersCommand extends Command
{
    /**
     * Nama dan signature dari command.
     */
    protected $signature = 'solher:reconcile-orders';

    /**
     * Deskripsi command.
     */
    protected $description = 'Active polling untuk menyinkronkan transaksi pending (dropped webhooks) dari Xendit & Biteship';

    public function handle(BiteshipService $biteship)
    {
        $this->info('Memulai Proses Reconcile (Penyembuhan Data Otomatis)...');
        Log::info('[RECONCILE] Cron Job Dimulai.');

        $this->reconcilePayments();
        $this->reconcileShipments($biteship);

        $this->info('Proses Reconcile Selesai.');
        Log::info('[RECONCILE] Cron Job Selesai.');
    }

    /**
     * 1. PENYEMBUHAN PEMBAYARAN (XENDIT / STRIPE)
     */
    private function reconcilePayments()
    {
        // Cari transaksi yang pending lebih dari 2 jam, tapi kurang dari 24 jam
        // (yang 24 jam biar diurus oleh CancelUnpaidTransactionJob)
        $pendingTransactions = Transaction::with('payment')
            ->whereIn('status', ['pending', 'awaiting_payment'])
            ->where('created_at', '<=', now()->subHours(2))
            ->where('created_at', '>=', now()->subHours(24))
            ->get();

        $healedCount = 0;

        foreach ($pendingTransactions as $trx) {
            try {
                // LOGIKA UNTUK XENDIT (IDR)
                if ($trx->currency_code === 'IDR' && $trx->payment && $trx->payment->external_id) {

                    // Hit API Xendit secara langsung untuk mengecek status Asli
                    $response = Http::withBasicAuth(env('XENDIT_SECRET_KEY'), '')
                        ->get('https://api.xendit.co/v2/invoices', [
                            'external_id' => $trx->payment->external_id
                        ]);

                    if ($response->successful() && !empty($response->json())) {
                        $invoice = $response->json()[0]; // Ambil hasil pertama

                        // Jika aslinya ternyata SUDAH DIBAYAR tapi webhook kita terlewat!
                        if (in_array($invoice['status'], ['PAID', 'SETTLED'])) {
                            // Panggil Job Webhook yang sudah ada agar diproses ulang secara paksa
                            \App\Jobs\ProcessPaymentWebhookJob::dispatchSync('xendit', $invoice['id'], $invoice);

                            $this->line("<fg=green>HEALED PAYMENT:</> Order {$trx->order_id} ternyata sudah dibayar.");
                            Log::info("[RECONCILE] Payment Healed: {$trx->order_id} (Xendit)");
                            $healedCount++;
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error("[RECONCILE] Gagal mengecek pembayaran untuk {$trx->order_id}: " . $e->getMessage());
            }
        }

        $this->info("Total Pembayaran Dipulihkan: {$healedCount}");
    }

    /**
     * 2. PENYEMBUHAN PENGIRIMAN (BITESHIP)
     */
    private function reconcileShipments(BiteshipService $biteship)
    {
        // Cari pengiriman yang tertahan di status 'picking_up' atau 'dropping_off' lebih dari 6 jam
        $stuckShipments = Transaction::where('shipping_method', 'biteship')
            ->whereNotNull('biteship_order_id')
            ->whereIn('shipping_status', ['allocated', 'picking_up', 'dropping_off'])
            ->where('updated_at', '<=', now()->subHours(6))
            ->get();

        $healedCount = 0;

        foreach ($stuckShipments as $trx) {
            try {
                // Hit API Biteship untuk melacak paket
                $trackingData = $biteship->getOrderTracking($trx->biteship_order_id);

                if (isset($trackingData['status']) && $trackingData['status'] !== $trx->shipping_status) {
                    // Jika status aslinya sudah berubah (misal sudah 'delivered' atau 'shipped')
                    // Paksa panggil Job Webhook Biteship
                    \App\Jobs\ProcessBiteshipWebhookJob::dispatchSync([
                        'order_id' => $trx->biteship_order_id,
                        'status' => $trackingData['status']
                    ]);

                    $this->line("<fg=green>HEALED SHIPMENT:</> Order {$trx->order_id} update status ke {$trackingData['status']}.");
                    Log::info("[RECONCILE] Shipment Healed: {$trx->order_id} (Status baru: {$trackingData['status']})");
                    $healedCount++;
                }
            } catch (\Exception $e) {
                Log::error("[RECONCILE] Gagal melacak paket untuk {$trx->order_id}: " . $e->getMessage());
            }
        }

        $this->info("Total Pengiriman Dipulihkan: {$healedCount}");
    }
}
