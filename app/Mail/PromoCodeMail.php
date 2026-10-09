<?php

// namespace App\Mail;

// use Illuminate\Bus\Queueable;
// use Illuminate\Mail\Mailable;
// use Illuminate\Queue\SerializesModels;

// class PromoCodeMail extends Mailable
// {
//     use Queueable, SerializesModels;

//     public $promoCode;
//     public $discountValue;

//     public function __construct($promoCode, $discountValue)
//     {
//         $this->promoCode = $promoCode;
//         $this->discountValue = $discountValue;
//     }

//     public function build()
//     {
//         return $this->subject('Your SolHer Exclusive Promo Code!')
//                     ->view('emails.promo_code');
//     }
// }

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PromoCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public $promoCode;
    public $discountValue;
    public $expiresAt; // [BARU]

    public $currencySymbol;

    public function __construct($promoCode, $discountValue, $expiresAt, $currencySymbol) // [BARU]
    {
        $this->promoCode = $promoCode;
        $this->discountValue = $discountValue;
        $this->expiresAt = $expiresAt; // [BARU]
        $this->currencySymbol = $currencySymbol; // [BARU]
    }

    public function build()
    {
        return $this->subject('Your SolHer Exclusive Promo Code!')
                    ->view('emails.promo_code');
    }
}
