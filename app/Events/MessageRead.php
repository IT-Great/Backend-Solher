<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $reader_id;
    public $sender_id;

    public function __construct($reader_id, $sender_id)
    {
        $this->reader_id = $reader_id;
        $this->sender_id = $sender_id;
    }

    public function broadcastOn()
    {
        // Pancarkan ke channel pengirim agar layarnya berubah jadi centang biru
        return new PrivateChannel('chat.' . $this->sender_id);
    }

    public function broadcastAs()
    {
        return 'message.read';
    }
}
