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
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign(['address_id'])->references(['id'])->on('addresses')->onUpdate('cascade')->onDelete('set null');
            $table->foreign(['affiliate_id'])->references(['id'])->on('users')->onUpdate('restrict')->onDelete('set null');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('restrict')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign('transactions_address_id_foreign');
            $table->dropForeign('transactions_affiliate_id_foreign');
            $table->dropForeign('transactions_user_id_foreign');
        });
    }
};
