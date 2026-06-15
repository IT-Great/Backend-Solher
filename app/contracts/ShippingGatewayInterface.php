<?php
namespace App\Contracts;

interface ShippingGatewayInterface
{
    public function calculateRates(array $origin, array $destination, array $items): array;
    public function createOrder(array $transactionData): array;
}
