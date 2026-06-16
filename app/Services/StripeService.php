<?php

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

class StripeService implements PaymentGatewayInterface
{
    protected $stripe;

    public function __construct()
    {
        // Mengambil Secret Key dari .env
        $this->stripe = new StripeClient(config('services.stripe.secret'));
    }

    public function createInvoice(array $transactionData): string
    {
        try {
            // Stripe mewajibkan format item sedikit berbeda dari Xendit
            // $lineItems = [];
            // foreach ($transactionData['items'] as $item) {
            //     // Konversi harga ke format "sen" (cents) jika mata uangnya USD/EUR/SGD
            //     // Jika IDR, Stripe tidak menggunakan sen. Kita asumsikan transaksi global menggunakan USD.
            //     $unitAmount = $item['price'];
            //     if (in_array(strtoupper($transactionData['currency']), ['USD', 'SGD', 'EUR'])) {
            //         // $unitAmount = (int) ($item['price'] * 100);
            //         // Tambahkan fungsi round() untuk memastikan tidak ada sen yang hilang
            //         $unitAmount = (int) round($item['price'] * 100);
            //     }

            //     $lineItems[] = [
            //         'price_data' => [
            //             'currency' => strtolower($transactionData['currency']),
            //             'product_data' => [
            //                 'name' => $item['name'],
            //             ],
            //             'unit_amount' => $unitAmount,
            //         ],
            //         'quantity' => $item['quantity'],
            //     ];
            // }

            // Stripe mewajibkan format item sedikit berbeda dari Xendit
            $lineItems = [];
            foreach ($transactionData['items'] as $item) {

                // Daftar mata uang yang TIDAK memiliki pecahan sen (Zero-decimal currencies)
                $zeroDecimalCurrencies = ['IDR', 'JPY', 'KRW', 'VND'];
                $currencyCode = strtoupper($transactionData['currency']);

                if (!in_array($currencyCode, $zeroDecimalCurrencies)) {
                    // Jika BUKAN zero-decimal (Berarti USD, SGD, EUR, MYR, AUD, dll) -> Kali 100
                    $unitAmount = (int) round($item['price'] * 100);
                } else {
                    // Jika Zero-decimal (seperti JPY) -> Jangan dikali 100, cukup bulatkan
                    $unitAmount = (int) round($item['price']);
                }

                $lineItems[] = [
                    'price_data' => [
                        'currency'     => strtolower($currencyCode),
                        'product_data' => [
                            'name' => $item['name'],
                        ],
                        'unit_amount'  => $unitAmount, // Sekarang pasti INTEGER
                    ],
                    'quantity' => $item['quantity'],
                ];
            }

            // Membuat Stripe Checkout Session
            $session = $this->stripe->checkout->sessions->create([
                'payment_method_types' => ['card'],
                'line_items' => $lineItems,
                'mode' => 'payment',
                'success_url' => $transactionData['success_redirect_url'].'&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $transactionData['failure_redirect_url'],
                'client_reference_id' => $transactionData['external_id'],
                'customer_email' => $transactionData['payer_email'],
            ]);

            return $session->url;

        } catch (\Exception $e) {
            Log::error('Stripe Checkout API Failed: '.$e->getMessage(), ['data' => $transactionData]);
            throw new \Exception('Gagal membuat tagihan pembayaran global: '.$e->getMessage());
        }
    }

    public function handleCallback(array $payload): bool
    {
        // Logika verifikasi signature Webhook Stripe (Akan kita kerjakan di fase Webhook)
        return true;
    }
}
