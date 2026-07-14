<?php

namespace App\Http\Controllers;

use App\Mail\PromoCodeMail;
use App\Models\PromoClaim;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PromoController extends Controller
{
    public function claim(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $discountValue = 250000;

        $exists = PromoClaim::where('email', $request->email)->first();
        if ($exists) {
            return response()->json(['message' => 'Email ini sudah mengklaim promo sebelumnya.'], 400);
        }

        $code = 'SOLHER-'.strtoupper(Str::random(6));

        // Set waktu expired 24 jam dari sekarang
        $expiresAt = now()->addHours(24);

        try {
            Mail::to($request->email)->send(new PromoCodeMail($code, $discountValue, $expiresAt));
        } catch (\Exception $e) {
            report($e);
            Log::error('Failed to send promo email to '.$request->email.': '.$e->getMessage());
            return response()->json(['message' => 'Gagal mengirim email. Pastikan alamat email valid atau coba lagi nanti.'], 500);
        }

        PromoClaim::create([
            'email' => $request->email,
            'promo_code' => $code,
            'discount_value' => $discountValue,
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'message' => 'Promo berhasil diklaim!',
            'promo_code' => $code,
        ]);
    }

    public function verify(Request $request)
    {
    //     $request->validate(['promo_code' => 'required|string']);
    //     $user = Auth::user();

    //     // Standarisasi kapitalisasi dan hapus spasi agar akurat
    //     $code = strtoupper(trim($request->promo_code));

    //     // =========================================================================
    //     // [LOGIKA BARU] OPSI C: VIP MEMBER VOUCHER UNIVERSAL
    //     // =========================================================================
    //     if ($code === 'SOLHERMEMBER') {

    //         // 1. Validasi Status Member
    //         if (!$user->is_membership) {
    //             return response()->json(['message' => 'Voucher ini eksklusif hanya untuk VIP Member.'], 400);
    //         }

    //         // 2. Validasi Kuota (Satu Kali Seumur Hidup per Akun)
    //         if ($user->has_used_member_voucher) {
    //             return response()->json(['message' => 'Anda sudah pernah menggunakan voucher VIP ini sebelumnya.'], 400);
    //         }

    //         // Jika lolos, kirimkan nilai diskon mutlak (500rb) ke Frontend
    //         return response()->json([
    //             'message' => 'VIP Member Voucher applied!',
    //             'discount_value' => 500000,
    //         ], 200);
    //     }

    $request->validate([
            'promo_code' => 'required|string',
            'cart_items' => 'required|array' // Tambahkan ini agar kita bisa cek apakah ada diskon di cart
        ]);

        $user = Auth::user();
        $code = strtoupper(trim($request->promo_code));
        $cartItems = $request->cart_items; // Array produk yang dibeli

        // --- VALIDASI: CEK DISKON BERTUMPUK (Stacking) ---
        // Jika ada satu saja produk di cart yang sedang diskon aktif, tolak voucher!
        foreach ($cartItems as $item) {
            $product = \App\Models\Product::find($item['product_id']);
            if ($product && $product->discount_price && \App\Models\Product::where('id', $product->id)->first()->discount_price !== null) {
                 // Cek apakah diskonnya sedang aktif
                 $now = now();
                 $start = $product->discount_start_date;
                 $end = $product->discount_end_date;

                 $isActive = false;
                 if ($start && $end) { $isActive = $now->between($start, $end); }
                 elseif ($start) { $isActive = $now->greaterThanOrEqualTo($start); }
                 elseif ($end) { $isActive = $now->lessThanOrEqualTo($end); }
                 else { $isActive = true; } // Jika tidak ada tanggal, dianggap diskon selamanya

                 if ($isActive) {
                     return response()->json(['message' => 'Voucher tidak dapat digunakan untuk produk yang sedang diskon.'], 400);
                 }
            }
        }

        // ================= OPSI C: VIP MEMBER VOUCHER =================
        if ($code === 'SOLHERMEMBER') {
            if (!$user->is_membership) return response()->json(['message' => 'Hanya untuk VIP Member.'], 400);
            if ($user->has_used_member_voucher) return response()->json(['message' => 'Voucher ini sudah pernah digunakan.'], 400);

            return response()->json(['message' => 'VIP Voucher applied!', 'discount_value' => 500000], 200);
        }

        // ================= OPSI D: FIRST ORDER VOUCHER =================
        if ($code === 'FIRSTORDER') {
            // Cek apakah user pernah transaksi sebelumnya
            $hasOrdered = \App\Models\Transaction::where('user_id', $user->id)->where('status', 'completed')->exists();
            if ($hasOrdered) return response()->json(['message' => 'Voucher ini hanya untuk pembeli pertama.'], 400);

            // Cek di tabel PromoClaim apakah dia sudah pernah pakai 'FIRSTORDER'
            $claim = PromoClaim::where('email', $user->email)->where('promo_code', 'FIRSTORDER')->where('is_used', true)->first();
            if ($claim) return response()->json(['message' => 'Anda sudah pernah menggunakan voucher ini.'], 400);

            return response()->json(['message' => 'First Order Voucher applied!', 'discount_value' => 250000], 200);
        }

        // =========================================================================
        // [LOGIKA LAMA] PROMO KLAIM EMAIL (NEWSLETTER)
        // =========================================================================
        $claim = PromoClaim::where('email', $user->email)
            ->where('promo_code', $code)
            ->first();

        if (! $claim) {
            return response()->json(['message' => 'Invalid promo code for this email address.'], 404);
        }

        // Validasi B: Cek apakah sudah lewat dari 24 jam
        if (now()->greaterThan($claim->expires_at)) {
            return response()->json(['message' => 'This promo code has expired.'], 400);
        }

        // Validasi C: Cek apakah sudah pernah diredeem
        if ($claim->is_used) {
            return response()->json(['message' => 'This promo code has already been used.'], 400);
        }

        return response()->json([
            'message' => 'Promo applied successfully!',
            'discount_value' => $claim->discount_value,
        ], 200);
    }
}
