<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->integer('bundle_qty')->nullable()->after('description');
            $table->decimal('bundle_price', 15, 2)->nullable()->after('bundle_qty');
            $table->dateTime('bundle_start_date')->nullable()->after('bundle_price');
            $table->dateTime('bundle_end_date')->nullable()->after('bundle_start_date');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['bundle_qty', 'bundle_price', 'bundle_start_date', 'bundle_end_date']);
        });
    }
};
