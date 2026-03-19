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

    // Accessor untuk field 'image'
    public function getImageAttribute($value)
    {
        if (!$value) return null;

        // Cek jika nilainya sudah berupa URL (Legacy Data dari S3)
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return $value;
        }

        // Cek jika nilainya sudah ada URL VPS (Gara-gara kode lama yang salah)
        if (str_starts_with($value, 'http')) {
             // Bersihkan jika ada dobel HTTP
             $cleanValue = preg_replace('/^http[s]?:\/\/[^\/]+/', '', $value);
             return url($cleanValue);
        }

        // Jika path relatif biasa (/storage/...)
        return url($value);
    }

    // Accessor untuk field 'variant_images'
    public function getVariantImagesAttribute($value)
    {
        $images = json_decode($value, true);
        if (! $images) {
            return [];
        }

        return array_map(function($img) {
            if (filter_var($img, FILTER_VALIDATE_URL)) return $img;
            if (str_starts_with($img, 'http')) {
                $cleanImg = preg_replace('/^http[s]?:\/\/[^\/]+/', '', $img);
                return url($cleanImg);
            }
            return url($img);
        }, $images);
    }

    // Accessor untuk field 'variant_video'
    public function getVariantVideoAttribute($value)
    {
        if (!$value) return null;
        if (filter_var($value, FILTER_VALIDATE_URL)) return $value;
        if (str_starts_with($value, 'http')) {
            $cleanValue = preg_replace('/^http[s]?:\/\/[^\/]+/', '', $value);
            return url($cleanValue);
        }
        return url($value);
    }
}
