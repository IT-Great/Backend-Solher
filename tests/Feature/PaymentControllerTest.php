<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected $user;
    protected $address;
    protected $category;
    protected $product;
    protected $transaction;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // 1. Buat User
        $this->user = User::create([
            'first_name' => 'Steve',
            'last_name' => 'Jobs',
            'email' => 'steve_pay_' . \Str::random(5) . '@solher.com',
            'password' => bcrypt('password123'),
            'usertype' => 'user',
            'is_membership' => false,
            'point' => 100, // Saldo Poin
        ]);

        // 2. Buat Alamat
        $this->address = Address::create([
            'user_id' => $this->user->id,
            'first_name_address' => 'Steve',
            'last_name_address' => 'Jobs',
            'phone_address' => '08123456789',
            'postal_code' => '60275',
            'address_location' => 'Jalan Apel No. 1',
            'latitude' => '-7.25',
            'longitude' => '112.75',
        ]);

        // 3. Buat Produk & Stok
        $this->category = Category::create([
            'category_code' => 'CAT-' . \Str::random(5),
            'category_name' => 'Payment Bags',
        ]);

        $this->product = Product::create([
            'code' => 'PAY-BAG-' . \Str::random(5),
            'name' => 'Xendit Tote',
            'category_id' => $this->category->id,
            'price' => 1000000,
            'stock' => 10,
            'weight' => 1500, // 1.5 KG
            'status' => 'active',
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'batch_code' => 'BATCH-PAY',
            'quantity' => 10,
            'initial_quantity' => 10,
        ]);

        // 4. Buat Transaksi Dasar
        $this->transaction = Transaction::create([
            'user_id' => $this->user->id,
            'address_id' => $this->address->id,
            'order_id' => 'SOL-PAY-' . \Str::random(5),
            'total_amount' => 1000000,
            'status' => 'awaiting_payment',
            'point' => 10,
            'points_used' => 50, // User memakai 50 poin
            'shipping_method' => 'biteship',
        ]);

        TransactionDetail::create([
            'transaction_id' => $this->transaction->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => 1000000,
            'color' => 'Navy',
        ]);
    }

    /**
     * TEST 1: SHIPPING RATES
     * Menguji perhitungan berat keranjang fallback ke 1000g dan menembak API Biteship
     */
    public function test_get_shipping_rates_calculates_weight_and_calls_biteship()
    {
        // Mock (Palsukan) API Biteship agar tidak menembak server asli
        Http::fake([
            'api.biteship.com/v1/rates/couriers' => Http::response(['success' => true, 'rates' => []], 200),
        ]);

        // Buat 2 item keranjang (Produk 1.5kg qty 2 = 3000g)
        $cart = Cart::create([
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
            'quantity' => 2,
            'gross_amount' => 2000000
        ]);

        // Asumsi Rute: /api/shipping-rates (Sesuaikan jika rute di api.php berbeda)
        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/shipping/rates', [
                             'address_id' => $this->address->id,
                             'cart_ids' => [$cart->id]
                         ]);

        $response->assertStatus(200);

        // Pastikan Request ke Biteship benar-benar dieksekusi dengan total weight 3000g
        Http::assertSent(function ($request) {
            $payload = $request->data();
            return $request->url() == 'https://api.biteship.com/v1/rates/couriers' &&
                   $payload['items'][0]['weight'] === 3000;
        });
    }

    /**
     * TEST 2: CREATE INVOICE (REUSE EXISTING URL)
     * Menguji apakah sistem cerdas menggunakan URL Xendit yang sudah ada jika belum kedaluwarsa
     */
    public function test_create_invoice_returns_existing_url_if_already_pending()
    {
        // Buat Payment yang sudah ada URL-nya
        Payment::create([
            'transaction_id' => $this->transaction->id,
            'external_id' => 'PAY-EXISTING-123',
            'checkout_url' => 'https://checkout.xendit.co/web/already-exists',
            'amount' => 1000000,
            'status' => 'pending',
        ]);

        // Asumsi Rute: /api/payment/invoice (Sesuaikan jika rute berbeda)
        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/payments/invoice', [
                             'transaction_id' => $this->transaction->id,
                             'address_id' => $this->address->id,
                             'shipping_method' => 'biteship',
                             'shipping_cost' => 15000,
                             'courier_company' => 'JNE',
                         ]);

        $response->assertStatus(200)
                 ->assertJson(['checkout_url' => 'https://checkout.xendit.co/web/already-exists']);
    }

    /**
     * TEST 3: WEBHOOK CALLBACK (PAID)
     * Ini yang paling krusial! Menguji apakah Webhook mengubah status pesanan dan memanggil Biteship
     */
    public function test_xendit_callback_paid_updates_status_and_creates_biteship_order()
    {
        // Mock API Biteship untuk Pembuatan Order (Create Order)
        Http::fake([
            'api.biteship.com/v1/orders' => Http::response([
                'success' => true,
                'id' => 'BITESHIP-ORD-999',
                'status' => 'placed',
                'courier' => ['waybill_id' => 'RESI-123456']
            ], 200),
        ]);

        $payment = Payment::create([
            'transaction_id' => $this->transaction->id,
            'external_id' => 'PAY-TEST-CALLBACK',
            'checkout_url' => 'https://checkout.xendit.co/web/test',
            'amount' => 1000000,
            'status' => 'pending',
        ]);

        // Tembak Webhook Callback
        // Asumsi Rute: /api/payment/callback
        $response = $this->postJson('/api/payments/callback', [
            'external_id' => 'PAY-TEST-CALLBACK',
            'status' => 'PAID',
            'payment_method' => 'EWALLET',
            'payment_channel' => 'OVO'
        ]);

        $response->assertStatus(200);

        // Verifikasi Perubahan Status
        $payment->refresh();
        $this->transaction->refresh();

        $this->assertEquals('PAID', $payment->status);
        $this->assertEquals('processing', $this->transaction->status); // Karena biteship, status jadi processing
        $this->assertEquals('EWALLET OVO', $this->transaction->payment_method);

        // Verifikasi bahwa Order Biteship Dibuat (Data tersimpan di Transaksi)
        $this->assertEquals('BITESHIP-ORD-999', $this->transaction->biteship_order_id);
        $this->assertEquals('RESI-123456', $this->transaction->tracking_number);
    }

    /**
     * TEST 4: WEBHOOK CALLBACK (EXPIRED/FAILED)
     * Menguji "Jaring Pengaman": Jika user gagal bayar, pesanan batal, STOK dan POIN kembali utuh!
     */
    public function test_xendit_callback_expired_cancels_order_and_restores_stock_and_points()
    {
        // Setup Awal: Stok sudah dikurangi saat checkout
        $this->product->decrement('stock', 1); // Stok jadi 9
        $batch = ProductStock::where('product_id', $this->product->id)->first();
        $batch->decrement('quantity', 1); // Batch sisa 9

        $payment = Payment::create([
            'transaction_id' => $this->transaction->id,
            'external_id' => 'PAY-TEST-EXPIRED',
            'checkout_url' => 'https://checkout.xendit.co/web/expired',
            'amount' => 1000000,
            'status' => 'pending',
        ]);

        // Poin awal user = 100. Poin yang digunakan untuk transaksi ini = 50.
        // Jika batal, poin harus kembali menjadi 150.

        // Tembak Webhook Callback EXPIRED
        $response = $this->postJson('/api/payments/callback', [
            'external_id' => 'PAY-TEST-EXPIRED',
            'status' => 'EXPIRED',
        ]);

        $response->assertStatus(200);

        // VERIFIKASI KEAMANAN TINGKAT TINGGI (Semua harus KEMBALI SEPERTI SEMULA)
        $this->transaction->refresh();
        $payment->refresh();
        $this->product->refresh();
        $this->user->refresh();
        $batch->refresh();

        // 1. Status Batal
        $this->assertEquals('EXPIRED', $payment->status);
        $this->assertEquals('cancelled', $this->transaction->status);

        // 2. Poin yang hangus dikembalikan! (100 + 50)
        $this->assertEquals(150, $this->user->point);

        // 3. Stok Barang Dikembalikan (9 + 1 = 10)
        $this->assertEquals(10, $this->product->stock);
        $this->assertEquals(10, $batch->quantity);
    }
}
