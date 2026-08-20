<?php

namespace App\Actions\Checkout;

use App\Models\Product;
use App\Models\ProductStock;
use App\Models\TransactionDetail;
use Illuminate\Support\Str;

class DeductInventoryAction
{
    public function execute($transaction, $cartItems, array $finalItemPrices)
    {
        foreach ($cartItems as $item) {
            $product = Product::lockForUpdate()->find($item->product_id);
            if ($product->stock < $item->quantity) {
                throw new \Exception("Stock {$product->name} insufficient");
            }

            $savedPrice = $finalItemPrices[$item->id] ?? $product->price;

            TransactionDetail::create([
                'transaction_id' => $transaction->id,
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'price' => $savedPrice,
                'color' => $item->color,
            ]);

            // Logika FIFO Batch Stok
            $remainingQuantityToDeduct = $item->quantity;
            $totalBatchQuantity = ProductStock::where('product_id', $product->id)->sum('quantity');
            $legacyStock = $product->stock - $totalBatchQuantity;

            if ($legacyStock > 0) {
                $takeFromLegacy = min($remainingQuantityToDeduct, $legacyStock);
                ProductStock::create([
                    'product_id' => $product->id,
                    'batch_code' => 'SYS-LEGACY-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4)),
                    'quantity' => 0,
                    'initial_quantity' => $takeFromLegacy,
                ]);
                $remainingQuantityToDeduct -= $takeFromLegacy;
            }

            if ($remainingQuantityToDeduct > 0) {
                $activeBatches = ProductStock::where('product_id', $product->id)
                    ->where('quantity', '>', 0)
                    ->orderBy('created_at', 'asc')
                    ->lockForUpdate()
                    ->get();

                foreach ($activeBatches as $batch) {
                    if ($remainingQuantityToDeduct <= 0) break;

                    if ($batch->quantity >= $remainingQuantityToDeduct) {
                        $batch->decrement('quantity', $remainingQuantityToDeduct);
                        $remainingQuantityToDeduct = 0;
                    } else {
                        $remainingQuantityToDeduct -= $batch->quantity;
                        $batch->update(['quantity' => 0]);
                    }
                }
            }

            if ($remainingQuantityToDeduct > 0) {
                throw new \Exception("System error: Stock batch mismatch for '{$product->name}'.");
            }

            $product->decrement('stock', $item->quantity);
        }
    }
}
