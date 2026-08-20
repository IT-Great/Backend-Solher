<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookLog extends Model
{
    protected $fillable = [
        'gateway',
        'event_id',
        'status',
        'payload',
        'response_message',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}