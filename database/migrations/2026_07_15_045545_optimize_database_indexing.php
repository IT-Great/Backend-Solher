<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Optimasi Tabel Transactions (Sering dihitung untuk Dashboard)
        Schema::table('transactions', function (Blueprint $table) {
            $table->index('status'); // Sangat sering dipakai: where('status', 'completed')
            $table->index('created_at'); // Untuk chart 6 bulan terakhir: where('created_at', '>=', ...)
            // Composite Index untuk Dashboard (Kombinasi Status & Tanggal)
            $table->index(['status', 'created_at']);
        });

        // 2. Optimasi Tabel Products
        Schema::table('products', function (Blueprint $table) {
            $table->index('status'); // where('status', 'active')
            $table->index('category_id'); // Untuk filter kategori
            $table->index('price'); // Jika ada sort by price di toko
        });

        // 3. Optimasi Tabel Transaction Details (Sering di-JOIN untuk produk terlaris)
        Schema::table('transaction_details', function (Blueprint $table) {
            $table->index('product_id'); 
            $table->index('transaction_id');
        });

        // 4. Optimasi Tabel Users
        Schema::table('users', function (Blueprint $table) {
            $table->index('usertype'); // Untuk menghitung: where('usertype', 'user')
            $table->index('email'); // Wajib untuk proses Login yang cepat
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['status', 'created_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['category_id']);
            $table->dropIndex(['price']);
        });

        Schema::table('transaction_details', function (Blueprint $table) {
            $table->dropIndex(['product_id']);
            $table->dropIndex(['transaction_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['usertype']);
            $table->dropIndex(['email']);
        });
    }
};