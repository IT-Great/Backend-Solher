<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ChatMessageNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $sender;
    public $chatMessage;

    public function __construct($sender, $chatMessage)
    {
        $this->sender = $sender;
        $this->chatMessage = $chatMessage;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Pesan Baru dari ' . $this->sender->first_name . ' di Solher',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.chat_notification',
        );
    }
}
