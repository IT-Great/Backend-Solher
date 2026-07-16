<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Ubah tipe data menjadi JSON untuk menampung { "IDR": 99000, "USD": 5 } dll.
            $table->json('bundle_price')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Rollback jika terjadi kesalahan (kembali ke decimal untuk IDR saja)
            $table->decimal('bundle_price', 15, 2)->nullable()->change();
        });
    }
};
