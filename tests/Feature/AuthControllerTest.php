<?php

namespace Tests\Feature;

use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    // Menggunakan DatabaseTransactions agar data palsu langsung dihapus setelah tes selesai
    use DatabaseTransactions;

    protected $user;
    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolasi Storage dan Mail agar tidak bocor ke Production
        Storage::fake('public');
        Mail::fake();

        // 1. Buat User Biasa
        $this->user = User::create([
            'first_name' => 'Regular',
            'last_name' => 'Customer',
            'email' => 'customer_auth_' . \Str::random(5) . '@solher.com',
            'password' => bcrypt('password123'),
            'usertype' => 'user',
            'is_membership' => false,
            'point' => 0,
        ]);

        // 2. Buat Staf (Superadmin)
        $this->admin = User::create([
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'admin_auth_' . \Str::random(5) . '@solher.com',
            'password' => bcrypt('adminpass123'),
            'usertype' => 'superadmin',
            'is_membership' => false,
            'point' => 0,
        ]);
    }

    /**
     * TEST 1: REGISTRASI & OTOMATISASI SUBSCRIBER
     */
    public function test_registration_auto_links_with_existing_subscriber()
    {
        $testEmail = 'new_customer_' . \Str::random(5) . '@test.com';

        // Skenario: User ini sebelumnya sudah langganan newsletter (guest)
        $subscriber = Subscriber::create([
            'email' => $testEmail,
            'is_active' => true,
            'is_registered' => false,
        ]);

        // Aksi: User mendaftar akun
        $response = $this->postJson('/api/register', [
            'first_name' => 'New',
            'last_name' => 'User',
            'email' => $testEmail,
            'password' => 'secret1234',
        ]);

        $response->assertStatus(201)
                 ->assertJsonFragment(['message' => 'User berhasil didaftarkan']);

        // Verifikasi: User baru harus memiliki is_subscribed = 1
        $this->assertDatabaseHas('users', [
            'email' => $testEmail,
            'is_subscribed' => 1,
        ]);

        // Verifikasi: Data di tabel subscriber harus berubah menjadi is_registered = 1
        $subscriber->refresh();
        $this->assertTrue((bool) $subscriber->is_registered);
    }

    /**
     * TEST 2: PEMISAHAN PORTAL LOGIN (ROLE-BASED ACCESS CONTROL)
     */
    public function test_strict_role_separation_on_login_portals()
    {
        // Skenario A: Admin mencoba login di portal User Biasa (Harus DITOLAK)
        $response1 = $this->postJson('/api/login', [
            'email' => $this->admin->email,
            'password' => 'adminpass123',
        ]);
        $response1->assertStatus(401)
                  ->assertJson(['message' => 'Email atau Password salah.']); // Pesan disamarkan demi keamanan

        // Skenario B: User biasa mencoba login di portal Admin (Harus DITOLAK)
        // Asumsi rute admin login Anda adalah /api/admin/login
        $response2 = $this->postJson('/api/admin/login', [
            'email' => $this->user->email,
            'password' => 'password123',
        ]);
        $response2->assertStatus(401)
                  ->assertJson(['message' => 'Akses ditolak. Email/Password salah atau Anda tidak memiliki akses ke panel ini.']);

        // Skenario C: Karyawan Gudang mencoba login di portal Admin (Harus DITERIMA)
        $gudang = User::create([
            'first_name' => 'Staf',
            'last_name' => 'Gudang',
            'email' => 'gudang_' . \Str::random(5) . '@solher.com',
            'password' => bcrypt('gudang123'),
            'usertype' => 'gudang',
        ]);

        $response3 = $this->postJson('/api/admin/login', [
            'email' => $gudang->email,
            'password' => 'gudang123',
        ]);
        $response3->assertStatus(200)
                  ->assertJsonStructure(['access_token', 'user']);
    }

    /**
     * TEST 3: UPDATE FOTO PROFIL (HAPUS FILE LAMA & UPLOAD BARU)
     */
    public function test_update_profile_image_replaces_old_file()
    {
        // 1. Berikan user foto lama terlebih dahulu
        $oldImagePath = 'profiles/' . \Str::random(10) . '.jpg';
        Storage::disk('public')->put($oldImagePath, 'dummy content');

        $this->user->update([
            'profile_image' => url(Storage::url($oldImagePath))
        ]);
        Storage::disk('public')->assertExists($oldImagePath);

        // 2. Siapkan foto baru
        $newFakeImage = UploadedFile::fake()->image('new_avatar.jpg');

        // 3. Eksekusi API Update Image
        // Asumsi rute: /api/profile/image
        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/profile/image', [
                             'image' => $newFakeImage
                         ]);

        $response->assertStatus(200);

        // 4. Verifikasi bahwa file lama BENAR-BENAR TERHAPUS dari disk
        Storage::disk('public')->assertMissing($oldImagePath);

        // 5. Verifikasi database memiliki URL foto yang baru
        $this->user->refresh();
        $this->assertStringContainsString('profiles/', $this->user->profile_image);
        $this->assertStringNotContainsString($oldImagePath, $this->user->profile_image);
    }

    /**
     * TEST 4: UPDATE PASSWORD (VALIDASI PASSWORD LAMA)
     */
    public function test_user_can_update_password_if_old_password_matches()
    {
        // A. Gagal karena password lama salah
        $responseFail = $this->actingAs($this->user, 'sanctum')
                             ->postJson('/api/profile/password', [
                                 'old_password' => 'wrongpassword',
                                 'password' => 'newpassword123',
                                 'password_confirmation' => 'newpassword123',
                             ]);

        $responseFail->assertStatus(401)
                     ->assertJson(['message' => 'Password lama tidak sesuai']);

        // B. Sukses karena password lama benar
        $responseSuccess = $this->actingAs($this->user, 'sanctum')
                              ->postJson('/api/profile/password', [
                                  'old_password' => 'password123',
                                  'password' => 'newpassword123',
                                  'password_confirmation' => 'newpassword123',
                              ]);

        $responseSuccess->assertStatus(200);

        // C. Verifikasi bisa login dengan password baru
        $this->user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $this->user->password));
    }

    /**
     * TEST 5: ALUR FORGOT PASSWORD (OTP GENERATION & MAIL DISPATCH)
     */
    public function test_forgot_password_generates_otp_and_sends_email()
    {
        // Eksekusi permintaan OTP
        $response = $this->postJson('/api/password/forgot', [
            'email' => $this->user->email
        ]);

        $response->assertStatus(200)
                 ->assertJson(['message' => 'Verification code sent to your email.']);

        // Verifikasi Email benar-benar masuk antrean untuk dikirim
        Mail::assertSent(\App\Mail\ResetPasswordCodeMail::class, function ($mail) {
            return $mail->hasTo($this->user->email);
        });

        // Verifikasi ada token OTP yang tersimpan di database
        $this->assertDatabaseHas('password_reset_codes', [
            'email' => $this->user->email,
        ]);
    }
}
