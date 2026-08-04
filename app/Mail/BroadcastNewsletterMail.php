<?php

// namespace App\Mail;

// use Illuminate\Bus\Queueable;
// use Illuminate\Mail\Mailable;
// use Illuminate\Mail\Mailables\Content;
// use Illuminate\Mail\Mailables\Envelope;
// use Illuminate\Queue\SerializesModels;

// class BroadcastNewsletterMail extends Mailable
// {
//     use Queueable, SerializesModels;

//     public $subjectLine;
//     public $htmlContent;
//     public $subscriberEmail;

//     public function __construct($subjectLine, $htmlContent, $subscriberEmail)
//     {
//         $this->subjectLine = $subjectLine;
//         $this->htmlContent = $htmlContent;
//         $this->subscriberEmail = $subscriberEmail;
//     }

//     public function envelope(): Envelope
//     {
//         return new Envelope(
//             subject: $this->subjectLine, // Subjek dinamis dari input admin
//         );
//     }

//     public function content(): Content
//     {
//         return new Content(
//             view: 'emails.broadcast_campaign', // File blade HTML
//         );
//     }
// }

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BroadcastNewsletterMail extends Mailable
{
    use Queueable, SerializesModels;

    public $subjectLine;
    public $htmlContent;
    public $subscriberEmail;
    public $unsubscribeUrl; // 👇 Tambahan Baru
    public $trackingUrl;

    public function __construct($subjectLine, $htmlContent, $subscriberEmail, $unsubscribeUrl, $trackingUrl)
    {
        $this->subjectLine = $subjectLine;
        $this->htmlContent = $htmlContent;
        $this->subscriberEmail = $subscriberEmail;
        $this->unsubscribeUrl = $unsubscribeUrl; // 👇 Simpan URL
        $this->trackingUrl = $trackingUrl; // 👇 Simpan URL Pixel
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.broadcast_campaign',
        );
    }
}
