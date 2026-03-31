<?php

namespace App\Mail;

// use App\Models\Product;
// use Illuminate\Bus\Queueable;
// use Illuminate\Mail\Mailable;
// use Illuminate\Mail\Mailables\Content;
// use Illuminate\Mail\Mailables\Address;
// use Illuminate\Queue\SerializesModels;
// use Illuminate\Mail\Mailables\Envelope;

// class NewProductAlertMail extends Mailable
// {
//     use Queueable, SerializesModels;

//     public $product;

//     public function __construct(Product $product)
//     {
//         $this->product = $product;
//     }

//     public function envelope(): Envelope
//     {
//         return new Envelope(
//             from: new Address('solherbag@gmail.com', 'Solher'),
//             subject: 'New Arrival: ' . $this->product->name . ' is Here!',
//         );
//     }

//     public function content(): Content
//     {
//         return new Content(view: 'emails.new_product_alert');
//     }
// }

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Database\Eloquent\Collection; // <--- BARU

class NewProductAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    // Ubah menjadi jamak (products)
    public $products;

    // Terima kumpulan produk, bukan 1 produk
    public function __construct(Collection $products)
    {
        $this->products = $products;
    }

    public function envelope(): Envelope
    {
        $count = $this->products->count();

        // Logika Subject Dinamis (Jika 1 produk vs Jika lebih dari 1 produk)
        $subjectText = $count > 1
            ? "New Arrivals: We just added {$count} new pieces to Solher!"
            : "New Arrival: " . $this->products->first()->name . " is Here!";

        return new Envelope(
            from: new Address('solherbag@gmail.com', 'Solher'),
            subject: $subjectText,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.new_product_alert');
    }
}
