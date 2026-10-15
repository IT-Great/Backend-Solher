<?php

namespace App\Contracts;

interface ShippingGatewayInterface
{
    /**
     * Dapatkan daftar tarif pengiriman (Shipping Rates).
     *
     * @param array $origin
     * @param array $destination
     * @param array $parcel
     * @return array
     */
    public function calculateRates(array $origin, array $destination, array $parcel): array;

    /**
     * Buat pesanan pengiriman ke kurir (Create Order/Waybill).
     *
     * @param array $transactionData
     * @return array
     */
    public function createOrder(array $transactionData): array;

    /**
     * Dapatkan status lacak resi dari kurir.
     * (Ditambahkan sesuai struktur BiteshipService yang Anda miliki)
     *
     * @param string $orderId
     * @return mixed
     */
    public function getOrderTracking(string $orderId);
}