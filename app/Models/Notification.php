<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    // Mengizinkan semua kolom diisi secara massal (Mass Assignment) kecuali ID
    protected $guarded = ['id'];

    // Memastikan kolom is_read otomatis berformat boolean (true/false) saat ditarik ke Frontend
    protected $casts = [
        'is_read' => 'boolean',
    ];

    // Relasi ke tabel users
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
