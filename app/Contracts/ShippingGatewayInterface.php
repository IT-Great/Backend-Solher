<?php

namespace App\Contracts;

interface ShippingGatewayInterface
{
    /**
     * Dapatkan daftar tarif pengiriman (Shipping Rates).
     * 
     * @param array $payload
     * @return mixed
     */
    public function getRates(array $payload);

    /**
     * Buat pesanan pengiriman ke kurir (Create Order/Waybill).
     * 
     * @param array $payload
     * @return mixed
     */
    public function createOrder(array $payload);

    /**
     * Lacak resi pengiriman (Track Order).
     * 
     * @param string $trackingNumber
     * @param string $courier
     * @return mixed
     */
    public function trackOrder(string $trackingNumber, string $courier);
}