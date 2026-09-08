<?php

// use Illuminate\Foundation\Application;
// use Illuminate\Foundation\Configuration\Exceptions;
// use Illuminate\Foundation\Configuration\Middleware;

// return Application::configure(basePath: dirname(__DIR__))
//     ->withRouting(
//         channels: __DIR__.'/../routes/channels.php',
//         web: __DIR__ . '/../routes/web.php',
//         api: __DIR__ . '/../routes/api.php',
//         commands: __DIR__ . '/../routes/console.php',
//         health: '/up',
//     )
//     ->withMiddleware(function (Middleware $middleware): void {
//         $middleware->alias([
//             'admin' => \App\Http\Middleware\AdminMiddleware::class,
//         ]);
//     })
//     ->withExceptions(function (Exceptions $exceptions): void {
//         //
//     })->create();

use Sentry\Laravel\Integration;
use Illuminate\Foundation\Application;
use App\Http\Middleware\SentryUserContext;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        channels: __DIR__.'/../routes/channels.php',
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            // Pertahankan middleware admin bawaan Anda jika masih dipakai di tempat lain
            'admin' => \App\Http\Middleware\AdminMiddleware::class,

            // [BARU] Daftarkan middleware Role untuk RBAC (Role-Based Access Control)
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'module' => \App\Http\Middleware\RoleMiddleware::class,
        ]);

        $middleware->api(append: [
            SentryUserContext::class,
        ]);

        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // // 👇 TAMBAHKAN BLOK INI UNTUK PENGECUALIAN MAINTENANCE MODE 👇
        // $middleware->preventRequestsDuringMaintenance(except: [
        //     'api/biteship/callback',
        //     'api/payments/callback',
        //     'api/payments/stripe-webhook',
        //     'api/payments/paypal-webhook',
        //     'api/admin/*', // 👇 WAJIB: Agar tombol "Bring Back" tidak ikut terblokir!
        // ]);
        // // 👆 ======================================================= 👆

        $middleware->append(\App\Http\Middleware\CheckMaintenanceMode::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
        Integration::handles($exceptions);
    })->create();
