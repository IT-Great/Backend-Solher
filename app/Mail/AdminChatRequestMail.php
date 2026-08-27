<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Contracts\Queue\ShouldQueue;

class AdminChatRequestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $customer;

    public function __construct(User $customer)
    {
        $this->customer = $customer;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🚨 BANTUAN LIVE CHAT: ' . $this->customer->first_name . ' Membutuhkan Admin',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin_chat_request',
        );
    }
}
