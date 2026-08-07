<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow; // Pakai 'Now' agar instan
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShippingStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $transaction;
    public $message;

    public function __construct(Transaction $transaction, $message)
    {
        // Data yang dikirim ke frontend via WebSockets
        $this->transaction = $transaction;
        $this->message = $message;
    }

    /**
     * Tentukan Channel WebSockets mana yang akan dipancarkan.
     */
    public function broadcastOn()
    {
        // Channel Privat khusus untuk user ini.
        // Format: private-user.{id}
        return new Channel('user.' . $this->transaction->user_id);

        // Catatan: Jika ingin public channel untuk uji coba, pakai new Channel('shipping-updates')
    }

    /**
     * Nama Event yang didengarkan oleh Vue (Echo).
     */
    public function broadcastAs()
    {
        return 'shipping.updated';
    }
}
