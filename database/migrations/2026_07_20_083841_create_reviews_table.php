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
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();

            $table->tinyInteger('rating')->unsigned(); // Bintang 1 - 5
            $table->text('comment')->nullable();

            // Kita gunakan JSON untuk menyimpan array URL gambar (maksimal 3-5 gambar per review)
            $table->json('images')->nullable();

            // Untuk moderasi, jika admin ingin menyembunyikan review yang mengandung kata kotor
            $table->boolean('is_approved')->default(true);

            $table->timestamps();

            // Mencegah 1 user mereview barang yang sama 2x di 1 transaksi
            $table->unique(['user_id', 'product_id', 'transaction_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
