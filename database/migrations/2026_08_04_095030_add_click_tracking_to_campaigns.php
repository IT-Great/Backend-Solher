<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->integer('clicked_count')->default(0)->after('opened_count');
        });

        Schema::table('campaign_logs', function (Blueprint $table) {
            $table->boolean('is_clicked')->default(false)->after('is_opened');
            $table->timestamp('clicked_at')->nullable()->after('opened_at');
        });
    }

    public function down()
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('clicked_count');
        });
        Schema::table('campaign_logs', function (Blueprint $table) {
            $table->dropColumn(['is_clicked', 'clicked_at']);
        });
    }
};