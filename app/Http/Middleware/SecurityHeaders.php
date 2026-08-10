<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Pastikan response adalah turunan dari Illuminate\Http\Response (mencegah error pada Binary File Download)
        if (method_exists($response, 'header')) {
            // Mencegah website dibungkus dalam iFrame (Anti-Clickjacking)
            $response->header('X-Frame-Options', 'DENY');

            // Memblokir serangan XSS dengan memaksa browser menolak eksekusi script berbahaya
            $response->header('X-XSS-Protection', '1; mode=block');

            // Mencegah browser menebak-nebak tipe MIME (Anti-MIME Sniffing)
            $response->header('X-Content-Type-Options', 'nosniff');

            // Memaksa seluruh koneksi menggunakan HTTPS secara ketat selama 1 tahun
            $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

            // Melindungi kebocoran data URL asal (Referrer) saat berpindah ke situs pihak ketiga
            $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        return $response;
    }
}
