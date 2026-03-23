<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RefundResultMail extends Mailable
{
    use Queueable, SerializesModels;

    public $transaction;
    public $action; // 'approve' atau 'reject'

    public function __construct($transaction, $action)
    {
        $this->transaction = $transaction;
        $this->action = $action;
    }

    public function build()
    {
        $subject = $this->action === 'approve'
            ? 'Kabar Baik! Pengajuan Refund Disetujui - Solher Bag'
            : 'Pembaruan Status Pengajuan Refund - Solher Bag';

        return $this->subject($subject)
                    ->view('emails.refund_result');
    }
}
