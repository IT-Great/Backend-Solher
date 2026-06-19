<?php

// namespace App\Services;

// use Illuminate\Support\Facades\Http;
// use Illuminate\Support\Facades\Log;
// use Exception;
// use App\Contracts\ShippingGatewayInterface;

// class ShippoService implements ShippingGatewayInterface
// {
//     protected string $apiKey;
//     protected string $baseUrl = 'https://api.goshippo.com';

//     public function __construct()
//     {
//         // Mengambil token dari .env
//         $this->apiKey = config('services.shippo.key', env('SHIPPO_API_KEY'));
//     }

//     /**
//      * Menghitung ongkos kirim internasional via Shippo
//      * * @param array $origin Alamat asal (Surabaya)
//      * @param array $destination Alamat tujuan luar negeri
//      * @param array $parcel Detail dimensi dan berat barang
//      * @return array
//      */
//     public function calculateRates(array $origin, array $destination, array $parcel): array
//     {
//         Log::info('Data Destination dari Vue:', $destination);
//         try {
//             // Shippo mewajibkan otentikasi dengan format header: "ShippoToken <key>"
//             $response = Http::withHeaders([
//                 'Authorization' => 'ShippoToken ' . $this->apiKey,
//                 'Content-Type' => 'application/json',
//             ])->post("{$this->baseUrl}/shipments/", [
//                 'address_from' => [
//                     'name'    => $origin['name'] ?? 'Solher Warehouse',
//                     'street1' => $origin['street1'] ?? '57 Jalan Wijaya Kusuma',
//                     'city'    => $origin['city'] ?? 'Surabaya',
//                     'state'   => $origin['state'] ?? 'Jawa Timur',
//                     'zip'     => $origin['zip'] ?? '60272',
//                     'country' => 'ID', // Indonesia
//                     'phone'   => $origin['phone'] ?? '08973424888',
//                 ],
//                 'address_to' => [
//                     'name'    => $destination['name'],
//                     // 'street1' => $destination['street1'],
//                     'street1' => $destination['street1'] ?? $destination['address'] ?? 'Alamat belum lengkap',
//                     'city'    => $destination['city'],
//                     'state'   => $destination['state'], // Sangat penting untuk US/Canada
//                     'zip'     => $destination['zip'],
//                     // 'country' => $destination['country_code'], // Misal: 'SG', 'US', 'AU'
//                     'country' => $destination['country_code'] ?? $destination['country'] ?? '',
//                     'phone'   => $destination['phone'],
//                 ],
//                 'parcels' => [[
//                     'length'        => $parcel['length'] ?? '10',
//                     'width'         => $parcel['width'] ?? '10',
//                     'height'        => $parcel['height'] ?? '10',
//                     'distance_unit' => 'cm',
//                     'weight'        => $parcel['weight'] ?? '0.5', // Dalam kg atau oz
//                     'mass_unit'     => 'kg',
//                 ]],
//                 'async' => false // Set false agar API langsung mengembalikan rate saat itu juga
//             ]);

//             if ($response->failed()) {
//                 Log::error('Shippo API Error: ' . $response->body());
//                 throw new Exception('Gagal mendapatkan estimasi pengiriman dari Shippo.');
//             }

//             return $this->formatShippoRates($response->json());

//         } catch (Exception $e) {
//             Log::error('Shippo Service Exception: ' . $e->getMessage());
//             return [
//                 'success' => false,
//                 'message' => $e->getMessage(),
//                 'data'    => []
//             ];
//         }
//     }

//     /**
//      * Memformat response dari Shippo agar serupa dengan format standard (Biteship)
//      * sehingga Frontend Vue.js tidak perlu banyak beradaptasi.
//      */
//     protected function formatShippoRates(array $responseData): array
//     {
//         $formattedRates = [];
//         $rates = $responseData['rates'] ?? [];

//         foreach ($rates as $rate) {
//             $formattedRates[] = [
//                 'id'           => $rate['object_id'],
//                 'provider'     => $rate['provider'], // Contoh: DHL Express, FedEx, UPS
//                 'service_name' => $rate['servicelevel']['name'], // Contoh: Express Worldwide
//                 'price'        => (float) $rate['amount'], // Nilai asli (biasanya USD)
//                 'currency'     => $rate['currency'], // USD, SGD, dll.
//                 'etd'          => $rate['estimated_days'] ? $rate['estimated_days'] . ' Days' : 'N/A',
//                 'raw_rate_id'  => $rate['object_id'] // Disimpan untuk keperluan cetak label nanti
//             ];
//         }

//         return [
//             'success' => true,
//             'message' => 'Rates retrieved successfully',
//             'data'    => $formattedRates
//         ];
//     }
//     /**
//      * Membuat pesanan pengiriman / mencetak label resi via Shippo
//      * (Akan dieksekusi saat pembeli sudah sukses membayar)
//      * * @param array $transactionData
//      * @return array
//      */
//     public function createOrder(array $transactionData): array
//     {
//         try {
//             // Nanti logika untuk membeli label (POST ke /transactions) diletakkan di sini.
//             // Membutuhkan ID rate yang dipilih pelanggan.

//             return [
//                 'success' => true,
//                 'message' => 'Order created successfully (Shippo Placeholder)',
//                 'data'    => [
//                     'tracking_number' => 'SHIPPO_TEST_TRACKING_123',
//                     'waybill_id'      => 'shp_dummy_123',
//                     // Label URL dll bisa ditambahkan nanti saat masuk ke tahap pemrosesan pesanan
//                 ]
//             ];

//         } catch (Exception $e) {
//             Log::error('Shippo Service Create Order Exception: ' . $e->getMessage());
//             return [
//                 'success' => false,
//                 'message' => $e->getMessage(),
//                 'data'    => []
//             ];
//         }
//     }
// }

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;
use App\Contracts\ShippingGatewayInterface;

class ShippoService implements ShippingGatewayInterface
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.goshippo.com';

    public function __construct()
    {
        $this->apiKey = config('services.shippo.key', env('SHIPPO_API_KEY'));
    }

    public function calculateRates(array $origin, array $destination, array $parcel): array
    {
        Log::info('Data Destination Shippo Final:', $destination);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'ShippoToken ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/shipments/", [
                'address_from' => [
                    'name'    => $origin['name'] ?? 'Solher Warehouse',
                    'street1' => $origin['street1'] ?? '57 Jalan Wijaya Kusuma',
                    'city'    => $origin['city'] ?? 'Surabaya',
                    'state'   => $origin['state'] ?? 'Jawa Timur',
                    'zip'     => $origin['postal_code'] ?? '60272', // Disesuaikan dengan controller
                    'country' => 'ID',
                    'phone'   => $origin['phone'] ?? '08973424888',
                ],
                'address_to' => [
                    'name'    => $destination['name'] ?? 'Customer Solher',
                    'street1' => $destination['address'] ?? 'Alamat belum lengkap',
                    'city'    => $destination['city'] ?? 'Unknown City',
                    // [KUNCI PERBAIKAN]: Membaca 'province' dari controller, bukan 'state'
                    'state'   => $destination['province'] ?? 'Unknown State',
                    'zip'     => $destination['postal_code'] ?? '00000',
                    'country' => $destination['country_code'] ?? 'US',
                    'phone'   => $destination['phone'] ?? '',
                ],
                'parcels' => [[
                    'length'        => $parcel['length'] ?? '20',
                    'width'         => $parcel['width'] ?? '20',
                    'height'        => $parcel['height'] ?? '10',
                    'distance_unit' => 'cm',
                    'weight'        => $parcel['weight'] ?? '0.5',
                    'mass_unit'     => 'kg',
                ]],

                // [BARU] WAJIB UNTUK PENGIRIMAN INTERNASIONAL
                'customs_declaration' => [
                    'contents_type' => 'MERCHANDISE', // Jenis barang: Dagangan
                    'non_delivery_option' => 'RETURN', // Jika gagal kirim, kembalikan
                    'certify' => true,
                    'certifier' => 'Solher Admin',
                    'items' => [
                        [
                            'description' => 'Solher Fashion Bag', // Deskripsi barang
                            'quantity' => 1,
                            'net_weight' => $parcel['weight'] ?? '0.5',
                            'mass_unit' => 'kg',
                            'value_amount' => '15.00', // Nilai barang (wajib diisi untuk asuransi/pajak)
                            'value_currency' => 'USD',
                            'origin_country' => 'ID' // Negara asal pembuat barang
                        ]
                    ]
                ],

                'async' => false
            ]);

            if ($response->failed()) {
                Log::error('Shippo API Error: ' . $response->body());
                throw new Exception('Gagal mendapatkan estimasi pengiriman dari Shippo.');
            }

            return $this->formatShippoRates($response->json());

        } catch (Exception $e) {
            Log::error('Shippo Service Exception: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data'    => []
            ];
        }
    }

    protected function formatShippoRates(array $responseData): array
    {
        $formattedRates = [];
        $rates = $responseData['rates'] ?? [];

        foreach ($rates as $rate) {
            $formattedRates[] = [
                'id'           => $rate['object_id'],
                'provider'     => $rate['provider'],
                'service_name' => $rate['servicelevel']['name'],
                'price'        => (float) $rate['amount'],
                'currency'     => $rate['currency'],
                'etd'          => $rate['estimated_days'] ? $rate['estimated_days'] . ' Days' : 'N/A',
                'raw_rate_id'  => $rate['object_id']
            ];
        }

        return [
            'success' => true,
            'message' => 'Rates retrieved successfully',
            'data'    => $formattedRates
        ];
    }

    public function createOrder(array $transactionData): array
    {
        try {
            return [
                'success' => true,
                'message' => 'Order created successfully (Shippo Placeholder)',
                'data'    => [
                    'tracking_number' => 'SHIPPO_TEST_TRACKING_123',
                    'waybill_id'      => 'shp_dummy_123',
                ]
            ];
        } catch (Exception $e) {
            Log::error('Shippo Service Create Order Exception: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data'    => []
            ];
        }
    }
}
