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
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('affiliate_id')->index('withdrawals_affiliate_id_foreign');
            $table->decimal('amount', 15);
            $table->string('bank_name');
            $table->string('account_number');
            $table->string('account_name');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
