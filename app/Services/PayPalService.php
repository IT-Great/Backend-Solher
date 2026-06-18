<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Exception;
use Illuminate\Support\Facades\Log;

class PayPalService
{
    protected $clientId;
    protected $secret;
    protected $baseUrl;

    public function __construct()
    {
        $this->clientId = config('services.paypal.client_id');
        $this->secret = config('services.paypal.secret');

        // Deteksi mode sandbox atau live
        $this->baseUrl = config('services.paypal.mode') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * Mendapatkan Access Token dari PayPal
     */
    private function getAccessToken()
    {
        $response = Http::asForm()->withBasicAuth($this->clientId, $this->secret)
            ->post("{$this->baseUrl}/v1/oauth2/token", [
                'grant_type' => 'client_credentials',
            ]);

        if ($response->failed()) {
            throw new Exception('Gagal mendapatkan PayPal Access Token: ' . $response->body());
        }

        return $response->json('access_token');
    }

    /**
     * Membuat Invoice / Order di PayPal
     */
    public function createInvoice($data)
    {
        $accessToken = $this->getAccessToken();

        // Menyusun payload sesuai standar PayPal Orders v2
        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => $data['external_id'],
                    'amount' => [
                        'currency_code' => $data['currency'],
                        'value' => number_format($data['amount'], 2, '.', ''), // Pastikan format 2 desimal, ex: 15.50
                    ],
                    'description' => 'Order ' . $data['order_id'] . ' from Solher'
                ]
            ],
            'application_context' => [
                'return_url' => $data['success_redirect_url'],
                'cancel_url' => $data['failure_redirect_url'],
                'shipping_preference' => 'NO_SHIPPING', // Karena alamat sudah diurus di website kita
                'user_action' => 'PAY_NOW',
                'brand_name' => 'Solher Beauty'
            ]
        ];

        $response = Http::withToken($accessToken)
            ->post("{$this->baseUrl}/v2/checkout/orders", $payload);

        if ($response->failed()) {
            Log::error('PayPal Order Creation Failed: ' . $response->body());
            throw new Exception('Gagal membuat pesanan PayPal.');
        }

        $responseData = $response->json();

        // Cari tautan "approve" untuk dilempar ke Vue.js
        $checkoutUrl = collect($responseData['links'])->firstWhere('rel', 'approve')['href'] ?? null;

        if (!$checkoutUrl) {
            throw new Exception('Tautan checkout PayPal tidak ditemukan.');
        }

        return $checkoutUrl;
    }
}
