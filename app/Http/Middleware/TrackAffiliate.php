<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Cookie;

class TrackAffiliate
{
    public function handle(Request $request, Closure $next)
    {
        // 1. Cek apakah URL memiliki parameter '?ref='
        if ($request->has('ref')) {
            $referralCode = $request->query('ref');
            
            // 2. Cari apakah kode tersebut valid dan milik seorang afiliator
            $affiliate = User::where('referral_code', $referralCode)
                             ->where('is_affiliate', true)
                             ->first();

            if ($affiliate) {
                // 3. Jika valid, tanamkan Cookie ke browser pengunjung.
                // Cookie bernama 'solher_affiliate_id' ini akan bertahan selama 30 hari (43200 menit).
                Cookie::queue('solher_affiliate_id', $affiliate->id, 43200);
            }
        }

        return $next($request);
    }
}