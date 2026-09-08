<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CheckMaintenanceMode
{
    public function handle(Request $request, Closure $next)
    {
        // Mengecek apakah tombol 'Takedown' di dasbor sedang aktif
        if (Cache::get('system_maintenance', false)) {

            // 1. Biarkan Webhook Xendit & Biteship lewat dengan aman
            if ($request->is('api/biteship/*', 'api/payments/*')) {
                return $next($request);
            }

            // 2. Biarkan rute login dan Dasbor Admin tetap jalan
            if ($request->is('api/admin/*', 'api/login', 'api/user')) {
                return $next($request);
            }

            // 3. Jika Anda sedang login sebagai Admin/Superadmin di Frontend, izinkan lewat!
            if ($request->user() && in_array($request->user()->usertype, ['superadmin', 'admin'])) {
                return $next($request);
            }

            // 4. Pengunjung biasa akan ditolak dengan kode 503
            return response()->json([
                'message' => 'Service Unavailable',
                'maintenance' => true
            ], 503);
        }

        return $next($request);
    }
}
