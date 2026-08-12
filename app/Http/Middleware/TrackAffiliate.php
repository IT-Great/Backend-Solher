<?php

// namespace App\Http\Middleware;

// use Closure;
// use Illuminate\Http\Request;
// use App\Models\User;
// use Illuminate\Support\Facades\Cookie;

// class TrackAffiliate
// {
//     public function handle(Request $request, Closure $next)
//     {
//         // 1. Cek apakah URL memiliki parameter '?ref='
//         if ($request->has('ref')) {
//             $referralCode = $request->query('ref');

//             // 2. Cari apakah kode tersebut valid dan milik seorang afiliator
//             $affiliate = User::where('referral_code', $referralCode)
//                              ->where('is_affiliate', true)
//                              ->first();

//             if ($affiliate) {
//                 // 3. Jika valid, tanamkan Cookie ke browser pengunjung.
//                 // Cookie bernama 'solher_affiliate_id' ini akan bertahan selama 30 hari (43200 menit).
//                 Cookie::queue('solher_affiliate_id', $affiliate->id, 43200);
//             }
//         }

//         return $next($request);
//     }
// }

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TrackAffiliate
{
    public function handle(Request $request, Closure $next)
    {
        // 1. Cek apakah URL memiliki parameter '?ref='
        if ($request->has('ref')) {
            $referralCode = $request->query('ref');
            $ipAddress = $request->ip();
            $userAgent = $request->userAgent();

            // =========================================================================
            // 🛡️ LAYER 1: BOT & SCRAPER BLOCKER (Invalid Traffic)
            // =========================================================================
            // Abaikan jika yang melakukan klik adalah Bot Mesin Pencari atau Scraper
            if (preg_match('/bot|crawl|curl|dataprovider|search|get|spider|find|java|majestyc|yandex|yahoo/i', strtolower($userAgent))) {
                return $next($request);
            }

            // Cari apakah kode tersebut valid dan milik seorang afiliator
            $affiliate = User::where('referral_code', $referralCode)
                             ->where('is_affiliate', true)
                             ->first();

            if ($affiliate) {

                // =========================================================================
                // 🛡️ LAYER 2: ANTI SELF-REFERRAL (Beli pakai link sendiri)
                // =========================================================================
                // Jika user sedang login dan mengklik link miliknya sendiri, JANGAN set cookie
                if (auth()->check() && auth()->id() === $affiliate->id) {
                    Log::warning("Anti-Fraud: Affiliate {$affiliate->email} mencoba klik link referralnya sendiri dari IP {$ipAddress}.");
                    return $next($request);
                }

                // =========================================================================
                // 🛡️ LAYER 3: ANTI COOKIE STUFFING (Rate Limiting per IP)
                // =========================================================================
                // Batasi 1 IP Address hanya bisa memicu penanaman cookie maksimal 5 kali per jam.
                // Jika lebih dari itu, kemungkinan ada bot atau orang yang sengaja melakukan spam.
                $rateLimitKey = 'affiliate_click_' . $ipAddress . '_' . $referralCode;
                $clickCount = Cache::get($rateLimitKey, 0);

                if ($clickCount > 5) {
                    Log::warning("Anti-Fraud: Terdeteksi Spam Klik (Cookie Stuffing) dari IP {$ipAddress} untuk kode {$referralCode}.");
                    return $next($request);
                }

                // Tambah jumlah klik dan set kadaluarsa cache 1 jam
                Cache::put($rateLimitKey, $clickCount + 1, now()->addHour());

                // =========================================================================
                // ✅ JIKA LOLOS SEMUA UJIAN, TANAMKAN COOKIE (Valid selama 30 Hari)
                // =========================================================================
                Cookie::queue('solher_affiliate_id', $affiliate->id, 43200);
            }
        }

        return $next($request);
    }
}
