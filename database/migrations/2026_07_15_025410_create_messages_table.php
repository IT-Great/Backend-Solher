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
        Schema::create('messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('sender_id')->index('messages_sender_id_foreign');
            $table->unsignedBigInteger('receiver_id')->index('messages_receiver_id_foreign');
            $table->text('message')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
            $table->string('attachment')->nullable();
            $table->string('attachment_type')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
