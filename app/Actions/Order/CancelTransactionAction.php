<?php

namespace App\Actions\Order;

use App\Models\PromoClaim;
use App\Models\Transaction;
use Xendit\Refund\RefundApi;
use Xendit\Invoice\InvoiceApi;
use Xendit\Refund\CreateRefund;
use App\Services\BiteshipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CancelTransactionAction
{
    protected $restoreInventory;

    public function __construct(RestoreInventoryAction $restoreInventory)
    {
        $this->restoreInventory = $restoreInventory;
    }

    public function execute(Transaction $transaction, BiteshipService $biteship): array
    {
        // 1. Pre-check & Batalkan Logistik (Di luar DB Transaction)
        if ($transaction->status === 'processing' && $transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
            $biteshipData = $biteship->getOrderTracking($transaction->biteship_order_id);
            $biteshipStatus = strtolower($biteshipData['status'] ?? '');

            $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'return_in_transit', 'returned', 'disposed'];

            if (in_array($biteshipStatus, $unCancellableStatuses)) {
                throw new \Exception('Pemesanan tidak dapat dibatalkan: Paket sudah diproses oleh kurir.');
            }

            $biteship->cancelOrder($transaction->biteship_order_id);

            // 2. Auto-Refund Xendit
            try {
                $transaction->load('payment');
                if ($transaction->payment && $transaction->payment->external_id) {
                    $invoiceApi = new InvoiceApi;
                    $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

                    if (!empty($invoices) && count($invoices) > 0) {
                        $refundApi = new RefundApi;
                        $refundApi->createRefund(null, null, new CreateRefund([
                            'invoice_id' => $invoices[0]['id'],
                            'reason' => 'REQUESTED_BY_CUSTOMER',
                            'amount' => (int) $transaction->total_amount,
                            'metadata' => ['order_id' => $transaction->order_id],
                        ]));
                    }
                }
            } catch (\Exception $e) {
                Log::error('Auto Refund Gagal: ' . $e->getMessage());

                DB::transaction(function () use ($transaction) {
                    $transaction->update(['status' => 'refund_manual_required']);
                    foreach ($transaction->details as $detail) {
                        $this->restoreInventory->execute($detail->product_id, $detail->quantity);
                    }
                });

                return ['message' => 'Pesanan dibatalkan, namun pengembalian dana otomatis gagal. Admin akan memprosesnya secara manual.'];
            }
        }

        // 3. Batalkan Transaksi Database Utama
        DB::transaction(function () use ($transaction) {
            $lockedTransaction = Transaction::lockForUpdate()->find($transaction->id);

            if ($lockedTransaction->status !== 'refund_manual_required' && $lockedTransaction->status !== 'cancelled') {
                $lockedTransaction->update([
                    'status' => 'cancelled',
                    'shipping_status' => 'cancelled',
                ]);

                if ($lockedTransaction->points_used > 0) {
                    $lockedTransaction->user->increment('point', $lockedTransaction->points_used);
                }

                if ($lockedTransaction->promo_code) {
                    if ($lockedTransaction->promo_code === 'SOLHERMEMBER') {
                        $lockedTransaction->user->update(['has_used_member_voucher' => false]);
                    } else {
                        PromoClaim::where('email', $lockedTransaction->user->email)
                            ->where('promo_code', $lockedTransaction->promo_code)
                            ->update(['is_used' => false, 'used_at' => null]);
                    }
                }

                if ($lockedTransaction->payment) {
                    $lockedTransaction->payment->update(['status' => 'EXPIRED']);
                }

                foreach ($lockedTransaction->details as $detail) {
                    $this->restoreInventory->execute($detail->product_id, $detail->quantity);
                }
            }
        });

        return ['message' => 'Order cancelled successfully'];
    }
}
