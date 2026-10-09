<?php

// namespace App\Http\Controllers;

// use App\Mail\PromoCodeMail;
// use App\Models\PromoClaim;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Auth;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Mail;
// use Illuminate\Support\Str;
// use App\Jobs\SendPromoReminderJob;
// use Carbon\Carbon;

// class PromoController extends Controller
// {
//     public function claim(Request $request)
//     {
//         $request->validate(['email' => 'required|email']);
//         $discountValue = 250000;

//         $exists = PromoClaim::where('email', $request->email)->first();
//         if ($exists) {
//             return response()->json(['message' => 'Email ini sudah mengklaim promo sebelumnya.'], 400);
//         }

//         $code = 'SOLHER-'.strtoupper(Str::random(6));

//         // Set waktu expired 24 jam dari sekarang
//         $expiresAt = now()->addHours(24);

//         try {
//             Mail::to($request->email)->send(new PromoCodeMail($code, $discountValue, $expiresAt));
//         } catch (\Exception $e) {
//             report($e);
//             Log::error('Failed to send promo email to '.$request->email.': '.$e->getMessage());
//             return response()->json(['message' => 'Gagal mengirim email. Pastikan alamat email valid atau coba lagi nanti.'], 500);
//         }

//         PromoClaim::create([
//             'email' => $request->email,
//             'promo_code' => $code,
//             'discount_value' => $discountValue,
//             'expires_at' => $expiresAt,
//         ]);

//         // 👇 [MAGIC HAPPENS HERE] Pemicu Drip Campaign Otomatis 👇
//         // Jadwalkan pengiriman email "Pengingat" tepat 23 jam dari sekarang (1 jam sebelum hangus)
//         SendPromoReminderJob::dispatch($request->email, $code, $discountValue)->delay(now()->addHours(23));

//         return response()->json([
//             'message' => 'Promo berhasil diklaim!',
//             'promo_code' => $code,
//         ]);
//     }

//     public function verify(Request $request)
//     {
//     //     $request->validate(['promo_code' => 'required|string']);
//     //     $user = Auth::user();

//     //     // Standarisasi kapitalisasi dan hapus spasi agar akurat
//     //     $code = strtoupper(trim($request->promo_code));

//     //     // =========================================================================
//     //     // [LOGIKA BARU] OPSI C: VIP MEMBER VOUCHER UNIVERSAL
//     //     // =========================================================================
//     //     if ($code === 'SOLHERMEMBER') {

//     //         // 1. Validasi Status Member
//     //         if (!$user->is_membership) {
//     //             return response()->json(['message' => 'Voucher ini eksklusif hanya untuk VIP Member.'], 400);
//     //         }

//     //         // 2. Validasi Kuota (Satu Kali Seumur Hidup per Akun)
//     //         if ($user->has_used_member_voucher) {
//     //             return response()->json(['message' => 'Anda sudah pernah menggunakan voucher VIP ini sebelumnya.'], 400);
//     //         }

//     //         // Jika lolos, kirimkan nilai diskon mutlak (500rb) ke Frontend
//     //         return response()->json([
//     //             'message' => 'VIP Member Voucher applied!',
//     //             'discount_value' => 500000,
//     //         ], 200);
//     //     }

//     $request->validate([
//             'promo_code' => 'required|string',
//             'cart_items' => 'required|array' // Tambahkan ini agar kita bisa cek apakah ada diskon di cart
//         ]);

//         $user = Auth::user();
//         $code = strtoupper(trim($request->promo_code));
//         $cartItems = $request->cart_items; // Array produk yang dibeli

//         // --- VALIDASI: CEK DISKON BERTUMPUK (Stacking) ---
//         // Jika ada satu saja produk di cart yang sedang diskon aktif, tolak voucher!
//         foreach ($cartItems as $item) {
//             $product = \App\Models\Product::find($item['product_id']);
//             if ($product && $product->discount_price && \App\Models\Product::where('id', $product->id)->first()->discount_price !== null) {
//                  // Cek apakah diskonnya sedang aktif
//                  $now = now();
//                  $start = $product->discount_start_date;
//                  $end = $product->discount_end_date;

//                  $isActive = false;
//                  if ($start && $end) { $isActive = $now->between($start, $end); }
//                  elseif ($start) { $isActive = $now->greaterThanOrEqualTo($start); }
//                  elseif ($end) { $isActive = $now->lessThanOrEqualTo($end); }
//                  else { $isActive = true; } // Jika tidak ada tanggal, dianggap diskon selamanya

//                  if ($isActive) {
//                      return response()->json(['message' => 'Voucher tidak dapat digunakan untuk produk yang sedang diskon.'], 400);
//                  }
//             }
//         }

//         // ================= OPSI C: VIP MEMBER VOUCHER =================
//         if ($code === 'SOLHERMEMBER') {
//             if (!$user->is_membership) return response()->json(['message' => 'Hanya untuk VIP Member.'], 400);
//             if ($user->has_used_member_voucher) return response()->json(['message' => 'Voucher ini sudah pernah digunakan.'], 400);

//             return response()->json(['message' => 'VIP Voucher applied!', 'discount_value' => 500000], 200);
//         }

//         // ================= OPSI D: FIRST ORDER VOUCHER =================
//         if ($code === 'FIRSTORDER') {
//             // Cek apakah user pernah transaksi sebelumnya
//             $hasOrdered = \App\Models\Transaction::where('user_id', $user->id)->where('status', 'completed')->exists();
//             if ($hasOrdered) return response()->json(['message' => 'Voucher ini hanya untuk pembeli pertama.'], 400);

//             // Cek di tabel PromoClaim apakah dia sudah pernah pakai 'FIRSTORDER'
//             $claim = PromoClaim::where('email', $user->email)->where('promo_code', 'FIRSTORDER')->where('is_used', true)->first();
//             if ($claim) return response()->json(['message' => 'Anda sudah pernah menggunakan voucher ini.'], 400);

//             return response()->json(['message' => 'First Order Voucher applied!', 'discount_value' => 250000], 200);
//         }

//         // =========================================================================
//         // [LOGIKA LAMA] PROMO KLAIM EMAIL (NEWSLETTER)
//         // =========================================================================
//         $claim = PromoClaim::where('email', $user->email)
//             ->where('promo_code', $code)
//             ->first();

//         if (! $claim) {
//             return response()->json(['message' => 'Invalid promo code for this email address.'], 404);
//         }

//         // Validasi B: Cek apakah sudah lewat dari 24 jam
//         if (now()->greaterThan($claim->expires_at)) {
//             return response()->json(['message' => 'This promo code has expired.'], 400);
//         }

//         // Validasi C: Cek apakah sudah pernah diredeem
//         if ($claim->is_used) {
//             return response()->json(['message' => 'This promo code has already been used.'], 400);
//         }

//         return response()->json([
//             'message' => 'Promo applied successfully!',
//             'discount_value' => $claim->discount_value,
//         ], 200);
//     }
// }

// namespace App\Http\Controllers;

// use App\Mail\PromoCodeMail;
// use App\Models\PromoClaim;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Auth;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Mail;
// use Illuminate\Support\Str;
// use App\Jobs\SendPromoReminderJob;
// use Carbon\Carbon;

// class PromoController extends Controller
// {
//     public function claim(Request $request)
//     {
//         $request->validate(['email' => 'required|email']);
//         $discountValue = 250000;

//         $exists = PromoClaim::where('email', $request->email)->first();
//         if ($exists) {
//             return response()->json(['message' => 'Email ini sudah mengklaim promo sebelumnya.'], 400);
//         }

//         $code = 'SOLHER-'.strtoupper(Str::random(6));
//         $expiresAt = now()->addHours(24);

//         try {
//             Mail::to($request->email)->send(new PromoCodeMail($code, $discountValue, $expiresAt));
//         } catch (\Exception $e) {
//             report($e);
//             Log::error('Failed to send promo email to '.$request->email.': '.$e->getMessage());
//             return response()->json(['message' => 'Gagal mengirim email. Pastikan alamat email valid atau coba lagi nanti.'], 500);
//         }

//         PromoClaim::create([
//             'email' => $request->email,
//             'promo_code' => $code,
//             'discount_value' => $discountValue,
//             'expires_at' => $expiresAt,
//         ]);

//         SendPromoReminderJob::dispatch($request->email, $code, $discountValue)->delay(now()->addHours(23));

//         return response()->json([
//             'message' => 'Promo berhasil diklaim!',
//             'promo_code' => $code,
//         ]);
//     }

//     public function verify(Request $request)
//     {
//         $request->validate([
//             'promo_code' => 'required|string',
//             'cart_items' => 'required|array'
//         ]);

//         $user = Auth::user();
//         $code = strtoupper(trim($request->promo_code));
//         $cartItems = $request->cart_items;

//         // --- VALIDASI: CEK DISKON BERTUMPUK (Stacking) ---
//         foreach ($cartItems as $item) {
//             $product = \App\Models\Product::with('category')->find($item['product_id']);

//             if ($product && $product->discount_price) {
//                  $now = now();
//                  $start = $product->discount_start_date;
//                  $end = $product->discount_end_date;

//                  $isActive = false;
//                  if ($start && $end) { $isActive = $now->between($start, $end); }
//                  elseif ($start) { $isActive = $now->greaterThanOrEqualTo($start); }
//                  elseif ($end) { $isActive = $now->lessThanOrEqualTo($end); }
//                  else { $isActive = true; }

//                  if ($isActive) {
//                      return response()->json(['message' => 'Voucher tidak dapat digunakan untuk produk yang sedang diskon.'], 400);
//                  }
//             }
//         }

//         // ====================================================================
//         // 👇 VOUCHER SUBSIDI TAS RP 3.4 JUTA (Hanya bisa 1 barang & Harus Tas) 👇
//         // ====================================================================
//         if ($code === 'SOLHOST34') {
//             $totalQuantityInCart = 0;
//             $bagProductFound = null;

//             foreach ($cartItems as $item) {
//                 $qty = isset($item['quantity']) ? (int)$item['quantity'] : 1;
//                 $totalQuantityInCart += $qty;

//                 $prod = \App\Models\Product::with('category')->find($item['product_id']);

//                 if ($prod && $prod->category) {
//                     $catCode = strtoupper(trim($prod->category->code));
//                     if (in_array($catCode, ['C001', 'C002', 'C003', 'C004'])) {
//                         $bagProductFound = $prod;
//                     }
//                 }
//             }

//             // Jika yang di-checkout lebih dari 1 barang (secara total kuantitas)
//             if ($totalQuantityInCart > 1) {
//                 return response()->json(['message' => 'Voucher Subsidi Tas hanya berlaku jika keranjang Anda berisi tepat 1 barang saja.'], 400);
//             }

//             // Jika barangnya cuma 1, tapi BUKAN TAS
//             if (!$bagProductFound) {
//                 return response()->json(['message' => 'Voucher ini khusus untuk pembelian kategori Tas (Kode Kategori Valid).'], 400);
//             }

//             // Cek apakah dia sudah pernah klaim voucher ini
//             $claim = PromoClaim::where('email', $user->email)->where('promo_code', 'SOLHOST34')->where('is_used', true)->first();
//             if ($claim) return response()->json(['message' => 'Anda sudah pernah menggunakan voucher ini (Hanya berlaku 1x).'], 400);

//             // Berikan Diskon Subsidi 3.4 Juta
//             return response()->json([
//                 'message' => 'Subsidi Spesial Rp 3.400.000 Berhasil Diterapkan!',
//                 'discount_value' => 3400000,
//                 'promo_type' => 'claim'
//             ], 200);
//         }
//         // ====================================================================

//         if ($code === 'SOLHERMEMBER') {
//             if (!$user->is_membership) return response()->json(['message' => 'Hanya untuk VIP Member.'], 400);
//             if ($user->has_used_member_voucher) return response()->json(['message' => 'Voucher ini sudah pernah digunakan.'], 400);

//             return response()->json(['message' => 'VIP Voucher applied!', 'discount_value' => 500000], 200);
//         }

//         if ($code === 'FIRSTORDER') {
//             $hasOrdered = \App\Models\Transaction::where('user_id', $user->id)->where('status', 'completed')->exists();
//             if ($hasOrdered) return response()->json(['message' => 'Voucher ini hanya untuk pembeli pertama.'], 400);

//             $claim = PromoClaim::where('email', $user->email)->where('promo_code', 'FIRSTORDER')->where('is_used', true)->first();
//             if ($claim) return response()->json(['message' => 'Anda sudah pernah menggunakan voucher ini.'], 400);

//             return response()->json(['message' => 'First Order Voucher applied!', 'discount_value' => 250000], 200);
//         }

//         $claim = PromoClaim::where('email', $user->email)
//             ->where('promo_code', $code)
//             ->first();

//         if (! $claim) {
//             return response()->json(['message' => 'Invalid promo code for this email address.'], 404);
//         }

//         if (now()->greaterThan($claim->expires_at)) {
//             return response()->json(['message' => 'This promo code has expired.'], 400);
//         }

//         if ($claim->is_used) {
//             return response()->json(['message' => 'This promo code has already been used.'], 400);
//         }

//         return response()->json([
//             'message' => 'Promo applied successfully!',
//             'discount_value' => $claim->discount_value,
//             'promo_type' => 'claim'
//         ], 200);
//     }
// }

// namespace App\Http\Controllers;

// use Carbon\Carbon;
// use App\Models\PromoClaim;
// use App\Mail\PromoCodeMail;
// use Illuminate\Support\Str;
// use Illuminate\Http\Request;
// use App\Jobs\SendPromoReminderJob;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Auth;
// use Illuminate\Support\Facades\Mail;
// use App\Services\PromoMerdekaService; // <-- Pastikan service dipanggil

// class PromoController extends Controller
// {
//     // public function claim(Request $request)
//     // {
//     //     // 👇 [PERBAIKAN] Tambahkan validasi 'campaign' 👇
//     //     $request->validate([
//     //         'email' => 'required|email',
//     //         'campaign' => 'nullable|string'
//     //     ]);

//     //     $campaign = $request->campaign;

//     //     // =======================================================
//     //     // LOGIKA POPUP 17 AGUSTUS
//     //     // =======================================================
//     //     if ($campaign === 'SOLHER17') {
//     //         $exists = PromoClaim::where('email', $request->email)->where('promo_code', 'SOLHER17')->first();
//     //         if ($exists) {
//     //             return response()->json(['message' => 'Email ini sudah mengklaim promo kemerdekaan sebelumnya.'], 400);
//     //         }

//     //         $code = 'SOLHER17';
//     //         $discountValue = 500000; // Sekadar angka placeholder maks
//     //         // Promo hangus pada 17 Agustus pukul 23:59:59
//     //         $expiresAt = Carbon::create(date('Y'), 8, 17, 23, 59, 59, 'Asia/Jakarta');
//     //     }
//     //     // =======================================================
//     //     // LOGIKA POPUP WELCOME DEFAULT (LAMA)
//     //     // =======================================================
//     //     else {
//     //         $exists = PromoClaim::where('email', $request->email)->where('promo_code', 'LIKE', 'SOLHER-%')->first();
//     //         if ($exists) {
//     //             return response()->json(['message' => 'Email ini sudah mengklaim promo sebelumnya.'], 400);
//     //         }

//     //         $code = 'SOLHER-'.strtoupper(Str::random(6));
//     //         $discountValue = 250000;
//     //         $expiresAt = now()->addHours(24);
//     //     }

//     //     try {
//     //         Mail::to($request->email)->send(new PromoCodeMail($code, $discountValue, $expiresAt));
//     //     } catch (\Exception $e) {
//     //         report($e);
//     //         Log::error('Failed to send promo email to '.$request->email.': '.$e->getMessage());
//     //         return response()->json(['message' => 'Gagal mengirim email. Pastikan alamat email valid atau coba lagi nanti.'], 500);
//     //     }

//     //     PromoClaim::create([
//     //         'email' => $request->email,
//     //         'promo_code' => $code,
//     //         'discount_value' => $discountValue,
//     //         'expires_at' => $expiresAt,
//     //     ]);

//     //     if ($campaign !== 'SOLHER17') {
//     //         SendPromoReminderJob::dispatch($request->email, $code, $discountValue)->delay(now()->addHours(23));
//     //     }

//     //     return response()->json([
//     //         'message' => 'Promo berhasil diklaim!',
//     //         'promo_code' => $code,
//     //     ]);
//     // }

//     public function claim(Request $request)
//     {
//         $request->validate([
//             'email' => 'required|email',
//             'campaign' => 'nullable|string'
//         ]);

//         $campaign = $request->campaign;

//         // =======================================================
//         // LOGIKA POPUP 17 AGUSTUS
//         // =======================================================
//         if ($campaign === 'SOLHER17') {
//             $exists = PromoClaim::where('email', $request->email)->where('promo_code', 'SOLHER17')->first();
//             if ($exists) {
//                 return response()->json(['message' => 'Email ini sudah mengklaim promo kemerdekaan sebelumnya.'], 400);
//             }

//             $code = 'SOLHER17';
//             $discountValue = 500000; // Sekadar angka placeholder maks
//             // Promo hangus pada 17 Agustus pukul 23:59:59
//             $expiresAt = Carbon::create(date('Y'), 8, 17, 23, 59, 59, 'Asia/Jakarta');
//         }
//         // =======================================================
//         // LOGIKA POPUP WELCOME DEFAULT (LAMA)
//         // =======================================================
//         else {
//             $exists = PromoClaim::where('email', $request->email)->where('promo_code', 'LIKE', 'SOLHER-%')->first();
//             if ($exists) {
//                 return response()->json(['message' => 'Email ini sudah mengklaim promo sebelumnya.'], 400);
//             }

//             $code = 'SOLHER-'.strtoupper(Str::random(6));
//             $discountValue = 250000;
//             $expiresAt = now()->addHours(24);
//         }

//         // 👇 [PERBAIKAN KRUSIAL] SIMPAN KE DATABASE TERLEBIH DAHULU 👇
//         try {
//             PromoClaim::create([
//                 'email' => $request->email,
//                 'promo_code' => $code,
//                 'discount_value' => $discountValue,
//                 'expires_at' => $expiresAt,
//             ]);
//         } catch (\Illuminate\Database\QueryException $e) {
//             // Jika ada user double-click, request kedua akan masuk ke error 1062 (Duplicate Entry MySQL)
//             if ($e->errorInfo[1] == 1062) {
//                 return response()->json(['message' => 'Email ini sudah mengklaim promo tersebut.'], 400);
//             }
//             // Jika ada error database lain, lempar kembali agar terlihat di Sentry
//             throw $e;
//         }
//         // 👆 ========================================================== 👆

//         // 👇 JIKA DATABASE BERHASIL TERSIMPAN AMAN, BARU KIRIM EMAIL 👇
//         try {
//             Mail::to($request->email)->send(new PromoCodeMail($code, $discountValue, $expiresAt));
//         } catch (\Exception $e) {
//             report($e);
//             Log::error('Failed to send promo email to '.$request->email.': '.$e->getMessage());

//             // JIKA EMAIL GAGAL DIKIRIM: Kita hapus kembali klaim di database
//             // agar user tidak terkunci dan bisa mencoba submit lagi
//             PromoClaim::where('email', $request->email)->where('promo_code', $code)->delete();

//             return response()->json(['message' => 'Gagal mengirim email. Pastikan alamat email valid atau coba lagi nanti.'], 500);
//         }

//         // Job reminder hanya untuk promo biasa
//         if ($campaign !== 'SOLHER17') {
//             SendPromoReminderJob::dispatch($request->email, $code, $discountValue)->delay(now()->addHours(23));
//         }

//         return response()->json([
//             'message' => 'Promo berhasil diklaim!',
//             'promo_code' => $code,
//         ]);
//     }

//     public function verify(Request $request, PromoMerdekaService $promoService)
//     {
//         $request->validate([
//             'promo_code' => 'required|string',
//             'cart_items' => 'required|array'
//         ]);

//         $user = Auth::user();
//         $code = strtoupper(trim($request->promo_code));
//         $cartItems = $request->cart_items;

//         foreach ($cartItems as $item) {
//             $product = \App\Models\Product::with('category')->find($item['product_id']);

//             if ($product && $product->discount_price) {
//                  $now = now();
//                  $start = $product->discount_start_date;
//                  $end = $product->discount_end_date;

//                  $isActive = false;
//                  if ($start && $end) { $isActive = $now->between($start, $end); }
//                  elseif ($start) { $isActive = $now->greaterThanOrEqualTo($start); }
//                  elseif ($end) { $isActive = $now->lessThanOrEqualTo($end); }
//                  else { $isActive = true; }

//                  if ($isActive) {
//                      return response()->json(['message' => 'Voucher tidak dapat digunakan untuk produk yang sedang diskon.'], 400);
//                  }
//             }
//         }

//         // ====================================================================
//         // 👇 [BARU] VALIDASI & KALKULASI SOLHER17 SAAT USER KLIK "APPLY" 👇
//         // ====================================================================
//         if ($code === 'SOLHER17') {
//             $claim = PromoClaim::where('email', $user->email)->where('promo_code', 'SOLHER17')->first();
//             if (!$claim) return response()->json(['message' => 'Anda belum mengklaim promo ini. Silakan klaim via pop-up terlebih dahulu.'], 400);
//             if ($claim->is_used) return response()->json(['message' => 'Voucher kemerdekaan Anda sudah pernah digunakan.'], 400);

//             // Karena servis butuh bentuk Collection Eloquent, kita tarik data keranjangnya dari DB
//             $dbCartItems = \App\Models\Cart::with('product.category')->where('user_id', $user->id)->get();

//             // Hitung secara presisi diskonnya
//             $promoResult = $promoService->calculatePromo($dbCartItems, []);

//             if (!$promoResult['is_valid']) {
//                 return response()->json(['message' => $promoResult['message']], 400);
//             }

//             return response()->json([
//                 'message' => $promoResult['message'],
//                 'discount_value' => $promoResult['discount_amount'], // <-- Kirimkan diskon yg sebenarnya (bukan sekadar 500k)
//                 'promo_type' => 'claim'
//             ], 200);
//         }
//         // ====================================================================

//         if ($code === 'SOLHOST34') {
//             // ... (logika SOLHOST34 biarkan persis seperti bawaan Anda sebelumnya)
//             $totalQuantityInCart = 0;
//             $bagProductFound = null;

//             foreach ($cartItems as $item) {
//                 $qty = isset($item['quantity']) ? (int)$item['quantity'] : 1;
//                 $totalQuantityInCart += $qty;
//                 $prod = \App\Models\Product::with('category')->find($item['product_id']);
//                 if ($prod && $prod->category) {
//                     $catCode = strtoupper(trim($prod->category->code));
//                     if (in_array($catCode, ['C001', 'C002', 'C003', 'C004'])) {
//                         $bagProductFound = $prod;
//                     }
//                 }
//             }

//             if ($totalQuantityInCart > 1) return response()->json(['message' => 'Voucher Subsidi Tas hanya berlaku jika keranjang Anda berisi tepat 1 barang saja.'], 400);
//             if (!$bagProductFound) return response()->json(['message' => 'Voucher ini khusus untuk pembelian kategori Tas (Kode Kategori Valid).'], 400);

//             $claim = PromoClaim::where('email', $user->email)->where('promo_code', 'SOLHOST34')->where('is_used', true)->first();
//             if ($claim) return response()->json(['message' => 'Anda sudah pernah menggunakan voucher ini (Hanya berlaku 1x).'], 400);

//             return response()->json([
//                 'message' => 'Subsidi Spesial Rp 3.400.000 Berhasil Diterapkan!',
//                 'discount_value' => 3400000,
//                 'promo_type' => 'claim'
//             ], 200);
//         }

//         if ($code === 'SOLHERMEMBER') {
//             if (!$user->is_membership) return response()->json(['message' => 'Hanya untuk VIP Member.'], 400);
//             if ($user->has_used_member_voucher) return response()->json(['message' => 'Voucher ini sudah pernah digunakan.'], 400);
//             return response()->json(['message' => 'VIP Voucher applied!', 'discount_value' => 500000], 200);
//         }

//         if ($code === 'FIRSTORDER') {
//             $hasOrdered = \App\Models\Transaction::where('user_id', $user->id)->where('status', 'completed')->exists();
//             if ($hasOrdered) return response()->json(['message' => 'Voucher ini hanya untuk pembeli pertama.'], 400);
//             $claim = PromoClaim::where('email', $user->email)->where('promo_code', 'FIRSTORDER')->where('is_used', true)->first();
//             if ($claim) return response()->json(['message' => 'Anda sudah pernah menggunakan voucher ini.'], 400);
//             return response()->json(['message' => 'First Order Voucher applied!', 'discount_value' => 250000], 200);
//         }

//         $claim = PromoClaim::where('email', $user->email)
//             ->where('promo_code', $code)
//             ->first();

//         if (! $claim) {
//             return response()->json(['message' => 'Invalid promo code for this email address.'], 404);
//         }

//         if (now()->greaterThan($claim->expires_at)) {
//             return response()->json(['message' => 'This promo code has expired.'], 400);
//         }

//         if ($claim->is_used) {
//             return response()->json(['message' => 'This promo code has already been used.'], 400);
//         }

//         return response()->json([
//             'message' => 'Promo applied successfully!',
//             'discount_value' => $claim->discount_value,
//             'promo_type' => 'claim'
//         ], 200);
//     }

//     // 👇 FUNGSI BARU UNTUK HALAMAN ADMIN VUE 👇
//     public function getAllClaims()
//     {
//         // Mengambil semua data promo claims diurutkan dari yang paling baru diklaim
//         $claims = PromoClaim::orderBy('created_at', 'desc')->get();
//         return response()->json($claims, 200);
//     }
// }

// namespace App\Http\Controllers;

// use Carbon\Carbon;
// use App\Models\Product;
// use App\Models\PromoClaim;
// use App\Mail\PromoCodeMail;
// use Illuminate\Support\Str;
// use Illuminate\Http\Request;
// use App\Jobs\SendPromoReminderJob;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Auth;
// use Illuminate\Support\Facades\Mail;
// use App\Services\PromoMerdekaService;

// class PromoController extends Controller
// {
//     public function claim(Request $request)
//     {
//         $request->validate([
//             'email' => 'required|email',
//             'campaign' => 'nullable|string'
//         ]);

//         $campaign = $request->campaign;

//         // =======================================================
//         // LOGIKA POPUP 17 AGUSTUS
//         // =======================================================
//         if ($campaign === 'SOLHER17') {
//             // 👇 PERBAIKAN FATAL 1: Cegah klaim jika masa promo sudah lewat 👇
//             $promoEnd = Carbon::create(date('Y'), 8, 17, 23, 59, 59, 'Asia/Jakarta');
//             if (now()->greaterThan($promoEnd)) {
//                 return response()->json(['message' => 'Mohon maaf, periode promo Kemerdekaan telah berakhir.'], 400);
//             }

//             $exists = PromoClaim::where('email', $request->email)->where('promo_code', 'SOLHER17')->first();
//             if ($exists) {
//                 return response()->json(['message' => 'Email ini sudah mengklaim promo kemerdekaan sebelumnya.'], 400);
//             }

//             $code = 'SOLHER17';
//             $discountValue = 500000;
//             $expiresAt = $promoEnd;
//         }
//         // =======================================================
//         // LOGIKA POPUP WELCOME DEFAULT
//         // =======================================================
//         else {
//             $exists = PromoClaim::where('email', $request->email)->where('promo_code', 'LIKE', 'SOLHER-%')->first();
//             if ($exists) {
//                 return response()->json(['message' => 'Email ini sudah mengklaim promo sebelumnya.'], 400);
//             }

//             $code = 'SOLHER-'.strtoupper(Str::random(6));
//             $discountValue = 250000;
//             $expiresAt = now()->addHours(24);
//         }

//         try {
//             PromoClaim::create([
//                 'email' => $request->email,
//                 'promo_code' => $code,
//                 'discount_value' => $discountValue,
//                 'expires_at' => $expiresAt,
//             ]);
//         } catch (\Illuminate\Database\QueryException $e) {
//             if ($e->errorInfo[1] == 1062) {
//                 return response()->json(['message' => 'Email ini sudah mengklaim promo tersebut.'], 400);
//             }
//             throw $e;
//         }

//         try {
//             // PERBAIKAN TIER 2: Sebaiknya gunakan ->queue() di masa depan, tapi untuk sekarang ->send() diamankan.
//             Mail::to($request->email)->send(new PromoCodeMail($code, $discountValue, $expiresAt));
//         } catch (\Exception $e) {
//             report($e);
//             Log::error('Failed to send promo email to '.$request->email.': '.$e->getMessage());

//             PromoClaim::where('email', $request->email)->where('promo_code', $code)->delete();
//             return response()->json(['message' => 'Gagal mengirim email. Pastikan alamat email valid atau coba lagi nanti.'], 500);
//         }

//         if ($campaign !== 'SOLHER17') {
//             SendPromoReminderJob::dispatch($request->email, $code, $discountValue)->delay(now()->addHours(23));
//         }

//         return response()->json([
//             'message' => 'Promo berhasil diklaim!',
//             'promo_code' => $code,
//         ]);
//     }

//     public function verify(Request $request, PromoMerdekaService $promoService)
//     {
//         $request->validate([
//             'promo_code' => 'required|string',
//             'cart_items' => 'required|array'
//         ]);

//         $user = Auth::user();
//         $code = strtoupper(trim($request->promo_code));
//         $cartItems = $request->cart_items;

//         // 👇 PERBAIKAN FATAL 2: HANCURKAN N+1 QUERY 👇
//         // Tarik semua ID produk yang ada di cart dalam 1x Query
//         $productIds = collect($cartItems)->pluck('product_id')->unique()->toArray();
//         $productsInCart = Product::with('category')->whereIn('id', $productIds)->get()->keyBy('id');
//         // 👆 ======================================== 👆

//         $totalQuantityInCart = 0;
//         $bagProductFound = null;

//         // Loop untuk mengecek syarat diskon menggunakan data yang sudah di-load di memori
//         foreach ($cartItems as $item) {
//             $product = $productsInCart->get($item['product_id']);
//             if (!$product) continue;

//             $qty = isset($item['quantity']) ? (int)$item['quantity'] : 1;
//             $totalQuantityInCart += $qty;

//             // Cek apakah ada barang yang sedang diskon
//             if ($product->discount_price) {
//                  $now = now();
//                  $start = $product->discount_start_date;
//                  $end = $product->discount_end_date;

//                  $isActive = false;
//                  if ($start && $end) { $isActive = $now->between($start, $end); }
//                  elseif ($start) { $isActive = $now->greaterThanOrEqualTo($start); }
//                  elseif ($end) { $isActive = $now->lessThanOrEqualTo($end); }
//                  else { $isActive = true; }

//                  if ($isActive) {
//                      return response()->json(['message' => 'Voucher tidak dapat digunakan untuk produk yang sedang diskon.'], 400);
//                  }
//             }

//             // Identifikasi apakah ada produk kategori Tas untuk syarat SOLHOST34
//             if ($product->category) {
//                 $catCode = strtoupper(trim($product->category->code));
//                 if (in_array($catCode, ['C001', 'C002', 'C003', 'C004'])) {
//                     $bagProductFound = $product;
//                 }
//             }
//         }

//         // ====================================================================
//         // VALIDASI SOLHER17
//         // ====================================================================
//         if ($code === 'SOLHER17') {
//             $claim = PromoClaim::where('email', $user->email)->where('promo_code', 'SOLHER17')->first();
//             if (!$claim) return response()->json(['message' => 'Anda belum mengklaim promo ini. Silakan klaim via pop-up terlebih dahulu.'], 400);
//             if ($claim->is_used) return response()->json(['message' => 'Voucher kemerdekaan Anda sudah pernah digunakan.'], 400);

//             $dbCartItems = \App\Models\Cart::with('product.category')->where('user_id', $user->id)->get();
//             $promoResult = $promoService->calculatePromo($dbCartItems, []);

//             if (!$promoResult['is_valid']) {
//                 return response()->json(['message' => $promoResult['message']], 400);
//             }

//             return response()->json([
//                 'message' => $promoResult['message'],
//                 'discount_value' => $promoResult['discount_amount'],
//                 'promo_type' => 'claim'
//             ], 200);
//         }

//         // ====================================================================
//         // VALIDASI SOLHOST34
//         // ====================================================================
//         if ($code === 'SOLHOST34') {
//             if ($totalQuantityInCart > 1) return response()->json(['message' => 'Voucher Subsidi Tas hanya berlaku jika keranjang Anda berisi tepat 1 barang saja.'], 400);
//             if (!$bagProductFound) return response()->json(['message' => 'Voucher ini khusus untuk pembelian kategori Tas.'], 400);

//             $claim = PromoClaim::where('email', $user->email)->where('promo_code', 'SOLHOST34')->where('is_used', true)->first();
//             if ($claim) return response()->json(['message' => 'Anda sudah pernah menggunakan voucher ini (Hanya berlaku 1x).'], 400);

//             return response()->json([
//                 'message' => 'Subsidi Spesial Rp 3.400.000 Berhasil Diterapkan!',
//                 'discount_value' => 3400000,
//                 'promo_type' => 'claim'
//             ], 200);
//         }

//         // ====================================================================
//         // VALIDASI MEMBER & FIRST ORDER
//         // ====================================================================
//         if ($code === 'SOLHERMEMBER') {
//             if (!$user->is_membership) return response()->json(['message' => 'Hanya untuk VIP Member.'], 400);
//             if ($user->has_used_member_voucher) return response()->json(['message' => 'Voucher ini sudah pernah digunakan.'], 400);
//             return response()->json(['message' => 'VIP Voucher applied!', 'discount_value' => 500000], 200);
//         }

//         if ($code === 'FIRSTORDER') {
//             $hasOrdered = \App\Models\Transaction::where('user_id', $user->id)->where('status', 'completed')->exists();
//             if ($hasOrdered) return response()->json(['message' => 'Voucher ini hanya untuk pembeli pertama.'], 400);
//             $claim = PromoClaim::where('email', $user->email)->where('promo_code', 'FIRSTORDER')->where('is_used', true)->first();
//             if ($claim) return response()->json(['message' => 'Anda sudah pernah menggunakan voucher ini.'], 400);
//             return response()->json(['message' => 'First Order Voucher applied!', 'discount_value' => 250000], 200);
//         }

//         // ====================================================================
//         // VALIDASI VOUCHER REGULER
//         // ====================================================================
//         $claim = PromoClaim::where('email', $user->email)->where('promo_code', $code)->first();

//         if (! $claim) {
//             return response()->json(['message' => 'Invalid promo code for this email address.'], 404);
//         }

//         if (now()->greaterThan($claim->expires_at)) {
//             return response()->json(['message' => 'This promo code has expired.'], 400);
//         }

//         if ($claim->is_used) {
//             return response()->json(['message' => 'This promo code has already been used.'], 400);
//         }

//         return response()->json([
//             'message' => 'Promo applied successfully!',
//             'discount_value' => $claim->discount_value,
//             'promo_type' => 'claim'
//         ], 200);
//     }

//     // 👇 PERBAIKAN FATAL 3: Gunakan Pagination agar Admin Panel tidak Out of Memory 👇
//     public function getAllClaims()
//     {
//         $claims = PromoClaim::orderBy('created_at', 'desc')->paginate(50);
//         return response()->json($claims, 200);
//     }
// }

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Product;
use App\Models\PromoClaim;
use App\Mail\PromoCodeMail;
use App\Models\Transaction;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Jobs\SendPromoReminderJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Services\PromoMerdekaService;
use Illuminate\Support\Facades\Cache;

class PromoController extends Controller
{
    // ====================================================================
    // [BARU] CRUD MANAGEMENT UNTUK ADMIN PANEL
    // ====================================================================

    /**
     * READ: Mengambil semua data promo untuk ditampilkan di tabel Admin.
     */
    public function indexAdmin(Request $request)
    {
        $promos = \App\Models\Promo::with(['targetCategory', 'targetProduct'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $promos
        ], 200);
    }

    /**
     * CREATE: Menyimpan promo baru.
     */
    public function storeAdmin(Request $request)
    {
        $request->validate([
            'code' => 'required|string|unique:promos,code',
            'title' => 'required|string',
            'description' => 'nullable|string',
            'discount_type' => 'required|in:fixed,percentage,free_shipping',
            'discount_value' => 'required|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0',
            'min_purchase' => 'required|numeric|min:0',
            'quota' => 'nullable|integer|min:1',
            'max_usage_per_user' => 'required|integer|min:1',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_member_only' => 'boolean',
            'is_first_order_only' => 'boolean',
            'is_active' => 'boolean',
            // Foreign Keys
            'target_category_id' => 'nullable|exists:categories,id',
            'target_product_id' => 'nullable|exists:products,id',
        ]);

        try {
            $promo = \App\Models\Promo::create($request->all());

            return response()->json([
                'status' => 'success',
                'message' => 'Promo berhasil dibuat.',
                'data' => $promo
            ], 201);
        } catch (\Exception $e) {
            Log::error('Gagal membuat Promo Admin: ' . $e->getMessage());
            return response()->json(['message' => 'Gagal menyimpan promo.'], 500);
        }
    }

    /**
     * READ SINGLE: Mengambil detail satu promo.
     */
    public function showAdmin($id)
    {
        $promo = \App\Models\Promo::findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $promo
        ], 200);
    }

    /**
     * UPDATE: Mengubah data promo yang sudah ada.
     */
    public function updateAdmin(Request $request, $id)
    {
        $promo = \App\Models\Promo::findOrFail($id);

        $request->validate([
            'code' => 'required|string|unique:promos,code,' . $id,
            'title' => 'required|string',
            'description' => 'nullable|string',
            'discount_type' => 'required|in:fixed,percentage,free_shipping',
            'discount_value' => 'required|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0',
            'min_purchase' => 'required|numeric|min:0',
            'quota' => 'nullable|integer|min:1',
            'max_usage_per_user' => 'required|integer|min:1',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_member_only' => 'boolean',
            'is_first_order_only' => 'boolean',
            'is_active' => 'boolean',
            // Foreign Keys
            'target_category_id' => 'nullable|exists:categories,id',
            'target_product_id' => 'nullable|exists:products,id',
        ]);

        try {
            $promo->update($request->all());

            return response()->json([
                'status' => 'success',
                'message' => 'Promo berhasil diperbarui.',
                'data' => $promo
            ], 200);
        } catch (\Exception $e) {
            Log::error('Gagal update Promo Admin: ' . $e->getMessage());
            return response()->json(['message' => 'Gagal memperbarui promo.'], 500);
        }
    }

    /**
     * DELETE: Menghapus promo.
     */
    public function destroyAdmin($id)
    {
        $promo = \App\Models\Promo::findOrFail($id);

        try {
            $promo->delete();
            return response()->json([
                'status' => 'success',
                'message' => 'Promo berhasil dihapus secara permanen.'
            ], 200);
        } catch (\Illuminate\Database\QueryException $e) {
            // Pengamanan: Cegah hapus jika sudah ada riwayat transaksi yang terikat dengan promo ini
            return response()->json(['message' => 'Promo tidak bisa dihapus karena sudah memiliki riwayat klaim.'], 422);
        }
    }

    // ====================================================================
    // [FITUR SENIOR] PROMO ABUSE SHIELD HELPERS
    // ====================================================================

    private function checkIpVelocity($ipAddress, $promoCode)
    {
        $cacheKey = "promo_claim_ip:{$ipAddress}:{$promoCode}";
        $claimCount = Cache::get($cacheKey, 0);

        // Jika IP ini sudah mengklaim / menggunakan kode promo yang sama lebih dari 2 kali, BLOKIR.
        if ($claimCount >= 2) {
            Log::warning("FRAUD DETECTED: IP Velocity limit reached for IP {$ipAddress} on Promo {$promoCode}");
            return false;
        }

        return true;
    }

    private function recordIpVelocity($ipAddress, $promoCode)
    {
        $cacheKey = "promo_claim_ip:{$ipAddress}:{$promoCode}";
        $claimCount = Cache::get($cacheKey, 0);

        // Simpan rekaman IP ini selama 24 jam (86400 detik)
        Cache::put($cacheKey, $claimCount + 1, 86400);
    }

    private function checkAddressSimilarity($userId, $requestedAddressId, $promoCode)
    {
        // 1. Ambil alamat yang akan digunakan untuk checkout
        $requestedAddress = \App\Models\Address::find($requestedAddressId);
        if (!$requestedAddress)
            return true;  // Lolos jika aneh

        $targetAddressStr = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $requestedAddress->address_location));

        // 2. Ambil semua alamat dari pengguna LAIN yang PERNAH sukses pakai kode promo yang sama
        $suspiciousTransactions = Transaction::with('address')
            ->where('promo_code', $promoCode)
            ->where('user_id', '!=', $userId)
            ->whereIn('status', ['completed', 'processing', 'pending'])
            // ->get();
            ->where('created_at', '>=', now()->subDays(7))  // 👈 Tambahkan limitasi waktu
            ->limit(200)  // 👈 Batasi loop maksimal 200 data
            ->get();

        foreach ($suspiciousTransactions as $trx) {
            if (!$trx->address)
                continue;

            $usedAddressStr = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $trx->address->address_location));

            // 3. Algoritma Levenshtein: Menghitung berapa huruf yang harus diubah untuk menyamakan 2 kalimat
            // Levenshtein butuh string pendek (kurang dari 255 karakter). Potong jika terlalu panjang.
            $str1 = substr($targetAddressStr, 0, 250);
            $str2 = substr($usedAddressStr, 0, 250);

            $distance = levenshtein($str1, $str2);
            $maxLength = max(strlen($str1), strlen($str2));

            // Hitung persentase kemiripan
            $similarity = $maxLength > 0 ? (1 - ($distance / $maxLength)) * 100 : 0;

            // Jika alamat 85% MIRIIP (meski dieja berbeda spt "Jln" vs "Jalan"), BLOKIR!
            if ($similarity >= 85) {
                Log::warning("FRAUD DETECTED: Address Similarity ({$similarity}%) on Promo {$promoCode}. User: {$userId}");
                return false;
            }
        }

        return true;
    }

    // ====================================================================

    // public function claim(Request $request)
    // {
    //     $request->validate([
    //         'email' => 'required|email',
    //         'campaign' => 'nullable|string'
    //     ]);

    //     $campaign = $request->campaign;
    //     $clientIp = $request->ip();  // Tangkap IP pengguna

    //     // =======================================================
    //     // LOGIKA POPUP 17 AGUSTUS
    //     // =======================================================
    //     if ($campaign === 'SOLHER17') {
    //         $promoEnd = Carbon::create(date('Y'), 8, 17, 23, 59, 59, 'Asia/Jakarta');
    //         if (now()->greaterThan($promoEnd)) {
    //             return response()->json(['message' => 'Mohon maaf, periode promo Kemerdekaan telah berakhir.'], 400);
    //         }

    //         // 👇 [SECURITY] Cek Velocity IP 👇
    //         if (!$this->checkIpVelocity($clientIp, 'SOLHER17')) {
    //             return response()->json(['message' => 'Sistem mendeteksi aktivitas mencurigakan dari perangkat Anda.'], 403);
    //         }

    //         $exists = PromoClaim::where('email', $request->email)->where('promo_code', 'SOLHER17')->first();
    //         if ($exists) {
    //             return response()->json(['message' => 'Email ini sudah mengklaim promo kemerdekaan sebelumnya.'], 400);
    //         }

    //         $code = 'SOLHER17';
    //         $discountValue = 500000;
    //         $expiresAt = $promoEnd;
    //     }
    //     // =======================================================
    //     // LOGIKA POPUP WELCOME DEFAULT
    //     // =======================================================
    //     else {
    //         $exists = PromoClaim::where('email', $request->email)->where('promo_code', 'LIKE', 'SOLHER-%')->first();
    //         if ($exists) {
    //             return response()->json(['message' => 'Email ini sudah mengklaim promo sebelumnya.'], 400);
    //         }

    //         $code = 'SOLHER-' . strtoupper(Str::random(6));
    //         $discountValue = 250000;
    //         $expiresAt = now()->addHours(24);
    //     }

    //     try {
    //         PromoClaim::create([
    //             'email' => $request->email,
    //             'promo_code' => $code,
    //             'discount_value' => $discountValue,
    //             'expires_at' => $expiresAt,
    //         ]);

    //         // 👇 [SECURITY] Catat IP setelah sukses klaim promo spesial 👇
    //         if ($campaign === 'SOLHER17') {
    //             $this->recordIpVelocity($clientIp, 'SOLHER17');
    //         }
    //     } catch (\Illuminate\Database\QueryException $e) {
    //         if ($e->errorInfo[1] == 1062) {
    //             return response()->json(['message' => 'Email ini sudah mengklaim promo tersebut.'], 400);
    //         }
    //         throw $e;
    //     }

    //     try {
    //         Mail::to($request->email)->send(new PromoCodeMail($code, $discountValue, $expiresAt));
    //     } catch (\Exception $e) {
    //         report($e);
    //         Log::error('Failed to send promo email to ' . $request->email . ': ' . $e->getMessage());

    //         PromoClaim::where('email', $request->email)->where('promo_code', $code)->delete();
    //         return response()->json(['message' => 'Gagal mengirim email. Pastikan alamat email valid atau coba lagi nanti.'], 500);
    //     }

    //     if ($campaign !== 'SOLHER17') {
    //         SendPromoReminderJob::dispatch($request->email, $code, $discountValue)->delay(now()->addHours(23));
    //     }

    //     return response()->json([
    //         'message' => 'Promo berhasil diklaim!',
    //         'promo_code' => $code,
    //     ]);
    // }

    public function claim(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'campaign' => 'nullable|string',
            'currency' => 'nullable|string' // Terima parameter currency
        ]);

        $campaign = $request->campaign;
        $clientIp = $request->ip();  // Tangkap IP pengguna
        $userCurrency = $request->currency ?? 'IDR'; // Default IDR

        // =======================================================
        // LOGIKA POPUP 17 AGUSTUS
        // =======================================================
        if ($campaign === 'SOLHER17') {
            $promoEnd = Carbon::create(date('Y'), 8, 17, 23, 59, 59, 'Asia/Jakarta');
            if (now()->greaterThan($promoEnd)) {
                return response()->json(['message' => 'Mohon maaf, periode promo Kemerdekaan telah berakhir.'], 400);
            }

            // 👇 [SECURITY] Cek Velocity IP 👇
            if (!$this->checkIpVelocity($clientIp, 'SOLHER17')) {
                return response()->json(['message' => 'Sistem mendeteksi aktivitas mencurigakan dari perangkat Anda.'], 403);
            }

            $exists = PromoClaim::where('email', $request->email)->where('promo_code', 'SOLHER17')->first();
            if ($exists) {
                return response()->json(['message' => 'Email ini sudah mengklaim promo kemerdekaan sebelumnya.'], 400);
            }

            $code = 'SOLHER17';
            $discountValue = 500000;
            $expiresAt = $promoEnd;
        }
        // =======================================================
        // LOGIKA POPUP WELCOME DEFAULT
        // =======================================================
        else {
            $exists = PromoClaim::where('email', $request->email)->where('promo_code', 'LIKE', 'SOLHER-%')->first();
            if ($exists) {
                return response()->json(['message' => 'Email ini sudah mengklaim promo sebelumnya.'], 400);
            }

            $code = 'SOLHER-' . strtoupper(Str::random(6));
            $discountValue = 250000;
            $expiresAt = now()->addHours(24);
        }

        try {
            PromoClaim::create([
                'email' => $request->email,
                'promo_code' => $code,
                'discount_value' => $discountValue,
                'expires_at' => $expiresAt,
            ]);

            // 👇 [SECURITY] Catat IP setelah sukses klaim promo spesial 👇
            if ($campaign === 'SOLHER17') {
                $this->recordIpVelocity($clientIp, 'SOLHER17');
            }
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                return response()->json(['message' => 'Email ini sudah mengklaim promo tersebut.'], 400);
            }
            throw $e;
        }

        // 👇 [PERBAIKAN] Hitung Nilai Tampil untuk Email berdasarkan Currency 👇
        $displayValue = $discountValue;
        $currencySymbol = 'Rp';

        if ($userCurrency !== 'IDR') {
            $rates = Cache::get('exchange_rates', []);
            $rate = $rates[$userCurrency] ?? 1;

            // Konversi dari IDR ke mata uang asing
            $displayValue = $discountValue * $rate;

            // Tentukan Simbol
            $currencySymbol = match ($userCurrency) {
                'USD' => '$',
                'SGD' => 'S$',
                'EUR' => '€',
                'AUD' => 'A$',
                'MYR' => 'RM',
                default => $userCurrency
            };
        }

        try {
            // Ubah pengiriman parameter Mailable
            Mail::to($request->email)->send(new PromoCodeMail($code, $displayValue, $expiresAt, $currencySymbol));
        } catch (\Exception $e) {
            report($e);
            Log::error('Failed to send promo email to ' . $request->email . ': ' . $e->getMessage());

            PromoClaim::where('email', $request->email)->where('promo_code', $code)->delete();
            return response()->json(['message' => 'Gagal mengirim email. Pastikan alamat email valid atau coba lagi nanti.'], 500);
        }

        if ($campaign !== 'SOLHER17') {
            SendPromoReminderJob::dispatch($request->email, $code, $discountValue)->delay(now()->addHours(23));
        }

        return response()->json([
            'message' => 'Promo berhasil diklaim!',
            'promo_code' => $code,
        ]);
    }

    // public function verify(Request $request, PromoMerdekaService $promoService)
    // {
    //     $request->validate([
    //         'promo_code' => 'required|string',
    //         'cart_items' => 'required|array',
    //         'address_id' => 'nullable|integer'  // Ditambahkan untuk cek fraud alamat
    //     ]);

    //     $user = Auth::user();
    //     $code = strtoupper(trim($request->promo_code));
    //     $cartItems = $request->cart_items;
    //     $addressId = $request->address_id;

    //     $productIds = collect($cartItems)->pluck('product_id')->unique()->toArray();
    //     $productsInCart = Product::with('category')->whereIn('id', $productIds)->get()->keyBy('id');

    //     $totalQuantityInCart = 0;
    //     $bagProductFound = null;

    //     foreach ($cartItems as $item) {
    //         $product = $productsInCart->get($item['product_id']);
    //         if (!$product)
    //             continue;

    //         $qty = isset($item['quantity']) ? (int) $item['quantity'] : 1;
    //         $totalQuantityInCart += $qty;

    //         if ($product->discount_price) {
    //             $now = now();
    //             $start = $product->discount_start_date;
    //             $end = $product->discount_end_date;

    //             $isActive = false;
    //             if ($start && $end) {
    //                 $isActive = $now->between($start, $end);
    //             } elseif ($start) {
    //                 $isActive = $now->greaterThanOrEqualTo($start);
    //             } elseif ($end) {
    //                 $isActive = $now->lessThanOrEqualTo($end);
    //             } else {
    //                 $isActive = true;
    //             }

    //             if ($isActive) {
    //                 return response()->json(['message' => 'Voucher tidak dapat digunakan untuk produk yang sedang diskon.'], 400);
    //             }
    //         }

    //         if ($product->category) {
    //             $catCode = strtoupper(trim($product->category->code));
    //             if (in_array($catCode, ['C001', 'C002', 'C003', 'C004'])) {
    //                 $bagProductFound = $product;
    //             }
    //         }
    //     }

    //     // ====================================================================
    //     // [SECURITY] EKSEKUSI FRAUD CHECKER UNTUK PROMO HIGH-RISK
    //     // ====================================================================
    //     if (in_array($code, ['SOLHOST34', 'SOLHOST35', 'SOLHER17', 'MERDEKA17'])) {
    //         // Cek IP Request saat checkout
    //         if (!$this->checkIpVelocity($request->ip(), $code)) {
    //             return response()->json(['message' => 'Sistem mendeteksi aktivitas fraud dari jaringan Anda. Kode promo diblokir.'], 403);
    //         }

    //         // Cek Kemiripan Alamat jika dikirimkan oleh Frontend
    //         if ($addressId) {
    //             if (!$this->checkAddressSimilarity($user->id, $addressId, $code)) {
    //                 return response()->json(['message' => 'Alamat pengiriman ini sudah melewati batas maksimal klaim promo.'], 403);
    //             }
    //         }
    //     }
    //     // ====================================================================

    //     if ($code === 'SOLHER17') {
    //         $claim = PromoClaim::where('email', $user->email)->where('promo_code', 'SOLHER17')->first();
    //         if (!$claim)
    //             return response()->json(['message' => 'Anda belum mengklaim promo ini. Silakan klaim via pop-up terlebih dahulu.'], 400);
    //         if ($claim->is_used)
    //             return response()->json(['message' => 'Voucher kemerdekaan Anda sudah pernah digunakan.'], 400);

    //         $dbCartItems = \App\Models\Cart::with('product.category')->where('user_id', $user->id)->get();
    //         $promoResult = $promoService->calculatePromo($dbCartItems, []);

    //         if (!$promoResult['is_valid']) {
    //             return response()->json(['message' => $promoResult['message']], 400);
    //         }

    //         return response()->json([
    //             'message' => $promoResult['message'],
    //             'discount_value' => $promoResult['discount_amount'],
    //             'promo_type' => 'claim'
    //         ], 200);
    //     }

    //     if ($code === 'SOLHOST34') {
    //         if ($totalQuantityInCart > 1)
    //             return response()->json(['message' => 'Voucher Subsidi Tas hanya berlaku jika keranjang Anda berisi tepat 1 barang saja.'], 400);
    //         if (!$bagProductFound)
    //             return response()->json(['message' => 'Voucher ini khusus untuk pembelian kategori Tas.'], 400);

    //         $claim = PromoClaim::where('email', $user->email)->where('promo_code', 'SOLHOST34')->where('is_used', true)->first();
    //         if ($claim)
    //             return response()->json(['message' => 'Anda sudah pernah menggunakan voucher ini (Hanya berlaku 1x).'], 400);

    //         return response()->json([
    //             'message' => 'Subsidi Spesial Rp 3.400.000 Berhasil Diterapkan!',
    //             'discount_value' => 3400000,
    //             'promo_type' => 'claim'
    //         ], 200);
    //     }

    //     // 👇 [TAMBAHKAN LOGIKA VERIFY SOLHOST35 DI SINI] 👇
    //     if ($code === 'SOLHOST35') {
    //         // $promoStart = Carbon::create(date('Y'), 10, 1, 0, 0, 0, 'Asia/Jakarta');
    //         // $promoEnd = Carbon::create(date('Y'), 10, 3, 23, 59, 59, 'Asia/Jakarta');

    //         $promoStart = Carbon::create(now()->year, 10, 1, 0, 0, 0, 'Asia/Jakarta');
    //         $promoEnd = Carbon::create(now()->year, 10, 8, 19, 0, 0, 'Asia/Jakarta');

    //         if (now()->lessThan($promoStart)) {
    //             return response()->json(['message' => 'Sabar ya, voucher SOLHOST35 baru bisa digunakan mulai 1 Oktober!'], 400);
    //         }
    //         if (now()->greaterThan($promoEnd)) {
    //             return response()->json(['message' => 'Mohon maaf, masa berlaku voucher SOLHOST35 telah berakhir.'], 400);
    //         }

    //         if ($totalQuantityInCart > 1)
    //             return response()->json(['message' => 'Voucher Subsidi Tas hanya berlaku jika keranjang Anda berisi tepat 1 barang saja.'], 400);
    //         if (!$bagProductFound)
    //             return response()->json(['message' => 'Voucher ini khusus untuk pembelian kategori Tas.'], 400);

    //         $claim = PromoClaim::where('email', $user->email)->where('promo_code', 'SOLHOST35')->where('is_used', true)->first();
    //         if ($claim)
    //             return response()->json(['message' => 'Anda sudah pernah menggunakan voucher ini (Hanya berlaku 1x).'], 400);

    //         return response()->json([
    //             'message' => 'Subsidi Spesial Rp 3.400.000 Berhasil Diterapkan!',
    //             'discount_value' => 3400000,
    //             'promo_type' => 'claim'
    //         ], 200);
    //     }

    //     if ($code === 'SOLHERMEMBER') {
    //         if (!$user->is_membership)
    //             return response()->json(['message' => 'Hanya untuk VIP Member.'], 400);
    //         if ($user->has_used_member_voucher)
    //             return response()->json(['message' => 'Voucher ini sudah pernah digunakan.'], 400);
    //         return response()->json(['message' => 'VIP Voucher applied!', 'discount_value' => 500000], 200);
    //     }

    //     if ($code === 'FIRSTORDER') {
    //         $hasOrdered = \App\Models\Transaction::where('user_id', $user->id)->where('status', 'completed')->exists();
    //         if ($hasOrdered)
    //             return response()->json(['message' => 'Voucher ini hanya untuk pembeli pertama.'], 400);
    //         $claim = PromoClaim::where('email', $user->email)->where('promo_code', 'FIRSTORDER')->where('is_used', true)->first();
    //         if ($claim)
    //             return response()->json(['message' => 'Anda sudah pernah menggunakan voucher ini.'], 400);
    //         return response()->json(['message' => 'First Order Voucher applied!', 'discount_value' => 250000], 200);
    //     }

    //     $claim = PromoClaim::where('email', $user->email)->where('promo_code', $code)->first();

    //     if (!$claim) {
    //         return response()->json(['message' => 'Invalid promo code for this email address.'], 404);
    //     }

    //     if (now()->greaterThan($claim->expires_at)) {
    //         return response()->json(['message' => 'This promo code has expired.'], 400);
    //     }

    //     if ($claim->is_used) {
    //         return response()->json(['message' => 'This promo code has already been used.'], 400);
    //     }

    //     return response()->json([
    //         'message' => 'Promo applied successfully!',
    //         'discount_value' => $claim->discount_value,
    //         'promo_type' => 'claim'
    //     ], 200);
    // }

    public function verify(Request $request, PromoMerdekaService $promoService)
    {
        $request->validate([
            'promo_code' => 'required|string',
            'cart_items' => 'required|array',
            'address_id' => 'nullable|integer'  // Ditambahkan untuk cek fraud alamat
        ]);

        $user = Auth::user();
        $code = strtoupper(trim($request->promo_code));
        $cartItems = $request->cart_items;
        $addressId = $request->address_id;

        $productIds = collect($cartItems)->pluck('product_id')->unique()->toArray();
        $productsInCart = Product::with('category')->whereIn('id', $productIds)->get()->keyBy('id');

        $totalQuantityInCart = 0;
        $bagProductFound = null;

        // --- 1. VALIDASI BARANG DISKON DI KERANJANG ---
        foreach ($cartItems as $item) {
            $product = $productsInCart->get($item['product_id']);
            if (!$product)
                continue;

            $qty = isset($item['quantity']) ? (int) $item['quantity'] : 1;
            $totalQuantityInCart += $qty;

            if ($product->discount_price) {
                $now = now();
                $start = $product->discount_start_date;
                $end = $product->discount_end_date;

                $isActive = false;
                if ($start && $end) {
                    $isActive = $now->between($start, $end);
                } elseif ($start) {
                    $isActive = $now->greaterThanOrEqualTo($start);
                } elseif ($end) {
                    $isActive = $now->lessThanOrEqualTo($end);
                } else {
                    $isActive = true;
                }

                if ($isActive) {
                    return response()->json(['message' => 'Voucher tidak dapat digunakan untuk produk yang sedang diskon.'], 400);
                }
            }

            if ($product->category) {
                $catCode = strtoupper(trim($product->category->code));
                if (in_array($catCode, ['C001', 'C002', 'C003', 'C004'])) {
                    $bagProductFound = $product;
                }
            }
        }

        // ====================================================================
        // [SECURITY] EKSEKUSI FRAUD CHECKER UNTUK PROMO HIGH-RISK
        // ====================================================================
        if (in_array($code, ['SOLHOST34', 'SOLHOST35', 'SOLHER17', 'MERDEKA17'])) {
            // Cek IP Request saat checkout
            if (!$this->checkIpVelocity($request->ip(), $code)) {
                return response()->json(['message' => 'Sistem mendeteksi aktivitas fraud dari jaringan Anda. Kode promo diblokir.'], 403);
            }

            // Cek Kemiripan Alamat jika dikirimkan oleh Frontend
            if ($addressId) {
                if (!$this->checkAddressSimilarity($user->id, $addressId, $code)) {
                    return response()->json(['message' => 'Alamat pengiriman ini sudah melewati batas maksimal klaim promo.'], 403);
                }
            }
        }
        // ====================================================================

        // --- 2. PENGECEKAN HARDCODE LAMA (BACKWARD COMPATIBILITY) ---
        if ($code === 'SOLHER17') {
            $claim = PromoClaim::where('email', $user->email)->where('promo_code', 'SOLHER17')->first();
            if (!$claim)
                return response()->json(['message' => 'Anda belum mengklaim promo ini. Silakan klaim via pop-up terlebih dahulu.'], 400);
            if ($claim->is_used)
                return response()->json(['message' => 'Voucher kemerdekaan Anda sudah pernah digunakan.'], 400);

            $dbCartItems = \App\Models\Cart::with('product.category')->where('user_id', $user->id)->get();
            $promoResult = $promoService->calculatePromo($dbCartItems, []);

            if (!$promoResult['is_valid']) {
                return response()->json(['message' => $promoResult['message']], 400);
            }

            return response()->json([
                'message' => $promoResult['message'],
                'discount_value' => $promoResult['discount_amount'],
                'promo_type' => 'claim'
            ], 200);
        }

        if ($code === 'SOLHOST34') {
            if ($totalQuantityInCart > 1)
                return response()->json(['message' => 'Voucher Subsidi Tas hanya berlaku jika keranjang Anda berisi tepat 1 barang saja.'], 400);
            if (!$bagProductFound)
                return response()->json(['message' => 'Voucher ini khusus untuk pembelian kategori Tas.'], 400);

            $claim = PromoClaim::where('email', $user->email)->where('promo_code', 'SOLHOST34')->where('is_used', true)->first();
            if ($claim)
                return response()->json(['message' => 'Anda sudah pernah menggunakan voucher ini (Hanya berlaku 1x).'], 400);

            return response()->json([
                'message' => 'Subsidi Spesial Rp 3.400.000 Berhasil Diterapkan!',
                'discount_value' => 3400000,
                'promo_type' => 'claim'
            ], 200);
        }

        if ($code === 'SOLHOST35') {
            $promoStart = Carbon::create(now()->year, 10, 1, 0, 0, 0, 'Asia/Jakarta');
            $promoEnd = Carbon::create(now()->year, 10, 9, 1, 0, 0, 'Asia/Jakarta');

            if (now()->lessThan($promoStart)) {
                return response()->json(['message' => 'Sabar ya, voucher SOLHOST35 baru bisa digunakan mulai 1 Oktober!'], 400);
            }
            if (now()->greaterThan($promoEnd)) {
                return response()->json(['message' => 'Mohon maaf, masa berlaku voucher SOLHOST35 telah berakhir.'], 400);
            }

            if ($totalQuantityInCart > 1)
                return response()->json(['message' => 'Voucher Subsidi Tas hanya berlaku jika keranjang Anda berisi tepat 1 barang saja.'], 400);
            if (!$bagProductFound)
                return response()->json(['message' => 'Voucher ini khusus untuk pembelian kategori Tas.'], 400);

            $claim = PromoClaim::where('email', $user->email)->where('promo_code', 'SOLHOST35')->where('is_used', true)->first();
            if ($claim)
                return response()->json(['message' => 'Anda sudah pernah menggunakan voucher ini (Hanya berlaku 1x).'], 400);

            return response()->json([
                'message' => 'Subsidi Spesial Rp 3.400.000 Berhasil Diterapkan!',
                'discount_value' => 3400000,
                'promo_type' => 'claim'
            ], 200);
        }

        if ($code === 'SOLHERMEMBER') {
            if (!$user->is_membership)
                return response()->json(['message' => 'Hanya untuk VIP Member.'], 400);
            if ($user->has_used_member_voucher)
                return response()->json(['message' => 'Voucher ini sudah pernah digunakan.'], 400);
            return response()->json(['message' => 'VIP Voucher applied!', 'discount_value' => 500000], 200);
        }

        if ($code === 'FIRSTORDER') {
            $hasOrdered = \App\Models\Transaction::where('user_id', $user->id)->where('status', 'completed')->exists();
            if ($hasOrdered)
                return response()->json(['message' => 'Voucher ini hanya untuk pembeli pertama.'], 400);
            $claim = PromoClaim::where('email', $user->email)->where('promo_code', 'FIRSTORDER')->where('is_used', true)->first();
            if ($claim)
                return response()->json(['message' => 'Anda sudah pernah menggunakan voucher ini.'], 400);
            return response()->json(['message' => 'First Order Voucher applied!', 'discount_value' => 250000], 200);
        }

        // --- 3. [BARU] PENGECEKAN KE DYNAMIC PROMO ENGINE ---
        $dynamicPromo = \App\Models\Promo::where('code', $code)->first();

        if ($dynamicPromo) {
            // Cek Status Aktif & Waktu
            if (!$dynamicPromo->isValidNow()) {
                return response()->json(['message' => 'Mohon maaf, kode promo ini sedang tidak aktif atau sudah kadaluwarsa.'], 400);
            }

            // Cek Minimum Belanja
            $cartSubtotal = collect($cartItems)->sum(function ($item) use ($productsInCart) {
                $p = $productsInCart->get($item['product_id']);
                return $p ? ($p->discount_price ?? $p->price) * $item['quantity'] : 0;
            });
            if ($cartSubtotal < $dynamicPromo->min_purchase) {
                return response()->json(['message' => 'Subtotal keranjang Anda belum memenuhi syarat minimum Rp ' . number_format($dynamicPromo->min_purchase, 0, ',', '.')], 400);
            }

            // Cek First Order
            if ($dynamicPromo->is_first_order_only) {
                $hasOrdered = \App\Models\Transaction::where('user_id', $user->id)->where('status', 'completed')->exists();
                if ($hasOrdered) {
                    return response()->json(['message' => 'Voucher ini khusus untuk pengguna baru / pembelian pertama.'], 400);
                }
            }

            // Cek VIP Member
            if ($dynamicPromo->is_member_only && !$user->is_membership) {
                return response()->json(['message' => 'Voucher ini eksklusif hanya untuk VIP Member Solher.'], 400);
            }

            // Cek Target Kategori Spesifik (Jika disetel)
            if ($dynamicPromo->target_category_id) {
                $hasTargetCategory = false;
                foreach ($cartItems as $item) {
                    $p = $productsInCart->get($item['product_id']);
                    if ($p && $p->category_id == $dynamicPromo->target_category_id) {
                        $hasTargetCategory = true;
                        break;
                    }
                }
                if (!$hasTargetCategory) {
                    return response()->json(['message' => 'Voucher ini tidak berlaku untuk produk di keranjang Anda.'], 400);
                }
            }

            // Cek Batas Penggunaan per User
            $userUsageCount = \App\Models\Transaction::where('user_id', $user->id)
                ->where('promo_code', $code)
                ->whereIn('status', ['completed', 'processing', 'pending'])
                ->count();
            if ($userUsageCount >= $dynamicPromo->max_usage_per_user) {
                return response()->json(['message' => "Anda sudah mencapai batas pemakaian voucher ini ({$dynamicPromo->max_usage_per_user}x)."], 400);
            }

            // Hitung Nilai Diskon
            $finalDiscount = 0;
            if ($dynamicPromo->discount_type === 'fixed') {
                $finalDiscount = $dynamicPromo->discount_value;
            } elseif ($dynamicPromo->discount_type === 'percentage') {
                $calculatedDiscount = $cartSubtotal * ($dynamicPromo->discount_value / 100);
                $finalDiscount = $dynamicPromo->max_discount ? min($calculatedDiscount, $dynamicPromo->max_discount) : $calculatedDiscount;
            } elseif ($dynamicPromo->discount_type === 'free_shipping') {
                // Return flag khusus untuk memberitahu frontend bahwa ongkir gratis
                return response()->json([
                    'message' => 'Voucher Gratis Ongkir berhasil diterapkan!',
                    'discount_value' => 0, // Nilainya 0 karena akan memotong ongkir, bukan subtotal produk
                    'promo_type' => 'free_shipping',
                    'dynamic_promo_id' => $dynamicPromo->id
                ], 200);
            }

            return response()->json([
                'message' => $dynamicPromo->title . ' berhasil diterapkan!',
                'discount_value' => $finalDiscount,
                'promo_type' => 'dynamic',
                'dynamic_promo_id' => $dynamicPromo->id
            ], 200);
        }

        // --- 4. PENGECEKAN PERSONAL PROMO (EMAIL BLAST) ---
        // Jika tidak ketemu di hardcode, dan tidak ketemu di tabel promos,
        // cek apakah ini promo personal (diklaim via popup welcome email)
        $claim = PromoClaim::where('email', $user->email)->where('promo_code', $code)->first();

        if (!$claim) {
            return response()->json(['message' => 'Kode voucher tidak valid atau tidak ditemukan.'], 404);
        }

        if (now()->greaterThan($claim->expires_at)) {
            return response()->json(['message' => 'Voucher ini sudah kadaluwarsa.'], 400);
        }

        if ($claim->is_used) {
            return response()->json(['message' => 'Voucher ini sudah pernah digunakan.'], 400);
        }

        return response()->json([
            'message' => 'Promo diterapkan!',
            'discount_value' => $claim->discount_value,
            'promo_type' => 'claim'
        ], 200);
    }

    public function getAllClaims()
    {
        $claims = PromoClaim::orderBy('created_at', 'desc')->paginate(50);
        return response()->json($claims, 200);
    }
}
