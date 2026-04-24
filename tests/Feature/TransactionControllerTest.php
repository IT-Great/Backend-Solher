<?php

namespace Tests\Feature;

use App\Http\Controllers\TransactionController;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\PromoClaim;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TransactionControllerTest extends TestCase
{
    // Melindungi database asli dari kerusakan
    use DatabaseTransactions;

    protected $user;
    protected $category;
    protected $product;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        Cache::flush();

        // Cegah API External (Biteship) benar-benar terpanggil selama tes
        Http::fake([
            'api.biteship.com/*' => Http::response(['success' => true, 'status' => 'cancelled'], 200),
        ]);

        // 1. Buat User Dasar
        $this->user = User::create([
            'first_name' => 'Elon',
            'last_name' => 'Musk',
            'email' => 'elon_tx_' . \Str::random(5) . '@solher.com',
            'password' => bcrypt('password123'),
            'usertype' => 'user',
            'is_membership' => false,
            'point' => 50, // Punya 50 poin
        ]);

        // 2. Buat Kategori
        $this->category = Category::create([
            'category_code' => 'CAT-' . \Str::random(5),
            'category_name' => 'Luxury Bags',
        ]);

        // 3. Buat Produk Utama
        $this->product = Product::create([
            'code' => 'TX-BAG-' . \Str::random(5),
            'name' => 'Diamond Tote',
            'category_id' => $this->category->id,
            'price' => 1000000, // Rp 1.000.000
            'stock' => 10,
            'weight' => 1200,
            'status' => 'active',
        ]);
    }

    /**
     * TEST 1: ALGORITMA FIFO STOCK RESTORE
     * Menguji apakah fungsi Helper pengembalian stok (Bencana Anti-Race Condition) bekerja sempurna
     */
    public function test_fifo_stock_restore_algorithm_works_perfectly()
    {
        // Kondisi Awal: Produk sisa 0 stok.
        $this->product->update(['stock' => 0]);

        // Buat 2 Batch Stok yang sudah bolong (Tidak penuh)
        // Batch 1: Dulu isinya 5, sekarang habis (0)
        $batch1 = ProductStock::create([
            'product_id' => $this->product->id,
            'batch_code' => 'BATCH-OLD',
            'quantity' => 0,
            'initial_quantity' => 5,
            'created_at' => now()->subDays(2) // Paling lama
        ]);

        // Batch 2: Dulu isinya 10, sekarang sisa 2 (Ada ruang kosong 8)
        $batch2 = ProductStock::create([
            'product_id' => $this->product->id,
            'batch_code' => 'BATCH-NEW',
            'quantity' => 2,
            'initial_quantity' => 10,
            'created_at' => now()->subDay(1) // Lebih baru
        ]);

        // AKSI: Seseorang membatalkan pesanan berisi 6 barang.
        // Ekspektasi: 5 barang masuk ke Batch 1 (memenuhinya), 1 barang sisanya masuk ke Batch 2.
        $controller = app(TransactionController::class);
        $controller->restoreProductStock($this->product->id, 6);

        // ASSERTIONS (Verifikasi)
        $batch1->refresh();
        $batch2->refresh();
        $this->product->refresh();

        // Total stok produk harus kembali menjadi 6
        $this->assertEquals(6, $this->product->stock);

        // Batch 1 harus penuh duluan (FIFO) -> 0 + 5 = 5
        $this->assertEquals(5, $batch1->quantity);

        // Batch 2 hanya menerima sisa luberan -> 2 + 1 = 3
        $this->assertEquals(3, $batch2->quantity);
    }

    /**
     * TEST 2: CANCEL ORDER RESTORES EVERYTHING
     * Memastikan saat order dibatalkan, Stok, Poin, dan Kode Promo kembali utuh
     */
    public function test_cancel_order_restores_stock_points_and_promo_code()
    {
        // 1. Setup Promo Claim yang sedang "terpakai"
        PromoClaim::create([
            'email' => $this->user->email,
            'promo_code' => 'WELCOME250',
            'discount_value' => 250000,
            'is_used' => true,
            'used_at' => now(),
        ]);

        // 2. Setup Transaksi yang menggunakan Promo dan Poin
        $transaction = Transaction::create([
            'user_id' => $this->user->id,
            'order_id' => 'SOL-TEST-' . \Str::random(5),
            'total_amount' => 750000,
            'status' => 'pending',
            'point' => 7, // Potensi poin yang didapat
            'points_used' => 20, // Poin yang dipakai buat potong harga
            'promo_code' => 'WELCOME250',
        ]);

        // 3. Setup Detail Transaksi (Membeli 2 barang)
        TransactionDetail::create([
            'transaction_id' => $transaction->id,
            'product_id' => $this->product->id,
            'quantity' => 2,
            'price' => 1000000,
        ]);

        // Kosongkan sebagian stok untuk melihat apakah kembalinya benar
        $this->product->update(['stock' => 8]);
        ProductStock::create([
            'product_id' => $this->product->id,
            'batch_code' => 'BATCH-TX',
            'quantity' => 8,
            'initial_quantity' => 10
        ]);

        // AKSI: Tembak Endpoint Cancel Order
        // Asumsi rute standar di api.php: Route::post('/transactions/{id}/cancel', [TxController::class, 'cancelOrder']);
        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson("/api/transactions/{$transaction->id}/cancel");

        $response->assertStatus(200);

        // ASSERTIONS (Verifikasi Kebenaran Data)
        $transaction->refresh();
        $this->user->refresh();
        $this->product->refresh();
        $promo = PromoClaim::where('email', $this->user->email)->first();

        // 1. Status Tx harus Cancelled
        $this->assertEquals('cancelled', $transaction->status);

        // 2. Poin yang HANGUS (20) harus KEMBALI ke dompet user (50 + 20 = 70)
        $this->assertEquals(70, $this->user->point);

        // 3. Kode Promo harus KEMBALI BISA DIPAKAI (is_used = 0)
        $this->assertFalse((bool) $promo->is_used);

        // 4. Stok Barang harus KEMBALI (8 + 2 = 10)
        $this->assertEquals(10, $this->product->stock);
    }

    /**
     * TEST 3: AUTO-MEMBERSHIP & POINTS EARNING
     * Menguji logika jika pesanan selesai, user mendapat poin dan di-upgrade jadi member
     */
    public function test_it_assigns_membership_and_gives_points_on_order_completed()
    {
        // User awalnya bukan member
        $this->assertFalse((bool) $this->user->is_membership);

        $transaction = Transaction::create([
            'user_id' => $this->user->id,
            'order_id' => 'SOL-CMP-' . \Str::random(5),
            'total_amount' => 1200000, // Syarat member > 100.000 terpenuhi
            'status' => 'processing',
            'point' => 12, // Poin yang akan dicairkan saat status selesai
        ]);

        // AKSI: Tembak Endpoint Confirm Complete
        // Asumsi rute standar di api.php: Route::post('/transactions/{id}/complete', [TxController::class, 'confirmComplete']);
        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson("/api/transactions/{$transaction->id}/complete");

        $response->assertStatus(200);

        // ASSERTIONS
        $this->user->refresh();
        $transaction->refresh();

        $this->assertEquals('completed', $transaction->status);

        // User harus otomatis jadi Member karena belanja 1.2 Juta!
        $this->assertTrue((bool) $this->user->is_membership);

        // Poin user HARUS BERTAMBAH (Awal 50 + 12 = 62)
        $this->assertEquals(62, $this->user->point);
    }

    /**
     * TEST 4: REQUEST REFUND (UPLOAD FILE KE S3)
     * Menguji keamanan pengajuan komplain barang rusak
     */
    public function test_it_can_request_refund_with_proof_upload()
    {
        // Transaksi harus berstatus completed atau shipping_failed untuk bisa direfund
        $transaction = Transaction::create([
            'user_id' => $this->user->id,
            'order_id' => 'SOL-REF-' . \Str::random(5),
            'total_amount' => 500000,
            'status' => 'completed',
        ]);

        // Buat file video/gambar dummy (5MB)
        $fakeVideo = UploadedFile::fake()->create('unboxing.mp4', 5000, 'video/mp4');

        // AKSI
        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson("/api/transactions/{$transaction->id}/refund", [
                             'reason' => 'Barang sobek di bagian tali',
                             'proof_file' => $fakeVideo
                         ]);

        $response->assertStatus(200);

        // ASSERTIONS
        $transaction->refresh();
        $this->assertEquals('refund_requested', $transaction->status);
        $this->assertEquals('Barang sobek di bagian tali', $transaction->refund_reason);
        $this->assertNotNull($transaction->refund_proof_url);

        // Pastikan file benar-benar masuk ke "S3" palsu kita
        $filename = str_replace(Storage::disk('s3')->url(''), '', $transaction->refund_proof_url);
        Storage::disk('s3')->assertExists($filename);
    }
}
