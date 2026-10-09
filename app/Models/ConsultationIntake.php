<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ConsultationIntake extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    // Casting JSON ke Array otomatis
    protected $casts = [
        'preferred_styles' => 'array',
        'preferred_bag_types' => 'array',
        'preferred_colors' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
