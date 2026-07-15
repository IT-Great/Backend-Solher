<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 1. Mencegah Lazy Loading (N+1 Problem)
        // Jika aplikasi dijalankan di mode lokal (bukan production),
        // Laravel akan memunculkan error mencolok jika ada N+1 query.
        Model::preventLazyLoading(! app()->isProduction());

        // 2. Mencegah Update Massal tanpa fillable (Mass Assignment Exception)
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        // 3. Log Peringatan Jika Ada Query yang Sangat Lambat (Di atas 500ms)
        // Menggunakan DB::listen yang dijamin 100% kompatibel dan aman
        DB::listen(function ($query) {
            if ($query->time > 500) {
                Log::warning("Slow Database Query Detected [{$query->time}ms]: {$query->sql}");
            }
        });

        if ($this->app->environment('production')) {
            \URL::forceScheme('https');
        }

        // Panggil fungsi arsitektur pembatasan API di sini
        $this->configureRateLimiting();
    }

    /**
     * Konfigurasi arsitektur Rate Limiting dengan standar hierarki risiko.
     */
    protected function configureRateLimiting(): void
    {
        // 1. General API (Batas wajar untuk aktivitas normal)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // 2. Autentikasi (Pencegahan Brute-Force pada Login/Register)
        RateLimiter::for('auth-limiter', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // 3. OTP & Password (Pencegahan Email/SMS Bombing)
        RateLimiter::for('otp-limiter', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        // 4. Checkout & Payment (Pencegahan Spam Order)
        RateLimiter::for('checkout-limiter', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });
    }
}
