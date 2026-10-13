<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Tier yang diizinkan masuk: 'none', 'muse', 'elan', 'heritage'
            $table->enum('early_access_tier', ['none', 'muse', 'elan', 'heritage'])->default('none')->after('discount_end_date');
            // Batas waktu sampai produk ini menjadi publik
            $table->timestamp('early_access_expires_at')->nullable()->after('early_access_tier');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['early_access_tier', 'early_access_expires_at']);
        });
    }
};
