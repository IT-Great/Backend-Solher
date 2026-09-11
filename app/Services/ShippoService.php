<?php

namespace App\Services;

use App\Contracts\ShippingGatewayInterface;

class ShippoService implements ShippingGatewayInterface
{
    /**
     * MOCK SERVICE: Memotong jalur API Shippo agar Frontend langsung
     * bisa memproses pengiriman internasional tanpa error birokrasi pabean.
     */
    public function calculateRates(array $origin, array $destination, array $parcel): array
    {
        // Langsung kembalikan data fiktif yang formatnya 100% selaras
        // dengan apa yang diharapkan oleh Frontend Vue.js Anda.
        return [
            'success' => true,
            'message' => 'Rates retrieved successfully (Mock API)',
            'data'    => [
                [
                    'id'           => 'mock_dhl_express_001',
                    'provider'     => 'DHL Express',
                    'service_name' => 'Express Worldwide (B2C)',
                    'price'        => 35.50, // Harga dalam USD
                    'currency'     => 'USD',
                    'etd'          => '3-5 Days',
                    'raw_rate_id'  => 'mock_dhl_express_001'
                ],
                [
                    'id'           => 'mock_fedex_intl_002',
                    'provider'     => 'FedEx',
                    'service_name' => 'International Economy',
                    'price'        => 28.00, // Harga dalam USD
                    'currency'     => 'USD',
                    'etd'          => '5-7 Days',
                    'raw_rate_id'  => 'mock_fedex_intl_002'
                ],
                [
                    'id'           => 'mock_ups_saver_003',
                    'provider'     => 'UPS',
                    'service_name' => 'Worldwide Saver',
                    'price'        => 42.00,
                    'currency'     => 'USD',
                    'etd'          => '2-4 Days',
                    'raw_rate_id'  => 'mock_ups_saver_003'
                ]
            ]
        ];
    }

    public function createOrder(array $transactionData): array
    {
        return [
            'success' => true,
            'message' => 'Order created successfully (Mock API)',
            'data'    => [
                'tracking_number' => 'DHL' . rand(100000000, 999999999),
                'waybill_id'      => 'shp_mock_' . uniqid(),
            ]
        ];
    }
}
