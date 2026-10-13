<?php

// namespace App\Jobs;

// use App\Models\Product;
// use App\Models\StockWaitlist;
// use Illuminate\Bus\Queueable;
// use Illuminate\Support\Facades\Log;
// use App\Mail\ProductBackInStockMail;
// use Illuminate\Support\Facades\Mail;
// use Illuminate\Support\Facades\Schema;
// use Illuminate\Queue\SerializesModels;
// use Illuminate\Queue\InteractsWithQueue;
// use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Foundation\Bus\Dispatchable;

// class NotifyStockWaitlistJob implements ShouldQueue
// {
//     use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

//     public int $productId;

//     public function __construct(int $productId)
//     {
//         $this->productId = $productId;
//     }

//     public function handle(): void
//     {
//         if (!Schema::hasTable('stock_waitlists')) {
//             return;
//         }

//         $product = Product::find($this->productId);
//         if (!$product || $product->stock <= 0) {
//             return;
//         }

//         $waitingList = StockWaitlist::where('product_id', $this->productId)
//             ->where('is_notified', false)
//             ->get();

//         if ($waitingList->isEmpty()) {
//             return;
//         }

//         foreach ($waitingList as $entry) {
//             try {
//                 Mail::to($entry->email)->send(new ProductBackInStockMail($product));
//                 $entry->update([
//                     'is_notified' => true,
//                     'notified_at' => now(),
//                 ]);
//             } catch (\Throwable $e) {
//                 Log::error("Gagal kirim email Waitlist ke {$entry->email}: " . $e->getMessage());
//             }
//         }
//     }
// }

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
    public bool $isPriorityPhase;

    public function __construct(int $productId, bool $isPriorityPhase = true)
    {
        $this->productId = $productId;
        $this->isPriorityPhase = $isPriorityPhase;
    }

    public function handle(): void
    {
        if (!Schema::hasTable('stock_waitlists')) {
            return;
        }

        $product = Product::find($this->productId);

        // Cek pengaman ganda: Batalkan job jika dihabiskan oleh fase sebelumnya
        if (!$product || $product->stock <= 0) {
            return;
        }

        // Ambil semua daftar tunggu yang belum dinotifikasi beserta data usernya
        $waitingList = StockWaitlist::with('user')
            ->where('product_id', $this->productId)
            ->where('is_notified', false)
            ->get();

        if ($waitingList->isEmpty()) {
            return;
        }

        $toNotifyNow = collect();
        $hasRemaining = false;

        // Terapkan Gated Priority HANYA jika masuk fase prioritas & stok sangat terbatas
        if ($this->isPriorityPhase && $product->stock <= 5) {
            foreach ($waitingList as $entry) {
                $user = $entry->user;
                // Definisi Member Héritage (Poin >= 10000)
                $isHeritage = $user && $user->is_membership && $user->point >= 10000;

                if ($isHeritage) {
                    $toNotifyNow->push($entry);
                } else {
                    $hasRemaining = true;
                }
            }

            // Jika ada pelanggan reguler, jadwalkan batch Publik dalam 2 Jam
            if ($hasRemaining) {
                self::dispatch($this->productId, false)->delay(now()->addHours(2));
            }

            // Bypass Cerdas: Jika ternyata TIDAK ADA member Héritage di antrean,
            // batalkan penahanan 2 jam dan langsung rilis ke pelanggan reguler saat ini juga.
            if ($toNotifyNow->isEmpty() && $hasRemaining) {
                $toNotifyNow = $waitingList;
            }

        } else {
            // Jika stok melimpah (> 5) atau sudah masuk waktu Fase Publik
            $toNotifyNow = $waitingList;
        }

        // Proses pengiriman
        foreach ($toNotifyNow as $entry) {
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

        if ($toNotifyNow->isNotEmpty()) {
            $phaseName = ($this->isPriorityPhase && $product->stock <= 5) ? 'Héritage VIP' : 'Public';
            Log::info("Restock Alert [{$phaseName}] dikirim untuk Produk ID {$product->id} kepada {$toNotifyNow->count()} pelanggan.");
        }
    }
}
