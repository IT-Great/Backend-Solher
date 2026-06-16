<?php

namespace App\Services;

use App\Contracts\PaymentGatewayInterface;

class PaymentFactory
{
    /**
     * Memutuskan API Gateway mana yang dipakai berdasarkan mata uang.
     * Menggunakan dependency injection container Laravel: app(ClassName::class)
     */
    public static function make(string $currency): PaymentGatewayInterface
    {
        // Pastikan huruf besar semua untuk menghindari error case-sensitive
        if (strtoupper($currency) === 'IDR') {
            return app(XenditService::class);
        }

        // Jika USD, SGD, EUR, dll, lempar ke Stripe
        return app(StripeService::class);
    }
}
