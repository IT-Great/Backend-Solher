<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'title_id',
        'title_en',
        'description_id',
        'description_en',
        'images',
        'event_date',
        'season_id',
        'season_en',
        'status',
    ];

    protected $casts = [
        'images' => 'array',
    ];

    // Mendaftarkan atribut dinamis agar otomatis masuk ke respons JSON API
    protected $appends = ['title', 'description', 'season'];

    // --- ACCESSORS ---
    // Logika: Jika Vue mengirim header 'Accept-Language: en', kembalikan bahasa Inggris.
    // Jika tidak, default ke bahasa Indonesia.

    public function getTitleAttribute()
    {
        $lang = request()->header('Accept-Language');
        return $lang === 'en' && !empty($this->title_en) ? $this->title_en : $this->title_id;
    }

    public function getDescriptionAttribute()
    {
        $lang = request()->header('Accept-Language');
        return $lang === 'en' && !empty($this->description_en) ? $this->description_en : $this->description_id;
    }

    public function getSeasonAttribute()
    {
        $lang = request()->header('Accept-Language');
        return $lang === 'en' && !empty($this->season_en) ? $this->season_en : $this->season_id;
    }
}
