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
        Schema::create('products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('category_id')->index('products_category_id_foreign');
            $table->string('code')->unique();
            $table->string('name');
            $table->string('slug')->nullable()->unique('slug');
            $table->string('image')->nullable();
            $table->json('variant_images')->nullable();
            $table->string('variant_video', 500)->nullable();
            $table->double('price');
            $table->json('prices')->nullable();
            $table->decimal('discount_price', 10)->nullable();
            $table->json('discount_prices')->nullable();
            $table->dateTime('discount_start_date')->nullable();
            $table->dateTime('discount_end_date')->nullable();
            $table->integer('stock')->default(0);
            $table->integer('weight')->default(1000);
            $table->decimal('length')->nullable();
            $table->decimal('width')->nullable();
            $table->decimal('height')->nullable();
            $table->string('material')->nullable();
            $table->json('strap_length')->nullable();
            $table->json('color')->nullable();
            $table->longText('description')->nullable();
            $table->longText('description_en')->nullable();
            $table->longText('design')->nullable();
            $table->longText('design_en')->nullable();
            $table->enum('status', ['active', 'inactive'])->nullable()->default('active');
            $table->boolean('is_notified')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
