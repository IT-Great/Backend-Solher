<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
// use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\DatabaseTransactions; // <-- PERBAIKAN DI SINI
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use DatabaseTransactions, WithFaker;

    protected $admin;
    protected $category;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Isolasi Storage & Cache agar tidak mengotori Production
        Storage::fake('public');
        Cache::flush();

        // 2. Siapkan Data Dasar
        $this->admin = User::factory()->create(['usertype' => 'superadmin']);
        $this->category = Category::factory()->create();
    }

    /**
     * @dataProvider edgeCaseProductProvider
     * SIKSAAN EDGE-CASES: Fungsi ini akan menjalankan test berulang kali dengan payload yang berbeda-beda
     */
    public function test_product_validation_with_extreme_edge_cases($payloadOverrides, $expectedStatus, $expectedErrors)
    {
        $payload = array_merge([
            'code' => 'TEST-' . rand(1000, 9999),
            'name' => 'Normal Product',
            'category_id' => $this->category->id,
            'price' => 150000,
            'stock' => 10,
            'weight' => 1000,
        ], $payloadOverrides);

        $response = $this->actingAs($this->admin, 'sanctum')
                         ->postJson('/api/admin/products', $payload);

        $response->assertStatus($expectedStatus);

        if ($expectedStatus === 422) {
            $response->assertJsonValidationErrors($expectedErrors);
        }
    }

    /**
     * DATA PROVIDER: Mensimulasikan ratusan skenario serangan user
     */
    public static function edgeCaseProductProvider()
    {
        return [
            'Harga minus' => [['price' => -50000], 422, ['price']],
            'Berat nol (Minimal 1)' => [['weight' => 0], 422, ['weight']],
            'Berat huruf (Tipe data salah)' => [['weight' => 'Seribu Gram'], 422, ['weight']],
            'Nama kosong' => [['name' => ''], 422, ['name']],
            'Dimensi minus (Panjang)' => [['length' => -5], 422, ['length']],
            'Warna bukan array (Dikirim sebagai string)' => [['color' => 'Merah'], 422, ['color']],
            'SQL Injection di nama' => [['name' => "DROP TABLE products;"], 201, []], // XSS/SQLi harusnya lolos validasi tapi ditangani oleh ORM Eloquent
            'Teks sangat panjang (Material)' => [['material' => str_repeat('A', 256)], 422, ['material']], // Maks 255
        ];
    }

    /**
     * PENGUJIAN: Fitur konversi Image ke WebP & Nullable strings handler
     */
    public function test_it_optimizes_image_to_webp_and_converts_empty_strings_to_null()
    {
        // Simulasi Upload Gambar Valid (10x10 pixel)
        $fakeImage = UploadedFile::fake()->image('bag.jpg', 10, 10);

        $payload = [
            'code' => 'BAG-001',
            'name' => 'Premium Leather Bag',
            'category_id' => $this->category->id,
            'price' => 500000,
            'stock' => 5,
            'weight' => 1200,
            'image' => $fakeImage,
            'discount_price' => "", // Edge case: Kosong dari form data
            'material' => "null",   // Edge case: Teks "null" dari Javascript
        ];

        $response = $this->actingAs($this->admin, 'sanctum')
                         ->postJson('/api/admin/products', $payload);

        $response->assertStatus(201);

        // 1. Pastikan string "" dan "null" benar-benar berubah menjadi NULL di Database
        $this->assertDatabaseHas('products', [
            'code' => 'BAG-001',
            'discount_price' => null,
            'material' => null,
        ]);

        // 2. Pastikan file tersimpan di disk
        $product = Product::where('code', 'BAG-001')->first();
        $this->assertNotNull($product->image);

        // 3. Pastikan format file telah diubah menjadi .webp oleh Intervention Image
        $this->assertStringContainsString('.webp', $product->image);

        // Ekstrak nama file dari URL untuk dicek di Storage lokal
        $filename = str_replace('/storage/', '', $product->image);
        Storage::disk('public')->assertExists($filename);
    }

    /**
     * PENGUJIAN: Tsunami Cache Stampede Fix (Cache dibersihkan saat CRUD)
     */
    public function test_it_flushes_catalog_cache_on_product_creation_and_deletion()
    {
        // 1. Simpan data palsu ke Cache
        Cache::tags(['catalog'])->put('dummy_key', 'some_data', 86400);
        $this->assertTrue(Cache::tags(['catalog'])->has('dummy_key'));

        // 2. Lakukan transaksi Create Product
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/products', [
            'code' => 'CACHE-001',
            'name' => 'Cache Test',
            'category_id' => $this->category->id,
            'price' => 10000,
            'stock' => 10,
            'weight' => 500,
        ]);

        // 3. Pastikan Cache HANCUR (Flush)
        $this->assertFalse(Cache::tags(['catalog'])->has('dummy_key'));
    }

    /**
     * PENGUJIAN: Force Delete menghapus file fisik di server (Mencegah Hard Disk Penuh)
     */
    public function test_it_removes_physical_image_files_on_force_delete()
    {
        $fakeImagePath = 'products/' . \Str::random(10) . '.webp';

        // Buat file palsu di Storage
        Storage::disk('public')->put($fakeImagePath, 'dummy content');

        $product = Product::factory()->create([
            'category_id' => $this->category->id,
            'image' => '/storage/' . $fakeImagePath
        ]);

        // Pastikan file awalnya ada
        Storage::disk('public')->assertExists($fakeImagePath);

        // Tembak API Force Delete
        $response = $this->actingAs($this->admin, 'sanctum')
                         ->deleteJson("/api/admin/products/{$product->id}/force");

        $response->assertStatus(200);

        // Pastikan Produk hilang dari database
        $this->assertDatabaseMissing('products', ['id' => $product->id]);

        // Pastikan File Fisik juga terhapus!
        Storage::disk('public')->assertMissing($fakeImagePath);
    }
}
