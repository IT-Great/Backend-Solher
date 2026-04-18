<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AbandonedCartMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $carts;

    public function __construct($user, $carts)
    {
        $this->user = $user;
        $this->carts = $carts;
    }

    public function build()
    {
        return $this->subject('Ada barang yang tertinggal di keranjangmu! 🛍️')
                    ->view('emails.abandoned_cart');
    }
}
