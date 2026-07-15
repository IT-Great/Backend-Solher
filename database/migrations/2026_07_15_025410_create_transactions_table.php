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
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index('transactions_user_id_foreign');
            $table->unsignedBigInteger('address_id')->nullable()->index();
            $table->string('shipping_method')->nullable()->default('free');
            $table->string('order_id')->unique();
            $table->decimal('total_amount', 15);
            $table->string('currency_code', 3)->default('IDR');
            $table->decimal('exchange_rate', 15, 4)->default(1);
            $table->decimal('grand_total_foreign', 15)->nullable();
            $table->decimal('shipping_cost', 15)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('courier_company')->nullable();
            $table->string('courier_type')->nullable();
            $table->string('delivery_type', 50)->nullable()->default('later');
            $table->date('delivery_date')->nullable();
            $table->time('delivery_time')->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'cancelled', 'awaiting_payment', 'refund_requested', 'refund_approved', 'refunded', 'refund_rejected', 'refund_manual_required', 'shipping_failed', 'returned'])->default('pending');
            $table->text('refund_reason')->nullable();
            $table->string('refund_proof_url')->nullable();
            $table->string('tracking_number')->nullable()->index();
            $table->string('shipping_status', 50)->nullable()->default('pending');
            $table->integer('point')->default(0);
            $table->integer('points_used')->default(0);
            $table->string('promo_code', 50)->nullable();
            $table->integer('promo_discount')->default(0);
            $table->string('biteship_order_id')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('affiliate_id')->nullable()->index('transactions_affiliate_id_foreign');
            $table->decimal('commission_earned', 15)->default(0);
            $table->enum('commission_status', ['pending', 'settled', 'void'])->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
