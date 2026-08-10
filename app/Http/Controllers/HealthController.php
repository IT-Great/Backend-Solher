<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class HealthController extends Controller
{
    public function check()
    {
        $status = 'OK';
        $services = [
            'database' => 'disconnected',
            'redis_cache' => 'disconnected'
        ];

        // 1. Cek Koneksi Database
        try {
            DB::connection()->getPdo();
            $services['database'] = 'connected';
        } catch (\Exception $e) {
            $status = 'ERROR';
        }

        // 2. Cek Koneksi Cache/Redis
        try {
            Cache::store()->get('health_check');
            $services['redis_cache'] = 'connected';
        } catch (\Exception $e) {
            $status = 'ERROR';
        }

        return response()->json([
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'services' => $services,
            'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB'
        ], $status === 'OK' ? 200 : 500);
    }
}
