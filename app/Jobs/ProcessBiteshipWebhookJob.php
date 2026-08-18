<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\User;
use App\Events\ShippingStatusUpdated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessBiteshipWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function handle()
    {
        $biteshipOrderId = $this->payload['order_id'] ?? null;
        $status = strtolower($this->payload['status'] ?? '');
        $waybill = $this->payload['courier_waybill_id'] ?? null;

        if (!$biteshipOrderId) return;

        DB::transaction(function () use ($biteshipOrderId, $status, $waybill) {
            $transaction = Transaction::where('biteship_order_id', $biteshipOrderId)
                ->lockForUpdate()
                ->first();

            if (! $transaction) return;
            if ($transaction->status === 'completed' && $status === 'delivered') return;

            $updates = ['shipping_status' => $status];

            if ($waybill && in_array($transaction->tracking_number, ['Pending', null])) {
                $updates['tracking_number'] = $waybill;
            }

            if ($status === 'delivered' && $transaction->status === 'processing') {
                $updates['status'] = 'completed';

                if ($transaction->affiliate_id && $transaction->commission_status === 'pending') {
                    $updates['commission_status'] = 'settled';
                    $affiliateUser = User::find($transaction->affiliate_id);
                    if ($affiliateUser) {
                        $affiliateUser->increment('commission_balance', $transaction->commission_earned);
                    }
                }

                $transaction->update($updates);
                $this->checkAndAssignMembership($transaction->user);
                $transaction->user->refresh();

                if ($transaction->point > 0 && $transaction->user->is_membership) {
                    $transaction->user->increment('point', $transaction->point);
                }
            } else {
                if (in_array($status, ['cancelled', 'rejected']) && $transaction->status === 'processing') {
                    $updates['status'] = 'refund_manual_required';
                    $updates['tracking_number'] = 'Logistics Cancelled/Rejected';
                    Log::warning("Biteship Logistics Cancelled for Order ID: {$transaction->order_id}.");
                } elseif ($status === 'disposed' && $transaction->status === 'processing') {
                    $updates['status'] = 'shipping_failed';
                    $updates['tracking_number'] = 'Shipping Failed';
                } elseif ($status === 'returned' && $transaction->status === 'processing') {
                    $updates['status'] = 'returned';
                    $updates['tracking_number'] = 'Shipping Returned';
                }
                
                $transaction->update($updates);
            }

            SendShippingUpdateJob::dispatch($transaction->id, $status);
            $transaction->refresh();
            broadcast(new ShippingStatusUpdated($transaction, "Status pengiriman diperbarui: " . strtoupper($status)));
        });
    }

    private function checkAndAssignMembership($user)
    {
        if ($user->is_membership) return;
        $totalSpent = Transaction::where('user_id', $user->id)->where('status', 'completed')->sum('total_amount');
        if ($totalSpent >= 100000) {
            $user->update(['is_membership' => true]);
        }
    }
}