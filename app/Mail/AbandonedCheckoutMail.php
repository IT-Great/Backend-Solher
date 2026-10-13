<?php

namespace App\Mail;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AbandonedCheckoutMail extends Mailable
{
    use Queueable, SerializesModels;

    public Transaction $transaction;
    public string $checkoutUrl;

    public function __construct(Transaction $transaction, string $checkoutUrl)
    {
        $this->transaction = $transaction;
        $this->checkoutUrl = $checkoutUrl;
    }

    public function build()
    {
        $orderId = $this->transaction->order_id;
        $firstName = $this->transaction->user->first_name ?? 'Valued Client';
        $amount = number_format($this->transaction->payment->amount ?? $this->transaction->total_amount, 0, ',', '.');
        $currency = $this->transaction->currency_code ?? 'IDR';

        $itemsHtml = '';
        foreach ($this->transaction->details as $detail) {
            $productName = $detail->product->name ?? 'Solhér Piece';
            $qty = $detail->quantity;
            $itemsHtml .= "<li style='margin-bottom: 6px; font-size: 13px; color: #333;'><strong>{$productName}</strong> (Qty: {$qty})</li>";
        }

        $html = "
        <div style='font-family: Georgia, serif; max-width: 600px; margin: 0 auto; padding: 40px 24px; background-color: #FAF9F8; color: #111; border: 1px solid #EAE6DF;'>
            <div style='text-align: center; margin-bottom: 32px;'>
                <p style='font-family: Arial, sans-serif; font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: #8c3b4a; margin: 0 0 8px 0; font-weight: bold;'>Reserved For You</p>
                <h1 style='font-size: 26px; font-weight: normal; margin: 0; letter-spacing: 1px;'>SOLHÉR</h1>
            </div>

            <p style='font-family: Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #333;'>
                Dear <strong>{$firstName}</strong>,
            </p>
            <p style='font-family: Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #555;'>
                Kami menyimpan pesanan Anda (<strong>#{$orderId}</strong>) secara khusus di keranjang reservasi kami. Mengingat ketersediaan koleksi Solhér yang terbatas, stok barang Anda hanya dapat kami tahan sebelum batas waktu pembayaran berakhir.
            </p>

            <div style='background: #FFFFFF; padding: 20px; border: 1px solid #E5E7EB; margin: 24px 0;'>
                <p style='font-family: Arial, sans-serif; font-size: 11px; text-transform: uppercase; letter-spacing: 2px; color: #888; margin-top: 0;'>Order Summary</p>
                <ul style='font-family: Arial, sans-serif; padding-left: 18px; margin: 12px 0;'>
                    {$itemsHtml}
                </ul>
                <hr style='border: none; border-top: 1px dashed #E5E7EB; margin: 16px 0;' />
                <p style='font-family: Arial, sans-serif; font-size: 14px; font-weight: bold; margin: 0; color: #111;'>
                    Total Due: {$currency} {$amount}
                </p>
            </div>

            <div style='text-align: center; margin: 32px 0;'>
                <a href='{$this->checkoutUrl}' style='display: inline-block; background-color: #111; color: #FFF; font-family: Arial, sans-serif; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 2.5px; padding: 16px 32px; text-decoration: none;'>
                    Complete Payment Now
                </a>
            </div>

            <p style='font-family: Arial, sans-serif; font-size: 11px; color: #888; text-align: center; margin-top: 32px;'>
                Jika Anda sudah menyelesaikan pembayaran, silakan abaikan pesan ini.
            </p>
        </div>";

        return $this->subject("Menunggu Pembayaran: Koleksi Solhér Anda (#{$orderId})")
                    ->html($html);
    }
}
