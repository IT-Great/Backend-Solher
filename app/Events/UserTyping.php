<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserTyping implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $typer_id;
    public $receiver_id;

    public function __construct($typer_id, $receiver_id)
    {
        $this->typer_id = $typer_id;
        $this->receiver_id = $receiver_id;
    }

    public function broadcastOn()
    {
        // Pancarkan ke channel penerima agar muncul tulisan "typing..."
        return new PrivateChannel('chat.' . $this->receiver_id);
    }

    public function broadcastAs()
    {
        return 'user.typing';
    }
}
