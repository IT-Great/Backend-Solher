<?php

namespace App\Jobs;

use App\Models\Product;
use App\Mail\LowStockAlertMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendLowStockAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $product;

    /**
     * Create a new job instance.
     */
    public function __construct(Product $product)
    {
        $this->product = $product;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Mail::to('gycora.essence@gmail.com')->send(new LowStockAlertMail($this->product));
        } catch (\Exception $e) {
            Log::error('Queue Error (LowStockAlert): ' . $e->getMessage());
        }
    }
}
