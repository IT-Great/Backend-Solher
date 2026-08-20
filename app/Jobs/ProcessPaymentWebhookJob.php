<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ShippingFactory;
use App\Traits\IdempotentWebhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessPaymentWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, IdempotentWebhook;

    public $gateway;
    public $eventId;
    public $payload;

    public function __construct(string $gateway, string $eventId, array $payload)
    {
        $this->gateway = $gateway;
        $this->eventId = $eventId;
        $this->payload = $payload;
    }

    public function handle()
    {
        // 1. Jalankan trait Idempotent agar request ganda tidak diproses dua kali
        $this->handleIdempotentWebhook($this->gateway, $this->eventId, $this->payload, function ($data) {
            if ($this->gateway === 'xendit') $this->processXendit($data);
            elseif ($this->gateway === 'stripe') $this->processStripe($data);
            elseif ($this->gateway === 'paypal') $this->processPaypal($data);
        });
    }

    protected function processXendit($data)
    {
        $externalId = $data['external_id'] ?? null;
        $status = $data['status'] ?? '';
        $paymentMethod = trim(($data['payment_method'] ?? 'Unknown') . ' ' . ($data['payment_channel'] ?? ''));
        $this->executePaymentSuccess($externalId, $status, $paymentMethod);
    }

    protected function processStripe($data)
    {
        if (($data['type'] ?? '') === 'checkout.session.completed') {
            $session = $data['data']['object'] ?? [];
            $externalId = $session['client_reference_id'] ?? null;
            $paymentMethod = !empty($session['payment_method_types']) ? strtoupper($session['payment_method_types'][0]) : 'STRIPE';
            $this->executePaymentSuccess($externalId, 'PAID', 'STRIPE ' . $paymentMethod);
        } elseif (($data['type'] ?? '') === 'checkout.session.expired') {
            $session = $data['data']['object'] ?? [];
            $externalId = $session['client_reference_id'] ?? null;
            $this->executePaymentSuccess($externalId, 'EXPIRED', 'STRIPE');
        }
    }

    protected function processPaypal($data)
    {
        if (($data['event_type'] ?? '') === 'PAYMENT.CAPTURE.COMPLETED') {
            $externalId = $data['resource']['custom_id'] ?? null;
            $this->executePaymentSuccess($externalId, 'PAID', 'PAYPAL');
        }
    }

    protected function executePaymentSuccess($externalId, $status, $paymentMethod)
    {
        if (!$externalId) return;

        DB::transaction(function () use ($externalId, $status, $paymentMethod) {
            $payment = Payment::where('external_id', $externalId)->lockForUpdate()->first();
            if (!$payment) return;

            $transaction = Transaction::lockForUpdate()->find($payment->transaction_id);
            if (!$transaction) return;

            if ($status === 'PAID') {
                if ($payment->status === 'PAID' || in_array($transaction->status, ['processing', 'completed'])) return;

                $payment->update(['status' => 'PAID']);
                $this->sendFacebookConversionAPI($transaction);

                $targetTransactionStatus = ($transaction->shipping_method === 'free') ? 'completed' : 'processing';
                $transaction->update([
                    'status' => $targetTransactionStatus,
                    'payment_method' => $paymentMethod,
                ]);

                if ($targetTransactionStatus === 'completed' && $transaction->affiliate_id && $transaction->commission_status === 'pending') {
                    $transaction->update(['commission_status' => 'settled']);
                    $affiliateUser = User::find($transaction->affiliate_id);
                    if ($affiliateUser) {
                        $affiliateUser->increment('commission_balance', $transaction->commission_earned);
                    }
                }

                $this->dispatchShippingOrder($transaction);

            } elseif ($status === 'EXPIRED' || $status === 'FAILED') {
                if ($transaction->status !== 'cancelled') {
                    $payment->update(['status' => $status]);
                    $transaction->update([
                        'status' => 'cancelled',
                        'shipping_status' => 'cancelled',
                    ]);

                    if ($transaction->points_used > 0) {
                        $transaction->user->increment('point', $transaction->points_used);
                    }

                    $transactionController = app(\App\Http\Controllers\TransactionController::class);
                    foreach ($transaction->details as $detail) {
                        $transactionController->restoreProductStock($detail->product_id, $detail->quantity);
                    }
                }
            } elseif ($status === 'PENDING' && $transaction->status === 'awaiting_payment') {
                $payment->update(['status' => $status]);
                $transaction->update(['status' => 'pending']);
            }
        });
    }

    private function dispatchShippingOrder(Transaction $transaction)
    {
        if (in_array($transaction->shipping_method, ['biteship', 'dhl'])) {
            try {
                $transaction->loadMissing(['address', 'user', 'details.product']);
                $destinationCountry = !empty($transaction->address->region) ? $transaction->address->region : (!empty($transaction->address->details['region']) ? $transaction->address->details['region'] : 'Indonesia');
                
                $shippingGateway = ShippingFactory::make($destinationCountry);
                $items = [];
                
                foreach ($transaction->details as $detail) {
                    $prod = $detail->product;
                    $dbWeight = $prod->weight > 0 ? $prod->weight : 1000;
                    $items[] = [
                        'name' => $prod->name,
                        'value' => (int) $detail->price,
                        'quantity' => (int) $detail->quantity,
                        'weight' => (int) ($dbWeight < 100 ? ($dbWeight * 1000) : $dbWeight),
                        'length' => (int) ($prod->length > 0 ? $prod->length : 20),
                        'width' => (int) ($prod->width > 0 ? $prod->width : 20),
                        'height' => (int) ($prod->height > 0 ? $prod->height : 10),
                    ];
                }

                $transactionData = [
                    'courier_company' => $transaction->courier_company,
                    'courier_type' => $transaction->courier_type,
                    'delivery_type' => $transaction->delivery_type,
                    'delivery_date' => $transaction->delivery_date,
                    'delivery_time' => $transaction->delivery_time,
                    'destination' => [
                        'name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
                        'phone' => $transaction->user->phone ?? '08123456789',
                        'address' => $transaction->address->address_location,
                        'postal_code' => $transaction->address->postal_code,
                        'latitude' => $transaction->address->latitude,
                        'longitude' => $transaction->address->longitude,
                        'country' => $destinationCountry,
                    ],
                    'items' => $items,
                ];

                $order = $shippingGateway->createOrder($transactionData);
                if (isset($order['id'])) {
                    $transaction->update([
                        'biteship_order_id' => $order['id'],
                        'tracking_number' => $order['tracking_number'],
                        'shipping_status' => $order['status'],
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Shipping Callback Exception: ' . $e->getMessage());
            }
        } else {
            $transaction->update(['tracking_number' => 'In-Store Pickup', 'shipping_status' => 'ready_for_pickup']);
        }
    }

    private function sendFacebookConversionAPI(Transaction $transaction)
    {
        try {
            $pixelId = '1060021089748617';
            $accessToken = 'EAATOy9uvwuMBSKF7gr9mSNTZCB6DYnAXDcgEmCMxLZA61GPs5hxHUfFjfNBZAQ2alYezpyGyU7zLZA6ubbM1yxADm36gBVLcYwDVyzVxfZCen9Rja5aQASYRIlgM0KgFZBbEZCWmTa60PuCGllmAJzByaa9kAvR4lWeg2SApuKCZCcWNqEnpU376xCrzfJ7hMQZDZD';
            $url = "https://graph.facebook.com/v19.0/{$pixelId}/events";

            $transaction->loadMissing(['user', 'details.product']);
            $user = $transaction->user;
            if (!$user) return;

            $hashedEmail = hash('sha256', strtolower(trim($user->email)));
            $cleanPhone = preg_replace('/[^0-9]/', '', $user->phone ?? '');
            if (!str_starts_with($cleanPhone, '62') && !empty($cleanPhone)) $cleanPhone = '62' . ltrim($cleanPhone, '0');
            $hashedPhone = !empty($cleanPhone) ? hash('sha256', $cleanPhone) : null;

            $contents = [];
            foreach ($transaction->details as $detail) {
                $contents[] = ['id' => (string) $detail->product_id, 'quantity' => (int) $detail->quantity, 'item_price' => (float) $detail->price];
            }

            $userData = ['em' => [$hashedEmail]];
            if ($hashedPhone) $userData['ph'] = [$hashedPhone];

            $payload = [
                'data' => [[
                    'event_name' => 'Purchase', 'event_time' => time(), 'action_source' => 'website',
                    'user_data' => $userData, 'custom_data' => ['currency' => $transaction->currency_code ?? 'IDR', 'value' => (float) $transaction->total_amount, 'contents' => $contents],
                ]],
            ];

            Http::post($url . '?access_token=' . $accessToken, $payload);
        } catch (\Exception $e) {
            Log::error('Facebook CAPI Exception: ' . $e->getMessage());
        }
    }
}