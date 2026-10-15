<?php

namespace App\Contracts;

interface PaymentGatewayInterface
{
    /**
     * Membuat tagihan/invoice pembayaran ke pihak Gateway.
     * Biasanya akan mengembalikan URL pembayaran (Checkout URL).
     * 
     * @param array $payload (Berisi detail transaksi, item, dan pelanggan)
     * @return mixed
     */
    public function createInvoice(array $payload);

    /**
     * Mendapatkan status pembayaran terbaru langsung dari server Gateway.
     * 
     * @param string $invoiceId
     * @return mixed
     */
    public function getPaymentStatus(string $invoiceId);

    /**
     * Memproses pengembalian dana (Refund) ke metode pembayaran pelanggan.
     * 
     * @param string $invoiceId
     * @param int|float $amount
     * @param string $reason
     * @return mixed
     */
    public function processRefund(string $invoiceId, $amount, string $reason);
}