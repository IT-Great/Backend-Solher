<?php

namespace App\Http\Controllers;

use App\Mail\ResetPasswordCodeMail;
use App\Models\Subscriber;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // ========================================================================
        // [BARU] 1. Cek apakah email ini sudah pernah subscribe saat menjadi Guest
        // ========================================================================
        $subscriber = Subscriber::where('email', $request->email)->first();
        $isSubscribed = $subscriber ? true : false;

        // 2. Buat User baru
        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'is_subscribed' => $isSubscribed, // <--- Set status otomatis berdasarkan pengecekan di atas
        ]);

        // ========================================================================
        // [BARU] 3. Jika dia ada di tabel subscribers, tandai bahwa dia kini Registered
        // ========================================================================
        if ($subscriber) {
            $subscriber->update(['is_registered' => true]);
        }

        return response()->json([
            'message' => 'User berhasil didaftarkan',
            'user' => $user,
        ], 201);
    }

    // public function login(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'email'         => 'required|email',
    //         'password'      => 'required',
    //         'captcha_token' => 'required|string', // [BARU] Validasi token captcha
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json($validator->errors(), 422);
    //     }

    //     // [BARU] Verifikasi CAPTCHA ke Google
    //     $captchaResponse = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
    //         'secret'   => env('RECAPTCHA_SECRET_KEY'),
    //         'response' => $request->captcha_token,
    //         'remoteip' => $request->ip()
    //     ]);

    //     $captchaResult = $captchaResponse->json();

    //     // Di v3, kita juga mengecek 'score'. Standard amannya adalah di atas 0.5
    //     if (!$captchaResult['success'] || $captchaResult['score'] < 0.5) {
    //         // Opsional: Log aktivitas bot jika diperlukan
    //         Log::warning('Bot detected during login. Score: ' . ($captchaResult['score'] ?? 'null'));

    //         return response()->json([
    //             'message' => 'Sistem mendeteksi aktivitas mencurigakan. Login ditolak.'
    //         ], 422);
    //     }

    //     $user = User::where('email', $request->email)->first();

    //     if (
    //         !$user ||
    //         !Hash::check($request->password, $user->password) ||
    //         $user->usertype !== 'user'
    //     ) {
    //         return response()->json([
    //             'message' => 'Email atau Password salah.'
    //         ], 401);
    //     }

    //     $token = $user->createToken('auth_token')->plainTextToken;

    //     return response()->json([
    //         'message'      => 'Login Berhasil',
    //         'access_token' => $token,
    //         'token_type'   => 'Bearer',
    //         'user'         => $user
    //     ], 200);
    // }

    public function login(Request $request)
    {
        // 1. Susun aturan dasar
        $rules = [
            'email' => 'required|email',
            'password' => 'required',
        ];

        // 2. Wajibkan captcha HANYA jika bukan di environment testing
        if (! app()->environment('testing')) {
            $rules['captcha_token'] = 'required|string';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // 3. Eksekusi pengecekan ke Google HANYA jika bukan di environment testing
        if (! app()->environment('testing')) {
            $captchaResponse = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => env('RECAPTCHA_SECRET_KEY'),
                'response' => $request->captcha_token,
                'remoteip' => $request->ip(),
            ]);

            $captchaResult = $captchaResponse->json();

            if (! $captchaResult['success'] || ($captchaResult['score'] ?? 0) < 0.5) {
                Log::warning('Bot detected during login. Score: '.($captchaResult['score'] ?? 'null'));

                return response()->json([
                    'message' => 'Sistem mendeteksi aktivitas mencurigakan. Login ditolak.',
                ], 422);
            }
        }

        // ==========================================
        // Logika utama aplikasi tetap berjalan normal
        // ==========================================
        $user = User::where('email', $request->email)->first();

        if (
            ! $user ||
            ! Hash::check($request->password, $user->password) ||
            $user->usertype !== 'user'
        ) {
            return response()->json([
                'message' => 'Email atau Password salah.',
            ], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login Berhasil',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ], 200);
    }

    // public function adminLogin(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'email'         => 'required|email',
    //         'password'      => 'required',
    //         'captcha_token' => 'required|string',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json($validator->errors(), 422);
    //     }

    //     // [BARU] Verifikasi CAPTCHA ke Google
    //     $captchaResponse = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
    //         'secret'   => env('RECAPTCHA_SECRET_KEY'),
    //         'response' => $request->captcha_token,
    //         'remoteip' => $request->ip()
    //     ]);

    //     $captchaResult = $captchaResponse->json();

    //     // Di v3, kita juga mengecek 'score'. Standard amannya adalah di atas 0.5
    //     if (!$captchaResult['success'] || $captchaResult['score'] < 0.5) {
    //         // Opsional: Log aktivitas bot jika diperlukan
    //         // Log::warning('Bot detected during login. Score: ' . ($captchaResult['score'] ?? 'null'));

    //         return response()->json([
    //             'message' => 'Sistem mendeteksi aktivitas mencurigakan. Login ditolak.'
    //         ], 422);
    //     }

    //     // if (!$captchaResponse->json('success')) {
    //     //     return response()->json([
    //     //         'message' => 'Validasi CAPTCHA gagal. Silakan centang ulang.'
    //     //     ], 422);
    //     // }

    //     $user = User::where('email', $request->email)
    //         ->whereIn('usertype', ['admin', 'superadmin', 'gudang', 'accounting'])
    //         ->first();

    //     if (!$user || !Hash::check($request->password, $user->password)) {
    //         return response()->json([
    //             'message' => 'Akses ditolak. Email/Password salah atau Anda tidak memiliki akses ke panel ini.'
    //         ], 401);
    //     }

    //     $token = $user->createToken('admin_token')->plainTextToken;

    //     return response()->json([
    //         'message'      => 'Login Berhasil',
    //         'access_token' => $token,
    //         'token_type'   => 'Bearer',
    //         'user'         => $user
    //     ], 200);
    // }

    public function adminLogin(Request $request)
    {
        // 1. Susun aturan dasar
        $rules = [
            'email' => 'required|email',
            'password' => 'required',
        ];

        // 2. Wajibkan captcha HANYA jika bukan di environment testing
        if (! app()->environment('testing')) {
            $rules['captcha_token'] = 'required|string';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // 3. Eksekusi pengecekan ke Google HANYA jika bukan di environment testing
        if (! app()->environment('testing')) {
            $captchaResponse = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => env('RECAPTCHA_SECRET_KEY'),
                'response' => $request->captcha_token,
                'remoteip' => $request->ip(),
            ]);

            $captchaResult = $captchaResponse->json();

            // 👇 TAMBAHKAN LOG INI UNTUK DEBUGGING 👇
            if (! $captchaResult['success']) {
                Log::error('reCAPTCHA Failed: '.json_encode($captchaResult));
            }

            // Di v3, kita juga mengecek 'score'. Standard amannya adalah di atas 0.5
            if (! $captchaResult['success'] || ($captchaResult['score'] ?? 0) < 0.5) {
                // Opsional: Log aktivitas bot jika diperlukan
                Log::warning('Bot detected during admin login. Score: ' . ($captchaResult['score'] ?? 'null'));

                return response()->json([
                    'message' => 'Sistem mendeteksi aktivitas mencurigakan. Login ditolak.',
                ], 422);
            }
        }

        // ==========================================
        // Logika utama aplikasi tetap berjalan normal
        // ==========================================
        $user = User::where('email', $request->email)
            ->whereIn('usertype', ['admin', 'superadmin', 'gudang', 'accounting', 'cs'])
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Akses ditolak. Email/Password salah atau Anda tidak memiliki akses ke panel ini.',
            ], 401);
        }

        $token = $user->createToken('admin_token')->plainTextToken;

        return response()->json([
            'message' => 'Login Berhasil',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ], 200);
    }

    // 1. Update Nama & Email
    public function updateProfileInfo(Request $request)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,'.$user->id,
            'phone' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user->update($request->only('first_name', 'last_name', 'email', 'phone'));

        return response()->json(['message' => 'Info profil diperbarui', 'user' => $user]);
    }

    public function updateImage(Request $request)
    {
        Log::info('Update profile image started', [
            'user_id' => $request->user()->id,
        ]);

        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        $user = $request->user();

        try {
            // [PERBAIKAN] Jika ada foto lama, hapus dari Local Storage
            if ($user->profile_image) {
                // Bersihkan URL agar hanya menyisakan path relatifnya saja
                $oldPath = str_replace(url(Storage::url('')), '', $user->profile_image);
                $oldPath = ltrim(str_replace('/storage/', '', $oldPath), '/');

                Log::info('Deleting old profile image', [
                    'user_id' => $user->id,
                    'old_path' => $oldPath,
                ]);

                Storage::disk('public')->delete($oldPath);
            }

            // [PERBAIKAN] Upload foto baru ke Local Storage (disk 'public' -> storage/app/public/profiles)
            $path = $request->file('image')->store('profiles', 'public');

            Log::info('New profile image uploaded', [
                'user_id' => $user->id,
                'new_path' => $path,
            ]);

            // [PERBAIKAN] Karena kita tidak memakai Accessor di User Model, kita simpan URL penuhnya langsung
            $user->profile_image = url(Storage::url($path));
            $user->save();

            $user = $user->fresh();

            Log::info('Profile image updated successfully', [
                'user_id' => $user->id,
                'profile_image_url' => $user->profile_image,
            ]);

            return response()->json([
                'message' => 'Foto profil diperbarui',
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update profile image', [
                'user_id' => $user->id ?? null,
                'error_message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Gagal memperbarui foto profil',
            ], 500);
        }
    }

    // 3. Update Password
    public function updatePassword(Request $request)
    {
        $request->validate([
            'old_password' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (! Hash::check($request->old_password, $user->password)) {
            return response()->json(['message' => 'Password lama tidak sesuai'], 401);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json(['message' => 'Password berhasil diubah']);
    }

    // Ambil semua daftar user biasa
    public function getAllUsers()
    {
        // Mengambil user dengan usertype 'user' saja
        $users = User::where('usertype', 'user')->latest()->get(); //

        return response()->json($users, 200);
    }

    // Ambil detail satu user beserta alamatnya
    public function getUserDetail($id)
    {
        // Memuat user beserta relasi addresses yang sudah kita buat sebelumnya
        $user = User::with('addresses')->findOrFail($id); //

        return response()->json($user, 200);
    }

    public function updateAdminProfileInfo(Request $request)
    {
        $admin = $request->user();

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,'.$admin->id,
            'phone' => 'nullable|string|max:20', // [BARU] Tambahkan validasi phone
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // [BARU] Sertakan phone saat update
        $admin->update($request->only('first_name', 'last_name', 'email', 'phone'));

        return response()->json([
            'message' => 'Admin profile updated successfully',
            'admin' => $admin,
        ]);
    }

    public function updateAdminImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg',
        ]);

        $admin = $request->user();

        try {
            // [PERBAIKAN] Jika ada foto lama, hapus dari Local Storage
            if ($admin->profile_image) {
                // Bersihkan URL agar hanya menyisakan path relatifnya saja
                $oldPath = str_replace(url(Storage::url('')), '', $admin->profile_image);
                $oldPath = ltrim(str_replace('/storage/', '', $oldPath), '/');

                Storage::disk('public')->delete($oldPath);
            }

            // [PERBAIKAN] Upload foto baru ke Local Storage
            $path = $request->file('image')->store('profiles', 'public');

            // [PERBAIKAN] Simpan URL penuhnya ke database
            $admin->profile_image = url(Storage::url($path));
            $admin->save();

            return response()->json([
                'message' => 'Admin photo updated',
                'admin' => $admin->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update admin photo',
            ], 500);
        }
    }

    public function updateAdminPassword(Request $request)
    {
        $request->validate([
            'old_password' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $admin = $request->user();

        if (! Hash::check($request->old_password, $admin->password)) {
            return response()->json([
                'message' => 'Old password does not match',
            ], 401);
        }

        $admin->password = Hash::make($request->password);
        $admin->save();

        return response()->json([
            'message' => 'Password updated successfully',
        ]);
    }

    public function toggleMembership(Request $request)
    {
        $user = $request->user();
        $request->validate([
            'is_membership' => 'required|boolean',
        ]);

        $user->update([
            'is_membership' => $request->is_membership,
        ]);

        return response()->json(['user' => $user, 'message' => 'Membership status updated!']);
    }

    // --- 1. MENGIRIM KODE OTP KE EMAIL ---
    public function sendResetCode(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json(['message' => 'Email address not found in our system.'], 404);
        }

        // Hapus kode lama jika ada
        DB::table('password_reset_codes')->where('email', $request->email)->delete();

        // Buat 6-digit angka random
        $code = sprintf('%06d', mt_rand(1, 999999));

        DB::table('password_reset_codes')->insert([
            'email' => $request->email,
            'code' => Hash::make($code), // Enkripsi kode di DB untuk keamanan
            'expires_at' => Carbon::now()->addMinutes(15),
            'created_at' => Carbon::now(),
        ]);

        try {
            Mail::to($request->email)->send(new ResetPasswordCodeMail($code));

            return response()->json(['message' => 'Verification code sent to your email.']);
        } catch (\Exception $e) {
            Log::error('Failed to send reset code: '.$e->getMessage());

            return response()->json(['message' => 'Failed to send email. Please try again later.'], 500);
        }
    }

    // --- 2. MEMVALIDASI KODE OTP ---
    public function verifyResetCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|digits:6',
        ]);

        $resetData = DB::table('password_reset_codes')
            ->where('email', $request->email)
            ->first();

        if (! $resetData) {
            return response()->json(['message' => 'Invalid or expired verification code.'], 400);
        }

        if (Carbon::now()->greaterThan($resetData->expires_at)) {
            DB::table('password_reset_codes')->where('email', $request->email)->delete();

            return response()->json(['message' => 'Verification code has expired.'], 400);
        }

        if (! Hash::check($request->code, $resetData->code)) {
            return response()->json(['message' => 'Incorrect verification code.'], 400);
        }

        return response()->json(['message' => 'Code verified successfully.']);
    }

    // --- 3. MERESET PASSWORD BERDASARKAN OTP YANG VALID ---
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|digits:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $resetData = DB::table('password_reset_codes')
            ->where('email', $request->email)
            ->first();

        if (! $resetData || ! Hash::check($request->code, $resetData->code) || Carbon::now()->greaterThan($resetData->expires_at)) {
            return response()->json(['message' => 'Invalid session or code expired.'], 400);
        }

        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();

        // Hapus token reset setelah sukses digunakan
        DB::table('password_reset_codes')->where('email', $request->email)->delete();

        return response()->json(['message' => 'Password has been successfully reset.']);
    }

    // ====================================================================
    // FUNGSI FORGOT PASSWORD KHUSUS ADMIN
    // ====================================================================
    public function adminSendResetCode(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        // [PERBAIKAN] Cek apakah email ini milik staf internal (bukan pelanggan biasa)
        $admin = User::where('email', $request->email)
            ->whereIn('usertype', ['admin', 'superadmin', 'gudang', 'accounting', 'cs'])
            ->first();

        if (! $admin) {
            return response()->json(['message' => 'Alamat email tidak ditemukan atau tidak memiliki izin akses.'], 404);
        }

        DB::table('password_reset_codes')->where('email', $request->email)->delete();

        $code = sprintf('%06d', mt_rand(1, 999999));

        DB::table('password_reset_codes')->insert([
            'email' => $request->email,
            'code' => Hash::make($code),
            'expires_at' => Carbon::now()->addMinutes(15),
            'created_at' => Carbon::now(),
        ]);

        try {
            Mail::to($request->email)->send(new ResetPasswordCodeMail($code));

            return response()->json(['message' => 'Kode verifikasi telah dikirim ke email Anda.']);
        } catch (\Exception $e) {
            Log::error('Failed to send admin reset code: '.$e->getMessage());

            return response()->json(['message' => 'Gagal mengirim email. Silakan coba lagi nanti.'], 500);
        }
    }

    public function adminVerifyResetCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|digits:6',
        ]);

        // [PERBAIKAN] Validasi staf
        $admin = User::where('email', $request->email)
            ->whereIn('usertype', ['admin', 'superadmin', 'gudang', 'accounting', 'cs'])
            ->first();
        if (! $admin) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $resetData = DB::table('password_reset_codes')->where('email', $request->email)->first();

        if (! $resetData) {
            return response()->json(['message' => 'Kode verifikasi tidak valid atau telah kedaluwarsa.'], 400);
        }

        if (Carbon::now()->greaterThan($resetData->expires_at)) {
            DB::table('password_reset_codes')->where('email', $request->email)->delete();

            return response()->json(['message' => 'Kode verifikasi telah kedaluwarsa.'], 400);
        }

        if (! Hash::check($request->code, $resetData->code)) {
            return response()->json(['message' => 'Kode verifikasi salah.'], 400);
        }

        return response()->json(['message' => 'Kode berhasil diverifikasi.']);
    }

    public function adminResetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|digits:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // [PERBAIKAN] Validasi staf
        $admin = User::where('email', $request->email)
            ->whereIn('usertype', ['admin', 'superadmin', 'gudang', 'accounting', 'cs'])
            ->first();
        if (! $admin) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $resetData = DB::table('password_reset_codes')->where('email', $request->email)->first();

        if (! $resetData || ! Hash::check($request->code, $resetData->code) || Carbon::now()->greaterThan($resetData->expires_at)) {
            return response()->json(['message' => 'Sesi tidak valid atau kode kedaluwarsa.'], 400);
        }

        $admin->password = Hash::make($request->password);
        $admin->save();

        DB::table('password_reset_codes')->where('email', $request->email)->delete();

        return response()->json(['message' => 'Kata sandi berhasil disetel ulang.']);
    }
}
