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
        Schema::table('invoice_suppliers', function (Blueprint $table) {
            $table->foreign(['debit_coa_id'])->references(['id'])->on('coas')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign(['kredit_coa_id'])->references(['id'])->on('coas')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign(['new_kredit_coa_id'])->references(['id'])->on('coas')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign(['old_kredit_coa_id'])->references(['id'])->on('coas')->onUpdate('cascade')->onDelete('cascade');
            $table->foreign(['supplier_id'])->references(['id'])->on('supplier_data')->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoice_suppliers', function (Blueprint $table) {
            $table->dropForeign('invoice_suppliers_debit_coa_id_foreign');
            $table->dropForeign('invoice_suppliers_kredit_coa_id_foreign');
            $table->dropForeign('invoice_suppliers_new_kredit_coa_id_foreign');
            $table->dropForeign('invoice_suppliers_old_kredit_coa_id_foreign');
            $table->dropForeign('invoice_suppliers_supplier_id_foreign');
        });
    }
};
