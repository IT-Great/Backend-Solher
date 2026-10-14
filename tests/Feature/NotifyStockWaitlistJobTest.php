<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Product;
use App\Models\Category;
use App\Models\StockWaitlist;
use App\Jobs\NotifyStockWaitlistJob;
use App\Mail\ProductBackInStockMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class NotifyStockWaitlistJobTest extends TestCase
{
    // Menggunakan DatabaseTransactions agar tidak mengotori MySQL
    use DatabaseTransactions;

    protected $category;
    protected $productLimited;
    protected $productPlentiful;
    protected $heritageUser;
    protected $regularUser;
    protected $guestEmail;

    protected function setUp(): void
    {
        parent::setUp();

        // 👇 1. Buat Kategori Dummy Terlebih Dahulu (Solusi Error 1452) 👇
        $this->category = Category::forceCreate([
            'name' => 'Test Category',
            'code' => 'CAT-' . \Str::random(5),
        ]);

        // 2. Persiapan Data Produk (Sekarang menggunakan category_id)
        $this->productLimited = Product::forceCreate([
            'name' => 'Limited Edition Bag',
            'price' => 5000000,
            'stock' => 3, // <= 5 (Akan memicu algoritma Priority VIP)
            'category_id' => $this->category->id, // 👈 Disisipkan di sini
        ]);

        $this->productPlentiful = Product::forceCreate([
            'name' => 'Standard Bag',
            'price' => 1000000,
            'stock' => 50, // > 5 (Akan dirilis ke semua orang)
            'category_id' => $this->category->id, // 👈 Disisipkan di sini
        ]);

        // 3. Persiapan Data User (VIP, Reguler, dan Guest)
        $this->heritageUser = User::forceCreate([
            'first_name' => 'VIP',
            'last_name' => 'Client',
            'email' => 'vip_' . \Str::random(5) . '@test.com',
            'password' => bcrypt('password123'),
            'usertype' => 'user',
            'is_membership' => true,
            'point' => 12000, // VIP Héritage
        ]);

        $this->regularUser = User::forceCreate([
            'first_name' => 'Regular',
            'last_name' => 'Client',
            'email' => 'regular_' . \Str::random(5) . '@test.com',
            'password' => bcrypt('password123'),
            'usertype' => 'user',
            'is_membership' => true,
            'point' => 500, // Hanya Muse biasa
        ]);

        $this->guestEmail = 'guest_' . \Str::random(5) . '@test.com';
    }

    /**
     * TEST 1: Saat stok melimpah (> 5), semua orang di daftar tunggu akan dikabari sekaligus.
     */
    public function test_it_notifies_everyone_if_stock_is_plentiful()
    {
        Mail::fake(); // Tahan pengiriman email sungguhan

        // Daftarkan ke waitlist
        StockWaitlist::create(['product_id' => $this->productPlentiful->id, 'email' => $this->heritageUser->email, 'user_id' => $this->heritageUser->id]);
        StockWaitlist::create(['product_id' => $this->productPlentiful->id, 'email' => $this->regularUser->email, 'user_id' => $this->regularUser->id]);
        StockWaitlist::create(['product_id' => $this->productPlentiful->id, 'email' => $this->guestEmail]);

        // Eksekusi Job
        (new NotifyStockWaitlistJob($this->productPlentiful->id))->handle();

        // Pastikan email terkirim ke ketiganya
        Mail::assertSent(ProductBackInStockMail::class, 3);
        Mail::assertSent(ProductBackInStockMail::class, function ($mail) {
            return $mail->hasTo($this->heritageUser->email);
        });
        Mail::assertSent(ProductBackInStockMail::class, function ($mail) {
            return $mail->hasTo($this->guestEmail);
        });

        // Pastikan is_notified berubah jadi true
        $this->assertEquals(0, StockWaitlist::where('product_id', $this->productPlentiful->id)->where('is_notified', false)->count());
    }

    /**
     * TEST 2: Saat stok terbatas (<= 5), email HANYA dikirim ke VIP Héritage,
     * dan Job akan dijadwalkan ulang untuk pelanggan reguler.
     */
    public function test_it_prioritizes_heritage_members_when_stock_is_limited()
    {
        Mail::fake();
        Queue::fake(); // Tahan Dispatch ulang ke Queue agar bisa dilacak

        StockWaitlist::create(['product_id' => $this->productLimited->id, 'email' => $this->heritageUser->email, 'user_id' => $this->heritageUser->id]);
        StockWaitlist::create(['product_id' => $this->productLimited->id, 'email' => $this->regularUser->email, 'user_id' => $this->regularUser->id]);

        // Eksekusi Job (Fase 1: Priority = true)
        (new NotifyStockWaitlistJob($this->productLimited->id, true))->handle();

        // Pastikan HANYA VIP yang menerima email
        Mail::assertSent(ProductBackInStockMail::class, 1);
        Mail::assertSent(ProductBackInStockMail::class, function ($mail) {
            return $mail->hasTo($this->heritageUser->email);
        });

        // Pastikan email Reguler TIDAK dikirim
        Mail::assertNotSent(ProductBackInStockMail::class, function ($mail) {
            return $mail->hasTo($this->regularUser->email);
        });

        // Pastikan job tertunda (delay) ke Queue untuk fase reguler (isPriorityPhase = false)
        Queue::assertPushed(NotifyStockWaitlistJob::class, function ($job) {
            return $job->productId === $this->productLimited->id && $job->isPriorityPhase === false;
        });

        // Pastikan is_notified berubah untuk VIP, tapi false untuk Reguler
        $this->assertTrue((bool) StockWaitlist::where('email', $this->heritageUser->email)->first()->is_notified);
        $this->assertFalse((bool) StockWaitlist::where('email', $this->regularUser->email)->first()->is_notified);
    }

    /**
     * TEST 3: Jika di antrean TIDAK ADA VIP, sistem harus cukup pintar
     * untuk mem-bypass penundaan dan langsung mengirimnya ke reguler.
     */
    public function test_it_bypasses_priority_phase_if_no_heritage_members_in_waitlist()
    {
        Mail::fake();
        Queue::fake();

        // Cuma reguler & guest yang antre (stok terbatas)
        StockWaitlist::create(['product_id' => $this->productLimited->id, 'email' => $this->regularUser->email, 'user_id' => $this->regularUser->id]);
        StockWaitlist::create(['product_id' => $this->productLimited->id, 'email' => $this->guestEmail]);

        (new NotifyStockWaitlistJob($this->productLimited->id, true))->handle();

        // Harus langsung ngirim ke 2 orang reguler tersebut tanpa menunggu delay
        Mail::assertSent(ProductBackInStockMail::class, 2);

        $this->assertTrue((bool) StockWaitlist::where('email', $this->regularUser->email)->first()->is_notified);
        $this->assertTrue((bool) StockWaitlist::where('email', $this->guestEmail)->first()->is_notified);
    }
}
