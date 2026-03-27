<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PromoClaim;
use Illuminate\Support\Facades\Auth;

class PromoController extends Controller
{
    // Dipanggil dari HomePage (Pop-up)
    public function claim(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $code = 'SOLHERBARU'; // Hardcoded kode promo kampanye saat ini

        $exists = PromoClaim::where('email', $request->email)->where('promo_code', $code)->first();
        if ($exists) {
            return response()->json(['message' => 'Email ini sudah mengklaim promo sebelumnya.'], 400);
        }

        PromoClaim::create([
            'email' => $request->email,
            'promo_code' => $code,
            'discount_value' => 25000 // Nilai diskon Rp 25.000
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
