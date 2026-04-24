<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CartControllerTest extends TestCase
{
    // Gunakan DatabaseTransactions agar DB MySQL Anda tidak hancur!
    use DatabaseTransactions;

    protected $user;
    protected $category;
    protected $productA;
    protected $productB;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Buat Data User (Manual, tanpa Factory yang mungkin bermasalah)
        $this->user = User::create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'johndoe_cart_' . \Str::random(5) . '@example.com',
            'password' => bcrypt('password123'),
            'usertype' => 'user',
            'is_membership' => false,
            'point' => 0,
        ]);

        // 2. Buat Kategori
        $this->category = Category::create([
            'category_code' => 'CAT-' . \Str::random(5),
            'category_name' => 'Test Bags',
        ]);

        // 3. Buat Produk A (Stock 10, ada diskon)
        $this->productA = Product::create([
            'code' => 'BAG-A-' . \Str::random(5),
            'name' => 'Leather Bag A',
            'category_id' => $this->category->id,
            'price' => 500000,
            'discount_price' => 450000, // Harga diskon harus dipakai untuk perhitungan Gross Amount
            'stock' => 10,
            'weight' => 1000,
            'status' => 'active',
        ]);

        // 4. Buat Produk B (Stock 5, tanpa diskon)
        $this->productB = Product::create([
            'code' => 'BAG-B-' . \Str::random(5),
            'name' => 'Leather Bag B',
            'category_id' => $this->category->id,
            'price' => 200000,
            'discount_price' => null,
            'stock' => 5,
            'weight' => 800,
            'status' => 'active',
        ]);
    }

    /**
     * TEST 1: Menambahkan produk pertama kali ke keranjang
     */
    public function test_it_can_add_new_item_to_cart()
    {
        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/carts', [
                             'product_id' => $this->productA->id,
                             'quantity' => 2,
                             'color' => 'Black'
                         ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['message', 'cart_id']);

        $this->assertDatabaseHas('carts', [
            'user_id' => $this->user->id,
            'product_id' => $this->productA->id,
            'color' => 'Black',
            'quantity' => 2,
            'gross_amount' => 900000 // 2 * 450.000 (Harga diskon)
        ]);
    }

    /**
     * TEST 2: Menambahkan produk yang sama DENGAN WARNA YANG SAMA (Harus Di-Merge / Quantity Bertambah)
     */
    public function test_it_merges_quantity_if_product_and_color_are_same()
    {
        // Masukkan dulu 2 barang warna hitam
        Cart::create([
            'user_id' => $this->user->id,
            'product_id' => $this->productA->id,
            'color' => 'Black',
            'quantity' => 2,
            'gross_amount' => 900000
        ]);

        // User tambah 3 barang lagi dengan warna hitam yang sama
        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/carts', [
                             'product_id' => $this->productA->id,
                             'quantity' => 3,
                             'color' => 'Black'
                         ]);

        $response->assertStatus(200);

        // Pastikan baris di tabel cart tetap 1, tapi quantity menjadi 5
        $this->assertDatabaseHas('carts', [
            'user_id' => $this->user->id,
            'product_id' => $this->productA->id,
            'color' => 'Black',
            'quantity' => 5,
            'gross_amount' => 2250000 // 5 * 450.000
        ]);
    }

    /**
     * TEST 3: Menambahkan produk yang sama TAPI WARNA BERBEDA (Harus Jadi Baris Baru di DB)
     */
    public function test_it_creates_new_row_if_product_is_same_but_color_is_different()
    {
        // Masukkan warna hitam
        Cart::create([
            'user_id' => $this->user->id,
            'product_id' => $this->productA->id,
            'color' => 'Black',
            'quantity' => 2,
            'gross_amount' => 900000
        ]);

        // User tambah barang yang sama tapi warna Brown
        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/carts', [
                             'product_id' => $this->productA->id,
                             'quantity' => 1,
                             'color' => 'Brown'
                         ]);

        $response->assertStatus(200);

        // Pastikan di database sekarang ada 2 baris (Black dan Brown)
        $this->assertDatabaseHas('carts', [
            'user_id' => $this->user->id,
            'product_id' => $this->productA->id,
            'color' => 'Black',
            'quantity' => 2,
        ]);

        $this->assertDatabaseHas('carts', [
            'user_id' => $this->user->id,
            'product_id' => $this->productA->id,
            'color' => 'Brown',
            'quantity' => 1,
        ]);
    }

    /**
     * TEST 4: Validasi gagal jika quantity melebihi sisa stock di database
     */
    public function test_it_rejects_if_requested_quantity_exceeds_stock()
    {
        // Stock produk B hanya 5. Kita coba minta 6.
        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/carts', [
                             'product_id' => $this->productB->id,
                             'quantity' => 6,
                         ]);

        $response->assertStatus(422)
                 ->assertJson(['message' => 'Quantity exceeds available stock!']);
    }

    /**
     * TEST 5: Validasi gagal jika akumulasi (keranjang lama + request baru) melebihi stock
     */
    public function test_it_rejects_if_accumulated_quantity_exceeds_stock()
    {
        // Masukkan 3 barang ke keranjang (Stock produk B = 5)
        Cart::create([
            'user_id' => $this->user->id,
            'product_id' => $this->productB->id,
            'quantity' => 3,
            'gross_amount' => 600000
        ]);

        // Minta 3 lagi (Total 6. Padahal stock cuma 5).
        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/carts', [
                             'product_id' => $this->productB->id,
                             'quantity' => 3,
                         ]);

        // Harus gagal!
        $response->assertStatus(422)
                 ->assertJson(['message' => 'Quantity exceeds available stock!']);
    }

    /**
     * TEST 6: User dapat mengupdate (mengubah) quantity keranjang langsung
     */
    public function test_it_can_update_cart_quantity()
    {
        $cart = Cart::create([
            'user_id' => $this->user->id,
            'product_id' => $this->productB->id,
            'quantity' => 1,
            'gross_amount' => 200000
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
                         ->putJson("/api/carts/{$cart->id}", [
                             'quantity' => 4
                         ]);

        $response->assertStatus(200);

        // Pastikan quantity dan gross_amount diperbarui di database
        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'quantity' => 4,
            'gross_amount' => 800000 // 4 * 200.000
        ]);
    }

    /**
     * TEST 7: User dapat menghapus item dari keranjang
     */
    public function test_it_can_remove_item_from_cart()
    {
        $cart = Cart::create([
            'user_id' => $this->user->id,
            'product_id' => $this->productA->id,
            'quantity' => 1,
            'gross_amount' => 450000
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
                         ->deleteJson("/api/carts/{$cart->id}");

        $response->assertStatus(200)
                 ->assertJson(['message' => 'Item removed']);

        $this->assertDatabaseMissing('carts', [
            'id' => $cart->id
        ]);
    }
}
