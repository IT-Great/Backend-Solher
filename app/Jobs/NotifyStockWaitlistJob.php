<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\StockWaitlist;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use App\Mail\ProductBackInStockMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class NotifyStockWaitlistJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $productId;

    public function __construct(int $productId)
    {
        $this->productId = $productId;
    }

    public function handle(): void
    {
        if (!Schema::hasTable('stock_waitlists')) {
            return;
        }

        $product = Product::find($this->productId);
        if (!$product || $product->stock <= 0) {
            return;
        }

        $waitingList = StockWaitlist::where('product_id', $this->productId)
            ->where('is_notified', false)
            ->get();

        if ($waitingList->isEmpty()) {
            return;
        }

        foreach ($waitingList as $entry) {
            try {
                Mail::to($entry->email)->send(new ProductBackInStockMail($product));
                $entry->update([
                    'is_notified' => true,
                    'notified_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::error("Gagal kirim email Waitlist ke {$entry->email}: " . $e->getMessage());
            }
        }
    }
}
