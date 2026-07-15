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
        Schema::create('invoice_suppliers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('no_invoice')->unique();
            $table->unsignedBigInteger('supplier_id')->index('invoice_suppliers_supplier_id_foreign');
            $table->bigInteger('amount');
            $table->integer('pph')->nullable();
            $table->integer('pph_percentage')->nullable();
            $table->dateTime('date')->default('2025-04-10 08:56:15');
            $table->string('nota')->nullable();
            $table->dateTime('deadline_invoice')->default('2025-04-10 08:56:15');
            $table->enum('payment_status', ['Paid', 'Not Yet'])->default('Not Yet');
            $table->string('payment_method')->nullable();
            $table->unsignedBigInteger('kredit_coa_id')->nullable()->index('invoice_suppliers_kredit_coa_id_foreign');
            $table->unsignedBigInteger('debit_coa_id')->nullable()->index('invoice_suppliers_debit_coa_id_foreign');
            $table->unsignedBigInteger('old_kredit_coa_id')->nullable()->index('invoice_suppliers_old_kredit_coa_id_foreign');
            $table->unsignedBigInteger('new_kredit_coa_id')->nullable()->index('invoice_suppliers_new_kredit_coa_id_foreign');
            $table->string('description')->nullable();
            $table->string('image_invoice')->nullable();
            $table->string('image_proof')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_suppliers');
    }
};
