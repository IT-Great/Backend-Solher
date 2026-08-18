<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlySalesAggregate extends Model
{
    protected $fillable = [
        'product_id', 'product_code', 'product_name', 'product_image',
        'category_name', 'month', 'year', 'total_sold', 'total_revenue'
    ];
}
