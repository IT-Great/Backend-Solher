<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PromoClaim;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\PromoCodeMail;

class PromoController extends Controller
{
    // Dipanggil dari HomePage (Pop-up)
    // public function claim(Request $request)
    // {
    //     $request->validate(['email' => 'required|email']);
    //     $code = 'SOLHERBARU'; // Hardcoded kode promo kampanye saat ini

    //     $exists = PromoClaim::where('email', $request->email)->where('promo_code', $code)->first();
    //     if ($exists) {
    //         return response()->json(['message' => 'Email ini sudah mengklaim promo sebelumnya.'], 400);
    //     }

    //     PromoClaim::create([
    //         'email' => $request->email,
    //         'promo_code' => $code,
    //         'discount_value' => 25000 // Nilai diskon Rp 25.000
    //     ]);

    //     return response()->json([
    //         'message' => 'Promo berhasil diklaim!',
    //         'promo_code' => $code
    //     ]);
    // }

    // // Dipanggil dari PaymentPage (Saat Apply Promo)
    // public function verify(Request $request)
    // {
    //     $request->validate(['promo_code' => 'required|string']);
    //     $user = Auth::user();

    //     // Pastikan email user yang sedang login SAMA dengan email yang didaftarkan di pop-up
    //     $claim = PromoClaim::where('email', $user->email)
    //         ->where('promo_code', strtoupper($request->promo_code))
    //         ->first();

    //     if (!$claim) {
    //         return response()->json(['message' => 'Kode promo tidak ditemukan untuk alamat email Anda.'], 404);
    //     }
    //     if ($claim->is_used) {
    //         return response()->json(['message' => 'Kode promo ini sudah Anda gunakan sebelumnya.'], 400);
    //     }

    //     return response()->json([
    //         'message' => 'Kode promo valid!',
    //         'discount_value' => $claim->discount_value
    //     ]);
    // }

    // Dipanggil dari HomePage (Pop-up)
    public function claim(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $code = 'SOLHERBARU';
        $discountValue = 25000;

        $exists = PromoClaim::where('email', $request->email)->where('promo_code', $code)->first();
        if ($exists) {
            return response()->json(['message' => 'Email ini sudah mengklaim promo sebelumnya.'], 400);
        }

        // 1. Coba kirim email TERLEBIH DAHULU sebelum menyimpan ke database
        try {
            Mail::to($request->email)->send(new PromoCodeMail($code, $discountValue));
        } catch (\Exception $e) {
            Log::error('Failed to send promo email to ' . $request->email . ': ' . $e->getMessage());
            return response()->json(['message' => 'Gagal mengirim email. Pastikan alamat email valid atau coba lagi nanti.'], 500);
        }

        // 2. Jika email sukses terkirim, baru catat di database
        PromoClaim::create([
            'email' => $request->email,
            'promo_code' => $code,
            'discount_value' => $discountValue
        ]);

        return response()->json([
            'message' => 'Promo berhasil diklaim!',
            'promo_code' => $code
        ]);
    }

    // Dipanggil dari PaymentPage (Saat Apply Promo)
    public function verify(Request $request)
    {
        $request->validate(['promo_code' => 'required|string']);
        $user = Auth::user();

        // Pastikan email user yang sedang login SAMA dengan email yang didaftarkan di pop-up
        $claim = PromoClaim::where('email', $user->email)
            ->where('promo_code', strtoupper($request->promo_code))
            ->first();

        if (!$claim) {
            return response()->json(['message' => 'Kode promo tidak ditemukan untuk alamat email Anda.'], 404);
        }
        if ($claim->is_used) {
            return response()->json(['message' => 'Kode promo ini sudah Anda gunakan sebelumnya.'], 400);
        }

        return response()->json([
            'message' => 'Kode promo valid!',
            'discount_value' => $claim->discount_value
        ]);
    }
}
