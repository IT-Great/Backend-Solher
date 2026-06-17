<?php

// namespace App\Services;

// use App\Contracts\ShippingGatewayInterface;

// class DHLService implements ShippingGatewayInterface
// {
//     public function calculateRates(array $origin, array $destination, array $items): array
//     {
//         // TODO: Hitung Ongkir Internasional dengan API DHL
//         return [
//             'status' => 'success',
//             'gateway' => 'dhl',
//             'rates' => [
//                 [
//                     'company' => 'dhl',
//                     'type' => 'express',
//                     'courier_name' => 'DHL Express Worldwide',
//                     'price' => 500000, // Dummy: Rp 500.000 ke Luar Negeri
//                     'duration' => '3-5 Days'
//                 ]
//             ]
//         ];
//     }

//     public function createOrder(array $transactionData): array
//     {
//         // TODO: Tembak API DHL untuk mencetak resi (Airway Bill)
//         return [
//             'id' => 'DHL-' . time(),
//             'tracking_number' => 'DHL-TRACK-PENDING',
//             'status' => 'pending'
//         ];
//     }
// }

namespace App\Services;

use App\Contracts\ShippingGatewayInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DHLService implements ShippingGatewayInterface
{
    protected $baseUrl;
    protected $apiKey;
    protected $apiSecret;
    protected $accountNumber;

    public function __construct()
    {
        // Secara default menggunakan URL Sandbox MyDHL API
        $this->baseUrl = config('services.dhl.base_url', 'https://express.api.dhl.com/mydhlapi/test');
        $this->apiKey = config('services.dhl.api_key');
        $this->apiSecret = config('services.dhl.api_secret');
        $this->accountNumber = config('services.dhl.account_number');
    }

    /**
     * Helper internal untuk memetakan nama negara ke Kode ISO 2 huruf (Wajib untuk DHL)
     */
    private function getCountryCode(string $countryName): string
    {
        $map = [
            'singapore' => 'SG',
            'malaysia' => 'MY',
            'australia' => 'AU',
            'united states' => 'US',
            'united kingdom' => 'GB',
            'germany' => 'DE',
            'france' => 'FR',
            'japan' => 'JP',
            // Tambahkan negara lain sesuai kebutuhan, atau gunakan library eksternal
        ];

        return $map[strtolower(trim($countryName))] ?? 'XX';
    }

    // public function calculateRates(array $origin, array $destination, array $items): array
    // {
    //     // DHL menggunakan Kilogram (Biteship menggunakan Gram), jadi kita bagi 1000
    //     $totalWeightGram = array_sum(array_column($items, 'weight'));
    //     $totalWeightKg = max(0.5, $totalWeightGram / 1000); // Minimal 0.5 KG

    //     $destinationCountryCode = $this->getCountryCode($destination['country'] ?? 'Singapore');
    //     $plannedDate = Carbon::now('Asia/Jakarta')->addDays(1)->format('Y-m-d\TH:i:s\G:i');

    //     $payload = [
    //         'customerDetails' => [
    //             'shipperDetails' => [
    //                 'postalCode' => $origin['postal_code'] ?? config('services.biteship.origin_postal_code'),
    //                 'cityName' => 'Surabaya',
    //                 'countryCode' => 'ID',
    //             ],
    //             'receiverDetails' => [
    //                 'postalCode' => $destination['postal_code'],
    //                 'cityName' => $destination['city'] ?? 'City', // DHL butuh nama kota
    //                 'countryCode' => $destinationCountryCode,
    //             ]
    //         ],
    //         'plannedShippingDateAndTime' => $plannedDate,
    //         'unitOfMeasurement' => 'metric',
    //         'isCustomsDeclarable' => true,
    //         'packages' => [
    //             [
    //                 'weight' => round($totalWeightKg, 2),
    //                 'dimensions' => [
    //                     'length' => 30, // Estimasi dimensi kotak Solher standar (cm)
    //                     'width' => 20,
    //                     'height' => 10,
    //                 ]
    //             ]
    //         ]
    //     ];

    //     try {
    //         // DHL menggunakan Basic Authentication (API Key sebagai Username, API Secret sebagai Password)
    //         $response = Http::withBasicAuth($this->apiKey, $this->apiSecret)
    //             ->post("{$this->baseUrl}/rates", $payload);

    //         if ($response->successful()) {
    //             $dhlRates = $response->json()['products'] ?? [];
    //             $formattedRates = [];

    //             foreach ($dhlRates as $rate) {
    //                 // Ambil harga total yang dibayarkan
    //                 $price = $rate['totalPrice'][0]['price'] ?? 0;

    //                 // Kita asumsikan harga yang dikembalikan DHL adalah USD atau mata uang tujuan.
    //                 // Dalam implementasi nyata, pastikan mengonversi rate DHL ini kembali ke Base IDR jika diperlukan,
    //                 // Atau simpan langsung sesuai mata uang yang ditarik.

    //                 $formattedRates[] = [
    //                     'company' => 'dhl',
    //                     'type' => strtolower(str_replace(' ', '_', $rate['productName'])),
    //                     'courier_name' => 'DHL ' . $rate['productName'],
    //                     'price' => (float) $price, // Nanti di frontend dirender dengan formatPrice()
    //                     'duration' => ($rate['deliveryCapabilities']['estimatedDeliveryDateAndTime'] ?? '3-5') . ' Days',
    //                 ];
    //             }

    //             return [
    //                 'status' => 'success',
    //                 'gateway' => 'dhl',
    //                 'rates' => $formattedRates
    //             ];
    //         }

    //         throw new \Exception('DHL Rate API Error: ' . $response->body());

    //     } catch (\Exception $e) {
    //         Log::error('DHL Calculate Rates Failed: ' . $e->getMessage());
    //         throw $e;
    //     }
    // }

    // public function createOrder(array $transactionData): array
    // {
    //     $destinationCountryCode = $this->getCountryCode($transactionData['destination']['country'] ?? 'Singapore');
    //     $plannedDate = Carbon::now('Asia/Jakarta')->addDays(1)->format('Y-m-d\TH:i:s\G:i');

    //     // Total berat dikonversi ke KG
    //     $totalWeightGram = array_sum(array_column($transactionData['items'], 'weight'));
    //     $totalWeightKg = max(0.5, $totalWeightGram / 1000);

    //     // Deklarasi Bea Cukai (Customs Line Items) wajib untuk pengiriman internasional
    //     $exportItems = [];
    //     $itemNumber = 1;
    //     foreach ($transactionData['items'] as $item) {
    //         $exportItems[] = [
    //             'number' => $itemNumber++,
    //             'description' => $item['name'],
    //             'price' => (float) $item['value'],
    //             'quantity' => [
    //                 'value' => (int) $item['quantity'],
    //                 'unitOfMeasurement' => 'PCS'
    //             ],
    //             'weight' => [
    //                 'netValue' => round(($item['weight'] / 1000), 2),
    //                 'grossValue' => round(($item['weight'] / 1000), 2)
    //             ]
    //         ];
    //     }

    //     $payload = [
    //         'plannedShippingDateAndTime' => $plannedDate,
    //         'pickup' => [
    //             'isRequested' => false, // Solher akan drop ke gerai DHL atau jadwalkan terpisah
    //         ],
    //         'productCode' => 'P', // 'P' biasanya untuk Express Worldwide (dokumen/paket non-dutiable/dutiable)
    //         'customerDetails' => [
    //             'shipperDetails' => [
    //                 'postalAddress' => [
    //                     'postalCode' => config('services.biteship.origin_postal_code', '60272'),
    //                     'cityName' => 'Surabaya',
    //                     'countryCode' => 'ID',
    //                     'addressLine1' => 'Jalan Wijaya Kusuma No.57',
    //                 ],
    //                 'contactInformation' => [
    //                     'email' => 'contact@solher.co.id',
    //                     'phone' => '08883888585',
    //                     'companyName' => 'Solher Store',
    //                     'fullName' => 'Solher Admin'
    //                 ]
    //             ],
    //             'receiverDetails' => [
    //                 'postalAddress' => [
    //                     'postalCode' => $transactionData['destination']['postal_code'],
    //                     'cityName' => $transactionData['destination']['city'] ?? 'City',
    //                     'countryCode' => $destinationCountryCode,
    //                     'addressLine1' => substr($transactionData['destination']['address'], 0, 45), // DHL punya limit karakter
    //                 ],
    //                 'contactInformation' => [
    //                     'email' => 'customer@email.com', // Opsional, disarankan ditarik dari data user
    //                     'phone' => substr($transactionData['destination']['phone'], 0, 25),
    //                     'companyName' => $transactionData['destination']['name'],
    //                     'fullName' => $transactionData['destination']['name']
    //                 ]
    //             ]
    //         ],
    //         'content' => [
    //             'packages' => [
    //                 [
    //                     'weight' => round($totalWeightKg, 2),
    //                     'dimensions' => [
    //                         'length' => 30,
    //                         'width' => 20,
    //                         'height' => 10,
    //                     ],
    //                     'description' => 'Solher Premium Bags',
    //                 ]
    //             ],
    //             'isCustomsDeclarable' => true,
    //             'description' => 'Fashion Accessories - Bags',
    //             'incoterm' => 'DAP', // Delivered at Place (Pajak ditanggung pembeli di negara tujuan)
    //             'unitOfMeasurement' => 'metric'
    //         ],
    //         'customsDeclaration' => [
    //             'lineItems' => $exportItems,
    //             'invoice' => [
    //                 'number' => 'INV-' . time(),
    //                 'date' => Carbon::now()->format('Y-m-d')
    //             ]
    //         ]
    //     ];

    //     try {
    //         $response = Http::withBasicAuth($this->apiKey, $this->apiSecret)
    //             ->post("{$this->baseUrl}/shipments", $payload);

    //         if ($response->successful()) {
    //             $data = $response->json();

    //             return [
    //                 'id' => $data['shipmentTrackingNumber'] ?? 'DHL-'.time(),
    //                 'tracking_number' => $data['shipmentTrackingNumber'] ?? 'Pending',
    //                 'status' => 'processing'
    //             ];
    //         }

    //         Log::error('DHL Shipment API Error: ' . $response->body());
    //         throw new \Exception('Failed to create DHL order');

    //     } catch (\Exception $e) {
    //         Log::error('DHL Create Order Failed: ' . $e->getMessage());
    //         throw $e;
    //     }
    // }

    public function calculateRates(array $origin, array $destination, array $items): array
    {
        // MOCK API: Karena belum memiliki Akun DHL, kita kembalikan data palsu
        // agar Frontend Vue.js bisa diselesaikan terlebih dahulu.

        return [
            'status' => 'success',
            'gateway' => 'dhl',
            'rates' => [
                [
                    'company' => 'dhl',
                    'type' => 'express_worldwide',
                    'courier_name' => 'DHL Express Worldwide',
                    'price' => 450000, // Dummy ongkir Rp 450.000
                    'duration' => '3-5 Days'
                ],
                [
                    'company' => 'dhl',
                    'type' => 'economy_select',
                    'courier_name' => 'DHL Economy Select',
                    'price' => 250000, // Dummy ongkir Rp 250.000
                    'duration' => '7-10 Days'
                ]
            ]
        ];
    }

    public function createOrder(array $transactionData): array
    {
        // MOCK API: Mengembalikan resi palsu
        return [
            'id' => 'DHL-MOCK-' . time(),
            'tracking_number' => 'DHL9988776655',
            'status' => 'processing'
        ];
    }
}
