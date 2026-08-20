<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow; // Gunakan ShouldBroadcastNow agar instan
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DashboardUpdated implements ShouldBroadcastNow 
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct()
    {
        // Anda bisa melempar pesan/data spesifik di sini jika perlu
    }

    public function broadcastOn()
    {
        // Sesuaikan dengan nama channel di frontend Vue Anda
        return new Channel('admin-dashboard'); 
    }

    public function broadcastAs()
    {
        // Nama event yang ditangkap di .listen('DashboardUpdated') Vue Anda
        return 'DashboardUpdated';
    }
}