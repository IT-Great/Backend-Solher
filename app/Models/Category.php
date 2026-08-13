<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    // use Auditable;


    // protected $fillable = ['code', 'name', 'description'];

    // public function products(): HasMany
    // {
    //     return $this->hasMany(Product::class);
    // }

    use Auditable;

    protected $fillable = [
        'code', 'name', 'description', 'subcategories',
        'bundle_qty', 'bundle_price', 'bundle_start_date', 'bundle_end_date'
    ];

    // Mengubah string datetime menjadi objek Carbon secara otomatis
    protected $casts = [
        'subcategories' => 'array',
        'bundle_start_date' => 'datetime',
        'bundle_end_date' => 'datetime',
        'bundle_price' => 'array',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
