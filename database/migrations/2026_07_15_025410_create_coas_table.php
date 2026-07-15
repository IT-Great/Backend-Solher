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
        Schema::create('coas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->unique();
            $table->string('coa_no')->unique();
            $table->unsignedBigInteger('coa_category_id')->index('coas_coa_category_id_foreign');
            $table->bigInteger('amount')->nullable();
            $table->bigInteger('debit')->nullable();
            $table->bigInteger('credit')->nullable();
            $table->date('date')->nullable();
            $table->date('posted_date')->nullable();
            $table->boolean('posted')->default(false);
            $table->enum('type', ['Debit', 'Kredit']);
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coas');
    }
};
