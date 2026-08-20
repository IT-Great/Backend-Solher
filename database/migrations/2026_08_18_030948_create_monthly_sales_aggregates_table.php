<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('monthly_sales_aggregates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('product_code')->index();
            $table->string('product_name')->index();
            $table->string('product_image')->nullable();
            $table->string('category_name')->nullable();

            $table->integer('month');
            $table->integer('year');

            $table->integer('total_sold')->default(0);
            $table->decimal('total_revenue', 15, 2)->default(0);

            $table->timestamps();

            // Kunci unik agar saat Sinkronisasi (Upsert), data tidak berlipat ganda
            $table->unique(['product_id', 'month', 'year']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('monthly_sales_aggregates');
    }
};
