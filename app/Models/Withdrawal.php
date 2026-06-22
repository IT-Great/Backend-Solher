<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Withdrawal extends Model
{
    protected $guarded = ['id']; // Mengizinkan mass-assignment

    // Relasi balik ke tabel User (Afiliator)
    public function affiliate()
    {
        return $this->belongsTo(User::class, 'affiliate_id');
    }
}
