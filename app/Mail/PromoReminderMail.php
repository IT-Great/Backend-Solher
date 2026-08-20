<?php
namespace App\Mail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PromoReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public $promoCode;
    public $discountValue;

    public function __construct($promoCode, $discountValue)
    {
        $this->promoCode = $promoCode;
        $this->discountValue = $discountValue;
    }

    public function envelope(): Envelope
    {
        // Subjek email ini dirancang khusus untuk memancing (FOMO)
        return new Envelope(subject: '🚨 Tinggal 1 Jam Lagi! Voucher Rp 250.000 Anda Segera Hangus');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.promo_reminder');
    }
}