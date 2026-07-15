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
        Schema::create('addresses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index('addresses_user_id_foreign');
            $table->string('region');
            $table->string('first_name_address');
            $table->string('last_name_address');
            $table->longText('address_location');
            $table->string('location_type')->nullable();
            $table->string('city');
            $table->string('province');
            $table->string('postal_code');
            $table->string('latitude', 50)->nullable();
            $table->string('longitude', 50)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
