<?php

namespace App\Services;

class PaymentFactory
{
    /**
     * Memutuskan API Gateway mana yang dipakai berdasarkan mata uang.
     * Menggunakan dependency injection container Laravel: app(ClassName::class)
     */
    public static function make(string $currency)
    {
        // Pastikan huruf besar semua untuk menghindari error case-sensitive
        if (strtoupper($currency) === 'IDR') {
            return app(XenditService::class);
        }

        // Jika USD, SGD, EUR, dll, lempar ke Stripe
        // return app(StripeService::class);
        return app(PayPalService::class);
        // return new PayPalService();
    }
}
