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
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('profile_image')->nullable();
            $table->string('email')->unique();
            $table->string('phone', 20)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->enum('usertype', ['user', 'admin', 'superadmin', 'gudang', 'accounting', 'cs'])->default('user');
            $table->boolean('is_membership')->default(false);
            $table->boolean('has_used_member_voucher')->default(false);
            $table->integer('point')->default(0);
            $table->boolean('is_subscribed')->default(false);
            $table->rememberToken();
            $table->timestamps();
            $table->boolean('is_affiliate')->default(false);
            $table->decimal('commission_balance', 15)->default(0);
            $table->string('referral_code')->nullable()->unique();
            $table->decimal('commission_rate', 5)->default(5);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
