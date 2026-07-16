<?php

// namespace App\Http\Resources;

// use Illuminate\Http\Request;
// use Illuminate\Http\Resources\Json\JsonResource;

// class CategoryResource extends JsonResource
// {
//     /**
//      * Transform the resource into an array.
//      *
//      * @return array<string, mixed>
//      */
//     public function toArray(Request $request): array
//     {
//         return [
//             'id' => $this->id,
//             'category_code' => $this->code,
//             'category_name' => $this->name,
//             'meta' => [
//                 'description' => $this->description ?? 'No description provided.',
//                 'slug' => str($this->name)->slug(),
//             ],
//             // [BARU] Load products hanya jika dipanggil dengan `with('products')`
//             'products' => $this->whenLoaded('products'),
//             'timestamps' => [
//                 'created_at' => $this->created_at?->toDateTimeString(),
//             ]
//         ];
//     }
// }

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Mengecek apakah promo sedang aktif detik ini
        $now = now();
        $isPromoActive = $this->bundle_qty && $this->bundle_price &&
                         $this->bundle_start_date && $this->bundle_end_date &&
                         $now->between($this->bundle_start_date, $this->bundle_end_date);

        return [
            'id' => $this->id,
            'category_code' => $this->code,
            'category_name' => $this->name,
            'meta' => [
                'description' => $this->description ?? 'No description provided.',
                'slug' => str($this->name)->slug(),
            ],
            // [BARU] Informasi Bundle Promo dikirimkan dalam 1 objek rapi
            'bundle_promo' => [
                'is_active' => $isPromoActive,
                'qty' => $this->bundle_qty,
                'price' => $this->bundle_price,
                'start_date' => $this->bundle_start_date?->format('Y-m-d\TH:i'), // Format untuk input datetime HTML
                'end_date' => $this->bundle_end_date?->format('Y-m-d\TH:i'),
            ],
            'products' => $this->whenLoaded('products'),
            'timestamps' => [
                'created_at' => $this->created_at?->toDateTimeString(),
            ]
        ];
    }
}
