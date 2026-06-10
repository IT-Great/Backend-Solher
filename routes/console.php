<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Robot akan berjalan setiap 2 jam sekali secara otomatis
Schedule::command('emails:send-batched-alerts')->everyTwoHours();

// Jalankan perintah ini setiap hari pada jam 10 pagi
Schedule::command('carts:abandoned-reminder')
    ->timezone('Asia/Jakarta')
    ->dailyAt('10:00')
    ->appendOutputTo(storage_path('logs/cart-reminder.log'));

// Update kurs mata uang 2 kali sehari (misal: 00:00 dan 12:00)
Schedule::command('currency:update-rates')
    ->timezone('Asia/Jakarta')
    ->twiceDaily(0, 12)
    ->appendOutputTo(storage_path('logs/currency-update.log'));
