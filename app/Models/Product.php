<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'category_id',
        'code',
        'name',
        'image',
        'variant_images',
        'variant_video',
        'price',
        'discount_price',
        'stock',
        'weight',
        'length',
        'width',
        'height',
        'material',
        'color', 
        'description',
        'care',
        'design',
        'status',
    ];

    protected $casts = [
        'variant_images' => 'array',
        'price' => 'decimal:2',
        'discount_price' => 'decimal:2',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function transactionDetails(): HasMany
    {
        return $this->hasMany(TransactionDetail::class, 'product_id', 'id');
    }

    public function stocks()
    {
        return $this->hasMany(ProductStock::class)->orderBy('created_at', 'asc'); // ASC untuk FIFO
    }

    // ====================================================================
    // [PERBAIKAN] ACCESSOR ANTI-BADAI (AUTO-HEALING URLs)
    // ====================================================================

    // public function getImageAttribute($value)
    // {
    //     if (! $value) {
    //         return null;
    //     }

    //     // 1. Jika ini adalah URL asli dari AWS S3 masa lalu, biarkan saja
    //     if (str_contains($value, 'amazonaws.com')) {
    //         return $value;
    //     }

    //     // 2. Ekstrak HANYA path relatifnya, buang semua domain cacat bawaan database
    //     // Jika di database tersimpan 'http:31.97.60.207:8246/storage/products/1.png'
    //     // Maka kita paksa potong dan hanya ambil '/storage/products/1.png'
    //     if (str_contains($value, '/storage/')) {
    //         $pathOnly = substr($value, strpos($value, '/storage/'));
    //     } else {
    //         // Jaga-jaga jika tersimpan tanpa '/storage/'
    //         $pathOnly = str_starts_with($value, '/') ? $value : '/'.$value;
    //     }

    //     // 3. Rangkai kembali dengan url() bawaan Laravel
    //     return url($pathOnly);
    // }

    // public function getVariantImagesAttribute($value)
    // {
    //     $images = json_decode($value, true);
    //     if (! $images) {
    //         return [];
    //     }

    //     return array_map(function ($img) {
    //         if (str_contains($img, 'amazonaws.com')) {
    //             return $img;
    //         }

    //         if (str_contains($img, '/storage/')) {
    //             $pathOnly = substr($img, strpos($img, '/storage/'));
    //         } else {
    //             $pathOnly = str_starts_with($img, '/') ? $img : '/'.$img;
    //         }

    //         return url($pathOnly);
    //     }, $images);
    // }

    // public function getVariantVideoAttribute($value)
    // {
    //     if (! $value) {
    //         return null;
    //     }
    //     if (str_contains($value, 'amazonaws.com')) {
    //         return $value;
    //     }

    //     if (str_contains($value, '/storage/')) {
    //         $pathOnly = substr($value, strpos($value, '/storage/'));
    //     } else {
    //         $pathOnly = str_starts_with($value, '/') ? $value : '/'.$value;
    //     }

    //     return url($pathOnly);
    // }

    // ====================================================================
    // [PERBAIKAN] UNIVERSAL AUTO-HEALING URLs (ANTI FATAL ERROR)
    // ====================================================================

    // public function getImageAttribute($value)
    // {
    //     if (!$value) return null;

    //     // 1. Tangani kasus terburuk: Duplikasi URL (http://ip/https://domain...)
    //     if (preg_match('/^(http[s]?:\/\/[^\/]+)\/(http[s]?:\/\/.*)$/', $value, $matches)) {
    //         return $matches[2];
    //     }

    //     // 2. Jika sudah berupa URL penuh yang valid
    //     if (filter_var($value, FILTER_VALIDATE_URL) || str_starts_with($value, 'http')) {
    //         return $value;
    //     }

    //     // 3. Jika path relatif biasa
    //     $pathOnly = str_starts_with($value, '/') ? $value : '/' . $value;
    //     return url($pathOnly);
    // }

    // public function getVariantImagesAttribute($value)
    // {
    //     // [PERBAIKAN ERROR 500]: Cek apakah $value sudah array (efek $casts)
    //     // Jika sudah array, jangan di-json_decode lagi!
    //     $images = is_array($value) ? $value : json_decode($value, true);

    //     if (!$images || !is_array($images)) return [];

    //     return array_map(function($img) {
    //         if (!$img) return null;

    //         // Handle duplikasi URL
    //         if (preg_match('/^(http[s]?:\/\/[^\/]+)\/(http[s]?:\/\/.*)$/', $img, $matches)) {
    //             return $matches[2];
    //         }

    //         // Jika sudah berupa URL penuh
    //         if (filter_var($img, FILTER_VALIDATE_URL) || str_starts_with($img, 'http')) {
    //             return $img;
    //         }

    //         // Path relatif
    //         $pathOnly = str_starts_with($img, '/') ? $img : '/' . $img;
    //         return url($pathOnly);
    //     }, $images);
    // }

    // public function getVariantVideoAttribute($value)
    // {
    //     if (!$value) return null;

    //     // Handle duplikasi URL
    //     if (preg_match('/^(http[s]?:\/\/[^\/]+)\/(http[s]?:\/\/.*)$/', $value, $matches)) {
    //         return $matches[2];
    //     }

    //     // Jika sudah berupa URL penuh
    //     if (filter_var($value, FILTER_VALIDATE_URL) || str_starts_with($value, 'http')) {
    //         return $value;
    //     }

    //     // Path relatif
    //     $pathOnly = str_starts_with($value, '/') ? $value : '/' . $value;
    //     return url($pathOnly);
    // }

    // ====================================================================
    // [PERBAIKAN] UNIVERSAL AUTO-HEALING URLs (ANTI FATAL ERROR)
    // ====================================================================

    public function getImageAttribute($value)
    {
        // 1. Jika kosong sama sekali, kembalikan null
        if (empty($value) || $value === 'null') return null;

        // 2. Tangani kasus terburuk: Duplikasi URL (http://ip/https://domain...)
        if (preg_match('/^(http[s]?:\/\/[^\/]+)\/(http[s]?:\/\/.*)$/', $value, $matches)) {
            return $matches[2];
        }

        // 3. Jika sudah berupa URL penuh yang valid
        if (filter_var($value, FILTER_VALIDATE_URL) || str_starts_with($value, 'http')) {
            return $value;
        }

        // 4. Jika path relatif biasa
        $pathOnly = str_starts_with($value, '/') ? $value : '/' . $value;
        return url($pathOnly);
    }

    public function getVariantImagesAttribute($value)
    {
        // 1. Pengecekan Aman Pertama: Jika kosong langsung kembalikan array kosong
        if (empty($value) || $value === 'null') return [];

        // 2. Lakukan konversi dengan aman
        $images = is_array($value) ? $value : json_decode($value, true);

        // 3. Pengecekan Aman Kedua: Pastikan variabel $images benar-benar array
        if (!is_array($images)) return [];

        return array_map(function($img) {
            if (empty($img)) return null;

            // Handle duplikasi URL
            if (preg_match('/^(http[s]?:\/\/[^\/]+)\/(http[s]?:\/\/.*)$/', $img, $matches)) {
                return $matches[2];
            }

            // Jika sudah berupa URL penuh
            if (filter_var($img, FILTER_VALIDATE_URL) || str_starts_with($img, 'http')) {
                return $img;
            }

            // Path relatif
            $pathOnly = str_starts_with($img, '/') ? $img : '/' . $img;
            return url($pathOnly);
        }, $images);
    }

    public function getVariantVideoAttribute($value)
    {
        // 1. Pengecekan Aman: Jika kosong langsung kembalikan null
        if (empty($value) || $value === 'null') return null;

        // Handle duplikasi URL
        if (preg_match('/^(http[s]?:\/\/[^\/]+)\/(http[s]?:\/\/.*)$/', $value, $matches)) {
            return $matches[2];
        }

        // Jika sudah berupa URL penuh
        if (filter_var($value, FILTER_VALIDATE_URL) || str_starts_with($value, 'http')) {
            return $value;
        }

        // Path relatif
        $pathOnly = str_starts_with($value, '/') ? $value : '/' . $value;
        return url($pathOnly);
    }
}
