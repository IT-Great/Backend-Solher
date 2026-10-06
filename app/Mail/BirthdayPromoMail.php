<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BirthdayPromoMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $promoCode;
    public $discountValue;
    public $expiresAt;

    public function __construct($user, $promoCode, $discountValue, $expiresAt)
    {
        $this->user = $user;
        $this->promoCode = $promoCode;
        $this->discountValue = $discountValue;
        $this->expiresAt = $expiresAt;
    }

    public function build()
    {
        return $this->subject('Selamat Ulang Tahun dari Solher! Ini Hadiah Spesial Anda 🎉')
                    ->view('emails.birthday_promo');
    }
}
