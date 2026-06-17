<?php

namespace App\Services;

use App\Contracts\ShippingGatewayInterface;

class ShippingFactory
{
    /**
     * Memutuskan API Pengiriman mana yang dipakai berdasarkan Negara Tujuan.
     */
    public static function make(string $destinationCountry = 'Indonesia'): ShippingGatewayInterface
    {
        // Standarisasi string untuk mencegah error huruf besar/kecil
        $country = strtolower(trim($destinationCountry));

        if ($country === 'indonesia') {
            return app(BiteshipService::class);
        }

        // Jika tujuan di luar negeri, delegasikan ke DHL Express
        return app(DHLService::class);
    }
}
