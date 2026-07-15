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
        Schema::create('carts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('product_id')->index('carts_product_id_foreign');
            $table->string('color', 50)->nullable();
            $table->integer('quantity');
            $table->decimal('gross_amount', 15);
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'product_id', 'color'], 'carts_user_product_color_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
