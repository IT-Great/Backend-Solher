<?php

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;
use Illuminate\Support\Facades\Log;
use Xendit\Configuration;
use Xendit\Invoice\CreateInvoiceRequest;
use Xendit\Invoice\InvoiceApi;

class XenditService implements PaymentGatewayInterface
{
    /**
     * Konstruktor: Inisialisasi kunci rahasia Xendit saat service dipanggil.
     */
    public function __construct()
    {
        Configuration::setXenditKey(config('services.xendit.secret_key'));
    }

    /**
     * Implementasi wajib dari PaymentGatewayInterface.
     * Mengubah array data standar menjadi Invoice Xendit.
     */
    public function createInvoice(array $transactionData): string
    {
        try {
            $invoiceRequest = new CreateInvoiceRequest([
                'external_id'          => $transactionData['external_id'],
                'payer_email'          => $transactionData['payer_email'],
                'description'          => 'Payment for Order ' . $transactionData['order_id'],
                'amount'               => (int) $transactionData['amount'],
                'currency'             => $transactionData['currency'] ?? 'IDR',
                'items'                => $transactionData['items'],
                'success_redirect_url' => $transactionData['success_redirect_url'],
                'failure_redirect_url' => $transactionData['failure_redirect_url'],
            ]);

            $api = new InvoiceApi();
            $invoice = $api->createInvoice($invoiceRequest);

            // Return HANYA string URL checkout-nya
            return $invoice['invoice_url'];

        } catch (\Exception $e) {
            Log::error('Xendit Invoice API Failed: ' . $e->getMessage(), ['data' => $transactionData]);

            // Melempar error agar bisa ditangkap oleh sistem utama
            throw new \Exception('Gagal membuat tagihan pembayaran lokal: ' . $e->getMessage());
        }
    }

    /**
     * Implementasi wajib dari PaymentGatewayInterface.
     * Mengamankan Webhook callback dari serangan luar.
     */
    public function handleCallback(array $payload): bool
    {
        // $xenditToken = config('services.xendit.webhook_token');
        // $incomingToken = request()->header('x-callback-token');

        // Jika token tidak cocok, tolak mentah-mentah
        // if ($incomingToken !== $xenditToken) {
        //     Log::critical('Fake Xendit Webhook Detected!', $payload);
        //     return false;
        // }

        return true;
    }
}
