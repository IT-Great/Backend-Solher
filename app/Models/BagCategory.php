<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BagCategory extends Model
{
    protected $fillable = [
        'code', 'name', 'description'
    ];

    // Nantinya di Step 2, kita akan hubungkan ini dengan Product
    public function products()
    {
        return $this->hasMany(Product::class, 'bag_category_id');
    }
}
