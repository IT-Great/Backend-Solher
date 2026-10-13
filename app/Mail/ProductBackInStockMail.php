<?php

namespace App\Mail;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ProductBackInStockMail extends Mailable
{
    use Queueable, SerializesModels;

    public Product $product;

    public function __construct(Product $product)
    {
        $this->product = $product;
    }

    public function build()
    {
        $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');
        $identifier = $this->product->slug ?: $this->product->id;
        $productUrl = "{$frontendUrl}/products/{$identifier}";
        $price = number_format($this->product->price, 0, ',', '.');

        $html = "
        <div style='font-family: Georgia, serif; max-width: 600px; margin: 0 auto; padding: 40px 24px; background-color: #FAF9F8; color: #111; border: 1px solid #EAE6DF; text-align: center;'>
            <p style='font-family: Arial, sans-serif; font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: #8c3b4a; margin: 0 0 8px 0; font-weight: bold;'>Back In Stock</p>
            <h1 style='font-size: 26px; font-weight: normal; margin: 0 0 24px 0; letter-spacing: 1px;'>SOLHÉR</h1>

            <div style='background: #FFF; padding: 24px; border: 1px solid #E5E7EB; margin-bottom: 24px;'>
                <h2 style='font-size: 20px; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 8px 0;'>{$this->product->name}</h2>
                <p style='font-family: Arial, sans-serif; font-size: 14px; font-weight: bold; color: #8c3b4a; margin: 0 0 16px 0;'>Rp {$price}</p>
                <p style='font-family: Arial, sans-serif; font-size: 13px; color: #555; line-height: 1.6; margin: 0;'>
                    Kabar gembira! Koleksi yang Anda tunggu kini telah tersedia kembali dalam jumlah terbatas (Sisa stok saat ini: <strong>{$this->product->stock}</strong>).
                </p>
            </div>

            <a href='{$productUrl}' style='display: inline-block; background-color: #111; color: #FFF; font-family: Arial, sans-serif; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 2.5px; padding: 16px 36px; text-decoration: none;'>
                Shop Before It's Gone
            </a>
        </div>";

        return $this->subject("Back in Stock: {$this->product->name} Kini Tersedia!")
                    ->html($html);
    }
}
