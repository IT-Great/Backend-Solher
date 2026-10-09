<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_intakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Tujuan Konsultasi: 'style_advice', 'product_inquiry', 'complaint', 'feedback'
            $table->string('intent');

            // Kategori Komplain (Jika intent = complaint)
            $table->string('complaint_type')->nullable();

            // Preferensi Gaya (Disimpan dalam bentuk JSON Array)
            $table->json('preferred_styles')->nullable();

            // Tipe Tas (Disimpan dalam bentuk JSON Array)
            $table->json('preferred_bag_types')->nullable();

            // Warna Kesukaan (Disimpan dalam bentuk JSON Array)
            $table->json('preferred_colors')->nullable();

            // Catatan Tambahan
            $table->text('specific_needs')->nullable();

            // Status: 'pending', 'in_progress', 'resolved'
            $table->string('status')->default('pending');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_intakes');
    }
};
