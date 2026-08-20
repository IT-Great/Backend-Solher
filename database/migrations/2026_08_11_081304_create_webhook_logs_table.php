<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('gateway'); // xendit, stripe, paypal, biteship
            $table->string('event_id')->index(); // ID unik transaksi dari payload gateway
            $table->string('status')->default('processing'); // processing, completed, failed
            $table->json('payload')->nullable();
            $table->text('response_message')->nullable();
            $table->timestamps();

            // Kunci unik: Kombinasi gateway + event_id tidak boleh duplikat
            $table->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};