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
        Schema::table('transfer_receive_payments', function (Blueprint $table) {
            $table->foreign(['debit_coa_id'], 'transactions_debit_coa_id_foreign')->references(['id'])->on('coas')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign(['kredit_coa_id'], 'transactions_kredit_coa_id_foreign')->references(['id'])->on('coas')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transfer_receive_payments', function (Blueprint $table) {
            $table->dropForeign('transactions_debit_coa_id_foreign');
            $table->dropForeign('transactions_kredit_coa_id_foreign');
        });
    }
};
