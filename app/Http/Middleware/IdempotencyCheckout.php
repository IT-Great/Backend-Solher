<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class IdempotencyCheckout
{
    public function handle(Request $request, Closure $next)
    {
        // 1. Tangkap kode unik dari header React/Vue
        $key = $request->header('X-Idempotency-Key');

        if ($key) {
            $cacheKey = 'idempotency_request:' . $key;

            // 2. Jika kode ini sudah masuk ke Redis dalam 24 jam terakhir, TOLAK!
            if (Cache::has($cacheKey)) {
                return response()->json([
                    'status' => 'processing',
                    'message' => 'Pesanan ini sudah diterima dan sedang diproses. Mohon tunggu.'
                ], 202); // 202 Accepted
            }

            // 3. Jika belum ada, catat kodenya di Redis (Kunci selama 24 jam)
            Cache::put($cacheKey, true, 86400);
        }

        return $next($request);
    }
}