<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use Auditable;

    protected $fillable = [
        'code', 'name', 'description', 'promo_config'
    ];

    // Otomatis men-decode JSON jadi array saat dibaca, dan meng-encode saat disimpan
    protected $casts = [
        'promo_config' => 'array',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
