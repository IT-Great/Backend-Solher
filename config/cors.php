<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'], // Pastikan rute api/* diizinkan

    'allowed_methods' => ['*'], // Izinkan semua method (GET, POST, PUT, DELETE, dll)

    // [PERBAIKAN KUNCI] Masukkan URL Frontend Anda di sini (TIDAK BOLEH ADA GARIS MIRING '/' DI AKHIR)
    'allowed_origins' => [
        'https://solher.co.id',
        'http://localhost:5173', // Opsional: Tambahkan ini jika Anda juga mengetesnya secara lokal dengan Vue/Vite
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'], // Izinkan semua header (termasuk Authorization Bearer)

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true, // Ubah jadi true jika Anda menggunakan cookie/session (Sanctum)
];
