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
        Schema::create('events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title_id');
            $table->string('title_en')->nullable();
            $table->longText('description_id')->nullable();
            $table->longText('description_en')->nullable();
            $table->longText('images')->nullable();
            $table->date('event_date');
            $table->string('season_id')->nullable();
            $table->string('season_en')->nullable();
            $table->enum('status', ['published', 'draft'])->default('published');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
