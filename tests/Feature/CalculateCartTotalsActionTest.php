<?php

use App\Actions\Checkout\CalculateCartTotalsAction;
use App\Models\User;
use App\Models\Category;
use App\Models\Product;
use App\Models\Cart;
use App\Models\PromoClaim;
use App\Services\PromoMerdekaService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Menggunakan RefreshDatabase agar database kembali bersih setiap kali test dijalankan
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->action = app(CalculateCartTotalsAction::class);
    $this->promoService = app(PromoMerdekaService::class);

    // User tetap pakai factory karena kita tahu UserFactory sudah berfungsi dengan baik
    $this->user = User::factory()->create([
        'is_membership' => true,
        'point' => 50 // Setara diskon Rp 50.000
    ]);

    // 👇 PERBAIKAN: Ganti factory() menjadi forceCreate() untuk mem-bypass kebutuhan file Factory
    $this->bagCategory = Category::forceCreate([
        'name' => 'Bag Collection',
        'code' => 'C001'
    ]);

    $this->beltCategory = Category::forceCreate([
        'name' => 'Belt Collection',
        'code' => 'C005'
    ]);
});

// 🧪 SKENARIO 1: Kalkulasi Normal Tanpa Promo
it('calculates normal cart totals correctly without any promos', function () {
    // Gunakan forceCreate dengan isian dasar tabel products
    $product = Product::forceCreate([
        'name' => 'Tas Premium',
        'code' => 'BAG-01',
        'price' => 150000,
        'stock' => 10,
        'weight' => 500,
        'category_id' => $this->bagCategory->id
    ]);

    Cart::forceCreate([
        'user_id' => $this->user->id,
        'product_id' => $product->id,
        'quantity' => 2
    ]);

    $cartItems = Cart::with('product.category')->where('user_id', $this->user->id)->get();

    $request = new Request([
        'currency' => 'IDR',
        'shipping_method' => 'free',
        'shipping_cost' => 0,
    ]);

    $result = $this->action->execute($this->user, $cartItems, $request, $this->promoService);

    expect($result['totalAmount'])->toBe(300000) // 150k * 2
        ->and($result['totalQuantity'])->toBe(2)
        ->and($result['promoDiscountAmount'])->toBe(0)
        ->and($result['pointsUsed'])->toBe(0);
});

// 🧪 SKENARIO 2: Penukaran Poin Loyalitas
it('deducts loyalty points correctly for VIP members', function () {
    $product = Product::forceCreate([
        'name' => 'Tas VIP',
        'code' => 'BAG-02',
        'price' => 200000,
        'stock' => 10,
        'weight' => 500,
        'category_id' => $this->bagCategory->id
    ]);

    Cart::forceCreate([
        'user_id' => $this->user->id,
        'product_id' => $product->id,
        'quantity' => 1
    ]);

    $cartItems = Cart::with('product.category')->where('user_id', $this->user->id)->get();

    $request = new Request([
        'currency' => 'IDR',
        'use_points' => 50, // User ingin pakai 50 poin
        'shipping_method' => 'free',
    ]);

    $result = $this->action->execute($this->user, $cartItems, $request, $this->promoService);

    expect($result['pointsUsed'])->toBe(50.0) // Poin tervalidasi dipakai
        ->and($this->user->fresh()->point)->toBe(0); // Sisa poin di DB harus 0
});

// 🧪 SKENARIO 3: Validasi Ketat Voucher Subsidi Tas (SOLHOST34)
it('rejects SOLHOST34 promo if cart has more than 1 item', function () {
    $product = Product::forceCreate([
        'name' => 'Tas Mahal',
        'code' => 'BAG-03',
        'price' => 5000000,
        'stock' => 10,
        'weight' => 1000,
        'category_id' => $this->bagCategory->id
    ]);

    // Beli 2 Tas sekaligus
    Cart::forceCreate([
        'user_id' => $this->user->id,
        'product_id' => $product->id,
        'quantity' => 2
    ]);

    $cartItems = Cart::with('product.category')->where('user_id', $this->user->id)->get();

    $request = new Request([
        'currency' => 'IDR',
        'promo_code' => 'SOLHOST34',
        'shipping_method' => 'free',
    ]);

    // Berharap sistem melempar Exception karena melanggar aturan bos
    expect(fn() => $this->action->execute($this->user, $cartItems, $request, $this->promoService))
        ->toThrow(\Exception::class, 'Voucher Subsidi Tas hanya berlaku untuk 1 barang.');
});

// 🧪 SKENARIO 4: Keamanan Pop-Up Kemerdekaan (SOLHER17)
it('throws an error if SOLHER17 is used without claiming it first via popup', function () {
    $product = Product::forceCreate([
        'name' => 'Tas Kemerdekaan',
        'code' => 'BAG-04',
        'price' => 1000000,
        'stock' => 10,
        'weight' => 1000,
        'category_id' => $this->bagCategory->id
    ]);

    Cart::forceCreate([
        'user_id' => $this->user->id,
        'product_id' => $product->id,
        'quantity' => 1
    ]);

    $cartItems = Cart::with('product.category')->where('user_id', $this->user->id)->get();

    $request = new Request([
        'currency' => 'IDR',
        'promo_code' => 'SOLHER17',
        'shipping_method' => 'free',
    ]);

    // Karena kita tidak membuat `PromoClaim` di awal test ini, sistem harusnya menolak!
    expect(fn() => $this->action->execute($this->user, $cartItems, $request, $this->promoService))
        ->toThrow(\Exception::class, 'Akses ditolak: Anda belum mengklaim promo ini.');
});

// 🧪 SKENARIO 5: Sukses Menggunakan Promo Kemerdekaan (SOLHER17)
it('applies SOLHER17 correctly and marks the claim as used', function () {
    // 1. Suntikkan data bahwa user SUDAH input email di popup
    PromoClaim::forceCreate([
        'email' => $this->user->email,
        'promo_code' => 'SOLHER17',
        'discount_value' => 500000,
        'expires_at' => now()->addDays(1),
        'is_used' => false
    ]);

    // 2. Siapkan keranjang dengan Tas harga 2 Juta
    $product = Product::forceCreate([
        'name' => 'Tas Juara',
        'code' => 'BAG-05',
        'price' => 2000000,
        'stock' => 10,
        'weight' => 1000,
        'category_id' => $this->bagCategory->id
    ]);

    Cart::forceCreate([
        'user_id' => $this->user->id,
        'product_id' => $product->id,
        'quantity' => 1
    ]);

    $cartItems = Cart::with('product.category')->where('user_id', $this->user->id)->get();

    $request = new Request([
        'currency' => 'IDR',
        'promo_code' => 'SOLHER17',
        'shipping_method' => 'free',
    ]);

    // 3. Kita "Mock" waktu agar sistem mengira sekarang tanggal 17 Agustus
    \Carbon\Carbon::setTestNow('2026-08-17 10:00:00');

    // Act
    $result = $this->action->execute($this->user, $cartItems, $request, $this->promoService);

    // 4. Assert / Validasi Akhir
    $claim = PromoClaim::where('email', $this->user->email)->first();

    // 17% dari 2.000.000 adalah 340.000
    expect($result['promoDiscountAmount'])->toBe(340000)
        ->and($result['appliedPromoCode'])->toBe('SOLHER17')
        ->and($claim->is_used)->toBeTruthy(); // Pastikan database ditandai terpakai
});
