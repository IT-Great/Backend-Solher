<?php

// namespace App\Services;

// use Carbon\Carbon;
// use Illuminate\Support\Facades\Http;

// class BiteshipService
// {
//     protected $baseUrl = 'https://api.biteship.com/v1';

//     protected $apiKey;

//     public function __construct()
//     {
//         $this->apiKey = config('services.biteship.api_key');
//     }

//     // // 1. CEK ONGKIR
//     // public function getRates($address, $weight = 1000)
//     // {
//     //     $payload = [
//     //         'origin_postal_code' => config('services.biteship.origin_postal_code'),
//     //         'destination_postal_code' => $address->postal_code,

//     //         // [PERBAIKAN 1] API Rates WAJIB menggunakan _coordinate object agar jarak akurat
//     //         // 'origin_coordinate' => [
//     //         //     'latitude' => -7.25706,
//     //         //     'longitude' => 112.74549
//     //         // ],
//     //         // 'destination_coordinate' => [
//     //         //     'latitude' => floatval($address->latitude),
//     //         //     'longitude' => floatval($address->longitude),
//     //         // ],

//     //         'origin_latitude' => -7.25706,
//     //         'origin_longitude' => 112.74549,

//     //         // [PERBAIKAN PENTING] Gunakan floatval() agar Biteship mengukur Jarak (Radius KM) secara presisi!
//     //         // Ini mencegah Grab Same Day muncul jika jaraknya > 40 KM.
//     //         'destination_latitude' => floatval($address->latitude),
//     //         'destination_longitude' => floatval($address->longitude),

//     //         'couriers' => 'jne,sicepat,jnt,anteraja,grab,gojek,paxel,ninja',
//     //         'items' => [
//     //             [
//     //                 'name' => 'Cart Items',
//     //                 'value' => 10000,
//     //                 'quantity' => $weight / 1000, // Konversi total berat kembali ke quantity
//     //                 'weight' => 1000, // Berat standar per item
//     //             ],
//     //         ],
//     //     ];

//     //     $response = Http::withHeaders([
//     //         'Authorization' => $this->apiKey,
//     //         'Content-Type' => 'application/json',
//     //     ])->post("{$this->baseUrl}/rates/couriers", $payload);

//     //     return $response->json();
//     // }

//     // // 2. CREATE ORDER (Dijalankan hanya saat status 'processing')
//     // public function createOrder($transaction)
//     // {
//     //     $transaction->loadMissing(['address', 'user', 'details']);
//     //     date_default_timezone_set('Asia/Jakarta');

//     //     $totalQuantity = $transaction->details->sum('quantity');

//     //     $payload = [
//     //         'origin_contact_name' => 'Solher Store',
//     //         'origin_contact_phone' => '08883888585',
//     //         'origin_address' => 'Jalan Kecilung N0. 8A, Kota Surabaya, Jawa Timur 60275, Indonesia',
//     //         'origin_postal_code' => config('services.biteship.origin_postal_code'),

//     //         'origin_coordinate' => [
//     //             'latitude' => -7.25706,
//     //             'longitude' => 112.74549,
//     //         ],

//     //         'destination_postal_code' => $transaction->address->postal_code,
//     //         'destination_contact_name' => trim($transaction->address->first_name_address.' '.$transaction->address->last_name_address),
//     //         'destination_contact_phone' => $transaction->user->phone ?? '08123456789',
//     //         'destination_address' => $transaction->address->address_location,

//     //         'destination_coordinate' => [
//     //             'latitude' => floatval($transaction->address->latitude),
//     //             'longitude' => floatval($transaction->address->longitude),
//     //         ],

//     //         'courier_company' => $transaction->courier_company,
//     //         'courier_type' => $transaction->courier_type,
//     //         'delivery_type' => $transaction->delivery_type ?? 'now',

//     //         'items' => [
//     //             [
//     //                 'name' => 'Solher Products',
//     //                 'value' => (int) $transaction->total_amount,
//     //                 'quantity' => (int) $totalQuantity,
//     //                 // [PERBAIKAN 2] Parameter weight adalah berat PER 1 ITEM! Jangan dikalikan total quantity.
//     //                 'weight' => 1000,
//     //             ],
//     //         ],
//     //         // 'status' => 'confirmed'
//     //     ];

//     //     // [PERBAIKAN 3] Hanya kirim jadwal jika user memilih 'scheduled' atau 'later'.
//     //     // Jika 'now', biarkan logistik yang menentukan jam pickup secepatnya.
//     //     if ($transaction->delivery_type === 'scheduled') {
//     //         $payload['delivery_date'] = $transaction->delivery_date ?? date('Y-m-d');
//     //         $payload['delivery_time'] = Carbon::parse($transaction->delivery_time)->format('H:i');
//     //     }

//     //     \Log::channel('stderr')->info('BITESHIP FINAL PAYLOAD:', $payload);

//     //     $response = Http::withHeaders([
//     //         'Authorization' => $this->apiKey,
//     //         'Content-Type' => 'application/json',
//     //     ])->post("{$this->baseUrl}/orders", $payload);

//     //     $data = $response->json();

//     //     if (isset($data['success']) && $data['success'] === false) {
//     //         $errorMsg = json_encode($data);
//     //         \Log::channel('stderr')->error('BITESHIP REJECTED ORDER: '.$errorMsg);
//     //     }

//     //     return $data;
//     // }

//     // 1. CEK ONGKIR
//     public function getRates($address, $weight = 1000)
//     {
//         $payload = [
//             'origin_postal_code' => config('services.biteship.origin_postal_code'),
//             'destination_postal_code' => $address->postal_code,
//             // 'origin_latitude' => -7.25706,
//             // 'origin_longitude' => 112.74549,

//             'origin_latitude' => -7.25653,
//             'origin_longitude' => 112.74877,

//             'destination_latitude' => floatval($address->latitude),
//             'destination_longitude' => floatval($address->longitude),
//             'couriers' => 'jne,sicepat,jnt,anteraja,grab,gojek,paxel,ninja',

//             'items' => [
//                 [
//                     'name' => 'Cart Items',
//                     'value' => 10000, // Dummy value untuk kalkulasi rate
//                     'quantity' => 1,  // Cukup 1 karena $weight sudah mewakili TOTAL BERAT seluruh keranjang
//                     'weight' => $weight,
//                 ],
//             ],
//         ];

//         $response = Http::withHeaders([
//             'Authorization' => $this->apiKey,
//             'Content-Type' => 'application/json',
//         ])->post("{$this->baseUrl}/rates/couriers", $payload);

//         return $response->json();
//     }

//     // 2. CREATE ORDER (Dijalankan hanya saat status 'processing')
//     public function createOrder($transaction)
//     {
//         // [PERBAIKAN 1] Pastikan relasi produk termuat agar kita bisa membaca beratnya
//         $transaction->loadMissing(['address', 'user', 'details.product']);
//         date_default_timezone_set('Asia/Jakarta');

//         // [PERBAIKAN 2] Mapping setiap produk ke dalam format Biteship Items
//         $biteshipItems = [];
//         foreach ($transaction->details as $detail) {
//             $itemWeight = $detail->product->weight ?? 1000; // Fallback 1000 gram

//             $biteshipItems[] = [
//                 'name' => $detail->product->name,
//                 'value' => (int) $detail->price,
//                 'quantity' => (int) $detail->quantity,
//                 'weight' => (int) $itemWeight, // BERAT AKTUAL DARI DB
//             ];
//         }

//         $payload = [
//             'origin_contact_name' => 'Solher Store',
//             'origin_contact_phone' => '08883888585',
//             // 'origin_address' => 'Jalan Kecilung N0. 8A, Kota Surabaya, Jawa Timur 60275, Indonesia',
//             'origin_address' => 'Jalan Wijaya Kusuma No.57, Kota Surabaya, Jawa Timur 60272, Indonesia',
//             'origin_postal_code' => config('services.biteship.origin_postal_code'),

//             'origin_coordinate' => [
//                 // 'latitude' => -7.25706,
//                 // 'longitude' => 112.74549,
//                 'latitude' => -7.25653,
//                 'longitude' => 112.74877,
//             ],

//             'destination_postal_code' => $transaction->address->postal_code,
//             'destination_contact_name' => trim($transaction->address->first_name_address.' '.$transaction->address->last_name_address),
//             'destination_contact_phone' => $transaction->user->phone ?? '08123456789',
//             'destination_address' => $transaction->address->address_location,

//             'destination_coordinate' => [
//                 'latitude' => floatval($transaction->address->latitude),
//                 'longitude' => floatval($transaction->address->longitude),
//             ],

//             'courier_company' => $transaction->courier_company,
//             'courier_type' => $transaction->courier_type,
//             'delivery_type' => $transaction->delivery_type ?? 'now',

//             // [PERBAIKAN 3] Masukkan array item yang sudah kita mapping beserta berat aslinya
//             'items' => $biteshipItems,
//         ];

//         if ($transaction->delivery_type === 'scheduled') {
//             $payload['delivery_date'] = $transaction->delivery_date ?? date('Y-m-d');
//             $payload['delivery_time'] = Carbon::parse($transaction->delivery_time)->format('H:i');
//         }

//         \Log::channel('stderr')->info('BITESHIP FINAL PAYLOAD:', $payload);

//         $response = Http::timeout(10)->withHeaders([
//             'Authorization' => $this->apiKey,
//             'Content-Type' => 'application/json',
//         ])->post("{$this->baseUrl}/orders", $payload);

//         $data = $response->json();

//         if (isset($data['success']) && $data['success'] === false) {
//             $errorMsg = json_encode($data);
//             \Log::channel('stderr')->error('BITESHIP REJECTED ORDER: '.$errorMsg);
//         }

//         return $data;
//     }

//     public function getTracking($waybillId, $courierCompany)
//     {
//         $response = Http::withHeaders([
//             'Authorization' => $this->apiKey,
//         ])->get("{$this->baseUrl}/trackings/{$waybillId}/couriers/{$courierCompany}");

//         return $response->json();
//     }
// }

namespace App\Services;

use App\Contracts\ShippingGatewayInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class BiteshipService implements ShippingGatewayInterface
{
    protected $baseUrl = 'https://api.biteship.com/v1';
    protected $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.biteship.api_key');
    }

    /**
     * Sesuai Kontrak: calculateRates
     */
    public function calculateRates(array $origin, array $destination, array $items): array
    {
        // Hitung total berat dari array items
        $totalWeight = array_sum(array_column($items, 'weight'));
        // Fallback berat minimal agar Biteship tidak error
        if ($totalWeight <= 0) $totalWeight = 1000;

        $payload = [
            'origin_postal_code' => $origin['postal_code'] ?? config('services.biteship.origin_postal_code'),
            'destination_postal_code' => $destination['postal_code'],
            'origin_latitude' => $origin['latitude'] ?? -7.25653,
            'origin_longitude' => $origin['longitude'] ?? 112.74877,
            'destination_latitude' => floatval($destination['latitude']),
            'destination_longitude' => floatval($destination['longitude']),
            'couriers' => 'jne,sicepat,jnt,anteraja,grab,gojek,paxel,ninja',
            'items' => [
                [
                    'name' => 'Cart Items',
                    'value' => 10000,
                    'quantity' => 1,
                    'weight' => $totalWeight,
                ],
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/rates/couriers", $payload);

        // Standarisasi Response agar DHL dan Biteship punya output kembar untuk Vue.js
        if ($response->successful() && isset($response->json()['pricing'])) {
            return [
                'status' => 'success',
                'gateway' => 'biteship',
                'rates' => $response->json()['pricing'] // Mengirim data ongkir ke frontend
            ];
        }

        throw new \Exception('Biteship API Error: ' . $response->body());
    }

    /**
     * Sesuai Kontrak: createOrder
     */
    public function createOrder(array $transactionData): array
    {
        date_default_timezone_set('Asia/Jakarta');

        $payload = [
            'origin_contact_name' => 'Solher Store',
            'origin_contact_phone' => '08883888585',
            'origin_address' => 'Jalan Wijaya Kusuma No.57, Kota Surabaya, Jawa Timur 60272, Indonesia',
            'origin_postal_code' => config('services.biteship.origin_postal_code'),
            'origin_coordinate' => [
                'latitude' => -7.25653,
                'longitude' => 112.74877,
            ],
            'destination_contact_name' => $transactionData['destination']['name'],
            'destination_contact_phone' => $transactionData['destination']['phone'],
            'destination_address' => $transactionData['destination']['address'],
            'destination_postal_code' => $transactionData['destination']['postal_code'],
            'destination_coordinate' => [
                'latitude' => floatval($transactionData['destination']['latitude']),
                'longitude' => floatval($transactionData['destination']['longitude']),
            ],
            'courier_company' => $transactionData['courier_company'],
            'courier_type' => $transactionData['courier_type'],
            'delivery_type' => $transactionData['delivery_type'] ?? 'now',
            'items' => $transactionData['items'],
        ];

        if (($transactionData['delivery_type'] ?? '') === 'scheduled') {
            $payload['delivery_date'] = $transactionData['delivery_date'] ?? date('Y-m-d');
            $payload['delivery_time'] = Carbon::parse($transactionData['delivery_time'])->format('H:i');
        }

        $response = Http::timeout(10)->withHeaders([
            'Authorization' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/orders", $payload);

        $data = $response->json();

        if (isset($data['success']) && $data['success'] === false) {
            \Log::channel('stderr')->error('BITESHIP REJECTED ORDER: ' . json_encode($data));
            throw new \Exception('Failed to create Biteship order');
        }

        // Return Data yang diseragamkan
        return [
            'id' => $data['id'],
            'tracking_number' => $data['courier']['waybill_id'] ?? 'Pending',
            'status' => strtolower($data['status'] ?? 'pending')
        ];
    }
}
