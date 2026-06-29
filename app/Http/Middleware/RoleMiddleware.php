<?php

// namespace App\Http\Middleware;

// use Closure;
// use Illuminate\Http\Request;
// use Symfony\Component\HttpFoundation\Response;

// class RoleMiddleware
// {
//     /**
//      * Handle an incoming request.
//      *
//      * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
//      * @param  string  ...$roles
//      */
//     public function handle(Request $request, Closure $next, ...$roles): Response
//     {
//         $user = $request->user();

//         if (!$user) {
//             return response()->json(['message' => 'Unauthenticated.'], 401);
//         }

//         // Jika user adalah superadmin, izinkan selalu melewati batas role apapun
//         if ($user->usertype === 'superadmin') {
//             return $next($request);
//         }

//         // Cek apakah usertype saat ini ada di dalam daftar roles yang diizinkan route
//         if (!in_array($user->usertype, $roles)) {
//             return response()->json([
//                 'message' => 'Forbidden. You do not have permission to access this resource.'
//             ], 403);
//         }

//         return $next($request);
//     }
// }

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     * $module: Nama modul di database (misal: 'sales_report')
     * $actionOverride: Jika ingin memaksa aksi tertentu (misal POST tapi untuk 'update')
     */
    public function handle(Request $request, Closure $next, $module, $actionOverride = null): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // 1. BYPASS MUTLAK: Superadmin bisa mengakses apa saja
        if ($user->usertype === 'superadmin') {
            return $next($request);
        }

        // 2. BYPASS PROFIL KHUSUS: Modul 'all_staff' izinkan siapa saja asal bukan user biasa
        if ($module === 'all_staff') {
            if (in_array($user->usertype, ['admin', 'gudang', 'accounting', 'cs'])) {
                return $next($request);
            }
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // 3. AUTO-REST TRANSLATION: Terjemahkan HTTP Request ke aksi C-R-U-D
        $action = $actionOverride;

        if (!$action) {
            $method = $request->method();
            $action = 'read'; // Default untuk GET

            if ($method === 'POST') {
                $action = 'create';
            } elseif ($method === 'PUT' || $method === 'PATCH') {
                $action = 'update';
            } elseif ($method === 'DELETE') {
                $action = 'delete';
            }
        }

        // 4. CEK KE DATABASE MATRIKS AKSES
        $hasAccess = DB::table('role_permissions')
            ->where('role', $user->usertype)
            ->where('module', $module)
            ->where('action', $action)
            ->exists();

        if (!$hasAccess) {
            return response()->json([
                'message' => "Forbidden. You do not have '{$action}' permission for '{$module}' module."
            ], 403);
        }

        return $next($request);
    }
}
