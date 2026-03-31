<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Robot akan berjalan setiap 2 jam sekali secara otomatis
Schedule::command('emails:send-batched-alerts')->everyTwoHours();
