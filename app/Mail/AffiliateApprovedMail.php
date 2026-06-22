<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AffiliateApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $referralCode;

    public function __construct($user, $referralCode)
    {
        $this->user = $user;
        $this->referralCode = $referralCode;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Selamat! Pendaftaran Afiliasi Solher Anda Disetujui 🎉',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.affiliate_approved', // Kita akan buat view ini di langkah 2
        );
    }
}