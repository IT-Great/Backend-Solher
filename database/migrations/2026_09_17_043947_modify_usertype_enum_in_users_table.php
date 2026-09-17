<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE users
            MODIFY COLUMN usertype
            ENUM('user', 'admin', 'superadmin', 'gudang', 'accounting', 'cs', 'guest')
            NOT NULL
            DEFAULT 'user'
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE users
            MODIFY COLUMN usertype
            ENUM('user', 'admin', 'superadmin', 'gudang', 'accounting')
            NOT NULL
            DEFAULT 'user'
        ");
    }
};
