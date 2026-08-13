<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Tambahkan kolom bag_category_id, boleh null (karena produk lama mungkin belum ada tipenya)
            $table->unsignedBigInteger('bag_category_id')->nullable()->after('category_id');

            // Buat relasi foreign key
            $table->foreign('bag_category_id')
                  ->references('id')->on('bag_categories')
                  ->onDelete('set null'); // Jika tipe tas dihapus, produk tidak ikut terhapus
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['bag_category_id']);
            $table->dropColumn('bag_category_id');
        });
    }
};
