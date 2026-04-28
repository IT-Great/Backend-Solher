<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'images',
        'event_date',
        'season',
        'status',
    ];

    // Tambahkan ini agar string otomatis jadi array
    protected $casts = [
        'images' => 'array',
    ];
}
