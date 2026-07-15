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
        Schema::table('payment_histories', function (Blueprint $table) {
            $table->foreign(['invoice_id'])->references(['id'])->on('invoice_suppliers')->onUpdate('restrict')->onDelete('cascade');
            $table->foreign(['processed_by'])->references(['id'])->on('users')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_histories', function (Blueprint $table) {
            $table->dropForeign('payment_histories_invoice_id_foreign');
            $table->dropForeign('payment_histories_processed_by_foreign');
        });
    }
};
