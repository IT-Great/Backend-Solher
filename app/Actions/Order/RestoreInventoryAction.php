<?php

namespace App\Actions\Order;

use App\Models\Product;
use Illuminate\Support\Str;
use App\Models\ProductStock;

class RestoreInventoryAction
{
    /**
     * Mengembalikan stok barang menggunakan algoritma FIFO Restore & Anti Race Condition.
     */
    public function execute($productId, $quantityToRestore): void
    {
        if ($quantityToRestore <= 0) {
            return;
        }

        $product = Product::lockForUpdate()->find($productId);
        if (!$product) {
            return;
        }

        $remainingToRestore = $quantityToRestore;

        $incompleteBatches = ProductStock::where('product_id', $productId)
            ->whereColumn('quantity', '<', 'initial_quantity')
            ->orderBy('created_at', 'asc')
            ->lockForUpdate()
            ->get();

        foreach ($incompleteBatches as $batch) {
            if ($remainingToRestore <= 0) {
                break;
            }

            $spaceAvailable = $batch->initial_quantity - $batch->quantity;

            if ($spaceAvailable >= $remainingToRestore) {
                $batch->increment('quantity', $remainingToRestore);
                $remainingToRestore = 0;
            } else {
                $batch->increment('quantity', $spaceAvailable);
                $remainingToRestore -= $spaceAvailable;
            }
        }

        if ($remainingToRestore > 0) {
            $latestBatch = ProductStock::where('product_id', $productId)
                ->orderBy('created_at', 'desc')
                ->lockForUpdate()
                ->first();

            if ($latestBatch) {
                $latestBatch->increment('quantity', $remainingToRestore);
                $latestBatch->increment('initial_quantity', $remainingToRestore);
            } else {
                ProductStock::create([
                    'product_id' => $productId,
                    'batch_code' => 'RET-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4)),
                    'quantity' => $remainingToRestore,
                    'initial_quantity' => $remainingToRestore,
                ]);
            }
        }

        $product->increment('stock', $quantityToRestore);
    }
}
