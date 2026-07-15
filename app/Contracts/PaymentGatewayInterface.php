<?php
namespace App\Contracts;

interface PaymentGatewayInterface
{
    public function createInvoice(array $transactionData): string; // Harus me-return URL Checkout
    public function handleCallback(array $payload): bool;
}
