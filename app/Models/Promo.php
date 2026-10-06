<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Promo extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'title',
        'description',
        'discount_type',
        'discount_value',
        'max_discount',
        'min_purchase',
        'target_category_id',
        'target_product_id',
        'quota',
        'used_count',
        'max_usage_per_user',
        'is_member_only',
        'is_first_order_only',
        'is_active',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'min_purchase' => 'decimal:2',
        'is_member_only' => 'boolean',
        'is_first_order_only' => 'boolean',
        'is_active' => 'boolean',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    public function targetCategory()
    {
        return $this->belongsTo(Category::class, 'target_category_id');
    }

    public function targetProduct()
    {
        return $this->belongsTo(Product::class, 'target_product_id');
    }

    // Fungsi helper untuk mengecek validitas waktu promo
    public function isValidNow()
    {
        if (!$this->is_active) return false;

        $now = now();
        if ($this->start_date && $now->lessThan($this->start_date)) return false;
        if ($this->end_date && $now->greaterThan($this->end_date)) return false;
        if ($this->quota !== null && $this->used_count >= $this->quota) return false;

        return true;
    }
}
