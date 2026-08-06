<?php

namespace App\Mail;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ShippingUpdateMail extends Mailable
{
    use Queueable, SerializesModels;

    public $transaction;
    public $statusPesan;
    public $statusJudul;

    /**
     * Create a new message instance.
     */
    public function __construct(Transaction $transaction, $statusPesan, $statusJudul)
    {
        $this->transaction = $transaction;
        $this->statusPesan = $statusPesan;
        $this->statusJudul = $statusJudul;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Update Pengiriman Pesanan [{$this->transaction->order_id}]: {$this->statusJudul}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.shipping_update',
        );
    }
}