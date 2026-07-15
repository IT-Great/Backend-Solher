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
        Schema::create('transfer_receive_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('kredit_coa_id')->index('transactions_kredit_coa_id_foreign');
            $table->unsignedBigInteger('debit_coa_id')->index('transactions_debit_coa_id_foreign');
            $table->string('recipient_name')->nullable();
            $table->string('no_transaction')->unique('transactions_no_transaction_unique');
            $table->bigInteger('amount');
            $table->dateTime('date')->default('2025-04-09 01:47:55');
            $table->enum('type', ['transfer', 'receive']);
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfer_receive_payments');
    }
};
