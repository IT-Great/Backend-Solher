<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::create('promos', function (Blueprint $table) {
            $table->id();

            // Info Dasar
            $table->string('code')->unique()->comment('Kode voucher, misal: SOLHER17');
            $table->string('title')->nullable()->comment('Nama promo untuk internal');
            $table->text('description')->nullable()->comment('Deskripsi S&K');

            // Konfigurasi Diskon
            $table->enum('discount_type', ['fixed', 'percentage', 'free_shipping'])->default('fixed');
            $table->decimal('discount_value', 15, 2)->default(0)->comment('Bisa berupa nominal (Rp 250.000) atau persentase (17%)');
            $table->decimal('max_discount', 15, 2)->nullable()->comment('Batas maksimal diskon jika tipe persentase');
            $table->decimal('min_purchase', 15, 2)->default(0)->comment('Syarat minimal belanja');

            // Konfigurasi Target (Opsional, jika null berarti berlaku untuk semua)
            $table->foreignId('target_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('target_product_id')->nullable()->constrained('products')->nullOnDelete();

            // Limitasi & Aturan Khusus
            $table->integer('quota')->nullable()->comment('Batas total penggunaan secara global. Null = Unlimited');
            $table->integer('used_count')->default(0);
            $table->integer('max_usage_per_user')->default(1)->comment('Berapa kali 1 user bisa pakai kode ini');

            $table->boolean('is_member_only')->default(false)->comment('Jika true, setara SOLHERMEMBER');
            $table->boolean('is_first_order_only')->default(false)->comment('Jika true, setara FIRSTORDER');
            $table->boolean('is_active')->default(true);

            // Validitas Waktu
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('promos');
    }
};
