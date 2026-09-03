<?php

namespace App\Actions\Order;

use App\Models\Transaction;
use Xendit\Refund\RefundApi;
use Xendit\Invoice\InvoiceApi;
use Xendit\XenditSdkException;
use Xendit\Refund\CreateRefund;
use App\Services\BiteshipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessRefundAction
{
    protected $restoreInventory;

    public function __construct(RestoreInventoryAction $restoreInventory)
    {
        $this->restoreInventory = $restoreInventory;
    }

    public function execute($transactionId, BiteshipService $biteship): array
    {
        // 1. Atomic State Transition
        $locked = Transaction::where('id', $transactionId)
            ->where('status', 'refund_approved')
            ->update(['status' => 'refund_processing']);

        if (!$locked) {
            throw new \Exception('Refund is already being processed or not valid.');
        }

        $transaction = Transaction::with('payment')->find($transactionId);

        if (!$transaction->payment) {
            $transaction->update(['status' => 'refund_approved']);
            throw new \Exception('Payment data not found.');
        }

        // 2. Pre-check Biteship
        if ($transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
            try {
                $biteshipData = $biteship->getOrderTracking($transaction->biteship_order_id);
                $biteshipStatus = strtolower($biteshipData['status'] ?? '');
                $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'rejected', 'return_in_transit', 'returned'];

                if (in_array($biteshipStatus, $unCancellableStatuses)) {
                    $transaction->update(['status' => 'refund_approved']);
                    throw new \Exception('Cannot process refund: The package is already in transit or has issues.');
                }

                if (!in_array($biteshipStatus, ['cancelled'])) {
                    $cancelSuccess = $biteship->cancelOrder($transaction->biteship_order_id);
                    if (!$cancelSuccess) {
                        $transaction->update(['status' => 'refund_approved']);
                        throw new \Exception('Failed to cancel courier. Refund aborted to prevent loss.');
                    }
                }
            } catch (\Exception $e) {
                $transaction->update(['status' => 'refund_approved']);
                throw new \Exception('Failed to verify logistics status. Try again later.');
            }
        }

        // 3. Eksekusi Xendit
        try {
            $invoiceApi = new InvoiceApi;
            $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

            if (empty($invoices) || count($invoices) === 0) {
                throw new \Exception('Invoice not found in Xendit.');
            }

            $refundApi = new RefundApi;
            $refundApi->createRefund(null, null, new CreateRefund([
                'invoice_id' => $invoices[0]['id'],
                'reason' => 'REQUESTED_BY_CUSTOMER',
                'amount' => (int) $transaction->total_amount,
                'metadata' => ['order_id' => $transaction->order_id],
            ]));

            DB::transaction(function () use ($transaction) {
                $transaction->update(['status' => 'refunded']);
                if ($transaction->payment) {
                    $transaction->payment->update(['status' => 'REFUNDED']);
                }

                $statusesThatAlreadyRestoredStock = ['refund_manual_required', 'cancelled', 'shipping_failed', 'returned'];
                $originalStatus = $transaction->getOriginal('status');

                if (!in_array($originalStatus, $statusesThatAlreadyRestoredStock)) {
                    foreach ($transaction->details as $detail) {
                        $this->restoreInventory->execute($detail->product_id, $detail->quantity);
                    }
                }
            });

            return [
                'message' => 'Refund processed successfully. Funds returned automatically.',
                'type' => 'automatic',
            ];

        } catch (XenditSdkException $e) {
            Log::error('Xendit Error: ' . $e->getMessage());

            if (str_contains(strtolower($e->getMessage()), 'not supported for this channel')) {
                DB::transaction(function () use ($transaction) {
                    $transaction->update(['status' => 'refund_manual_required']);
                    foreach ($transaction->details as $detail) {
                        $this->restoreInventory->execute($detail->product_id, $detail->quantity);
                    }
                });

                return [
                    'message' => 'Automatic refund not supported. Status updated to Manual Check. Courier has been cancelled.',
                    'type' => 'manual',
                    'code' => 'MANUAL_REFUND_NEEDED'
                ];
            }

            $transaction->update(['status' => 'refund_approved']);
            throw new \Exception('Xendit Refund Failed: ' . $e->getMessage());
        } catch (\Exception $e) {
            $transaction->update(['status' => 'refund_approved']);
            throw new \Exception('Refund Error: ' . $e->getMessage());
        }
    }
}
