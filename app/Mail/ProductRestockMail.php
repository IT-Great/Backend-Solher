<?php

namespace App\Mail;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProductRestockMail extends Mailable
{
    use Queueable, SerializesModels;

    public $product;
    public $addedQuantity;

    public function __construct(Product $product, $addedQuantity)
    {
        $this->product = $product;
        $this->addedQuantity = $addedQuantity;
    }

    public function build()
    {
        return $this->subject("Kabar Gembira! {$this->product->name} Kembali Tersedia 🎉")
                    ->view('emails.product_restock');
    }
}
