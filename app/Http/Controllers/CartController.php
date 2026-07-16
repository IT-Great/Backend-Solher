<?php

// namespace App\Http\Controllers;

// use App\Models\Cart;
// use App\Models\Product;
// use Illuminate\Http\Request;
// use App\Http\Controllers\Controller;

// class CartController extends Controller
// {
//     public function index(Request $request)
//     {
//         $carts = Cart::with('product')->where('user_id', $request->user()->id)->latest()->get();
//         return response()->json($carts);
//     }

//     public function store(Request $request)
//     {
//         $request->validate([
//             'product_id' => 'required|exists:products,id',
//             'quantity' => 'required|integer|min:1',
//             'color' => 'nullable|string|max:50' // <--- BARU
//         ]);

//         $product = Product::findOrFail($request->product_id);
//         $user = $request->user();

//         // [PERBAIKAN KUNCI] Cari apakah produk DENGAN WARNA YANG SAMA sudah ada di keranjang
//         $cartItem = Cart::where('user_id', $user->id)
//             ->where('product_id', $product->id)
//             ->where(function($query) use ($request) {
//                 if ($request->color) {
//                     $query->where('color', $request->color);
//                 } else {
//                     $query->whereNull('color');
//                 }
//             })
//             ->first();

//         $newQuantity = $cartItem ? $cartItem->quantity + $request->quantity : $request->quantity;

//         // VALIDASI STOK
//         if ($newQuantity > $product->stock) {
//             return response()->json(['message' => 'Quantity exceeds available stock!'], 422);
//         }

//         $price = $product->discount_price ?? $product->price;

//         if ($cartItem) {
//             $cartItem->update([
//                 'quantity' => $newQuantity,
//                 'gross_amount' => $newQuantity * $price
//             ]);
//         } else {
//             // [PERBAIKAN] Pastikan hasil create() ditampung ke dalam variabel $cartItem
//             $cartItem = Cart::create([
//                 'user_id' => $user->id,
//                 'product_id' => $product->id,
//                 'quantity' => $request->quantity,
//                 'gross_amount' => $request->quantity * $price,
//                 'color' => $request->color // <--- BARU (Simpan warna)
//             ]);
//         }

//         // [PERBAIKAN KUNCI] Kembalikan ID cart asli ke frontend!
//         return response()->json([
//             'message' => 'Added to cart successfully',
//             'cart_id' => $cartItem->id // <--- INI YANG HILANG SEBELUMNYA!
//         ]);
//     }

//     public function update(Request $request, $id)
//     {
//         $cart = Cart::with('product')->findOrFail($id);

//         if ($request->quantity > $cart->product->stock) {
//             return response()->json(['message' => 'Stock limited!'], 422);
//         }

//         $price = $cart->product->discount_price ?? $cart->product->price;
//         $cart->update([
//             'quantity' => $request->quantity,
//             'gross_amount' => $request->quantity * $price
//         ]);

//         return response()->json($cart);
//     }

//     public function destroy($id)
//     {
//         Cart::findOrFail($id)->delete();
//         return response()->json(['message' => 'Item removed']);
//     }
// }

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class CartController extends Controller
{
    // =========================================================================
    // [BARU] HELPER FUNGSI UNTUK KALKULASI TOTAL KERANJANG TERMASUK BUNDLE PROMO
    // =========================================================================
    private function calculateCartTotals($cartItems, $currency = 'IDR')
    {
        $totalPrice = 0;
        $totalDiscount = 0;

        // Kelompokkan item berdasarkan Category ID untuk mengecek Bundle
        $groupedByCategory = $cartItems->groupBy(function ($item) {
            return $item->product->category_id;
        });

        foreach ($groupedByCategory as $categoryId => $items) {
            $category = $items->first()->product->category;

            // Baca promo dari JSON
            $bundlePromo = $category->bundle_price; // Ini sudah berupa array/json karena casts
            $bundleQty = $category->bundle_qty;
            $bundleStartDate = $category->bundle_start_date;
            $bundleEndDate = $category->bundle_end_date;

            // Cek apakah kategori ini punya bundle promo yang aktif
            $now = now();
            $isPromoActive = $bundleQty && $bundlePromo &&
                (!$bundleStartDate || $now >= $bundleStartDate) &&
                (!$bundleEndDate || $now <= $bundleEndDate);

            $totalQtyInCategory = $items->sum('quantity');

            // --- LOGIKA BUNDLE BERLAKU ---
            if ($isPromoActive && $totalQtyInCategory >= $bundleQty) {

                // Tentukan harga bundle sesuai mata uang, fallback ke IDR jika tidak ada
                $activeBundlePrice = $bundlePromo[$currency] ?? ($bundlePromo['IDR'] ?? 0);

                // Hitung berapa "paket" bundle yang didapat
                $bundleCount = floor($totalQtyInCategory / $bundleQty);
                $remainderQty = $totalQtyInCategory % $bundleQty;

                // 1. Tambahkan harga "Paket Bundle" ke Total
                $totalPrice += ($bundleCount * $activeBundlePrice);

                // 2. Hitung harga barang sisa (remainder) dengan harga normal
                // Aturan bisnis: Barang termurah yang tidak masuk bundle
                $sortedItems = $items->sortBy(function ($item) use ($currency) {
                    $prices = is_string($item->product->prices) ? json_decode($item->product->prices, true) : ($item->product->prices ?? []);
                    return $prices[$currency] ?? $item->product->price;
                });

                $remainderAssigned = 0;
                foreach ($sortedItems as $item) {
                    if ($remainderAssigned < $remainderQty) {
                        $takeQty = min($item->quantity, $remainderQty - $remainderAssigned);

                        // Cek apakah barang sisa ini punya diskon coret
                        $normalPrice = $this->resolveProductPrice($item->product, $currency);

                        $totalPrice += ($takeQty * $normalPrice);
                        $remainderAssigned += $takeQty;
                    }
                }

                // Kalkulasi total harga asli (untuk dikurangkan dengan hasil bundle demi menghitung nilai $totalDiscount)
                $originalPriceSum = 0;
                foreach ($items as $item) {
                    $originalPriceSum += ($item->quantity * $this->resolveProductPrice($item->product, $currency));
                }
                $totalDiscount += max(0, $originalPriceSum - $totalPrice); // Hindari diskon minus

            } else {
                // --- LOGIKA HARGA NORMAL (Tidak ada bundle) ---
                foreach ($items as $item) {
                    $totalPrice += ($item->quantity * $this->resolveProductPrice($item->product, $currency));
                }
            }
        }

        return [
            'total_price' => $totalPrice,
            'total_discount' => $totalDiscount,
        ];
    }

    // Helper untuk menentukan harga akhir 1 buah produk (Normal vs Coret) sesuai mata uang
    private function resolveProductPrice($product, $currency)
    {
        $prices = is_string($product->prices) ? json_decode($product->prices, true) : ($product->prices ?? []);
        $discountPrices = is_string($product->discount_prices) ? json_decode($product->discount_prices, true) : ($product->discount_prices ?? []);

        $basePrice = $prices[$currency] ?? $product->price;
        $discountPrice = $discountPrices[$currency] ?? $product->discount_price;

        $now = now();
        if (!empty($discountPrice) &&
            (!$product->discount_start || $now >= $product->discount_start) &&
            (!$product->discount_end || $now <= $product->discount_end)) {
            return $discountPrice;
        }

        return $basePrice;
    }

    // =========================================================================

    public function index(Request $request)
    {
        // Ambil parameter mata uang dari request frontend (contoh: /api/carts?currency=SGD)
        // Default ke IDR jika tidak dikirim
        $currency = $request->query('currency', 'IDR');

        // Pastikan Eager Load Category karena dibutuhkan untuk hitung bundle
        $carts = Cart::with(['product.category'])->where('user_id', $request->user()->id)->latest()->get();

        // Hitung total dengan logika Bundle yang baru dibuat
        $calculated = $this->calculateCartTotals($carts, $currency);

        return response()->json([
            'items' => $carts,
            'summary' => [
                'currency' => $currency,
                'subtotal' => $calculated['total_price'] + $calculated['total_discount'],
                'bundle_discount' => $calculated['total_discount'],
                'grand_total' => $calculated['total_price']
            ]
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'color' => 'nullable|string|max:50'
        ]);

        $product = Product::findOrFail($request->product_id);
        $user = $request->user();

        $cartItem = Cart::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->where(function($query) use ($request) {
                if ($request->color) {
                    $query->where('color', $request->color);
                } else {
                    $query->whereNull('color');
                }
            })
            ->first();

        $newQuantity = $cartItem ? $cartItem->quantity + $request->quantity : $request->quantity;

        if ($newQuantity > $product->stock) {
            return response()->json(['message' => 'Quantity exceeds available stock!'], 422);
        }

        // Catatan: Nilai 'gross_amount' di tabel Cart kini kurang relevan karena perhitungan
        // dinamis multi-currency dan bundle, tapi kita tetap update base IDR untuk legacy.
        $price = $product->discount_price ?? $product->price;

        if ($cartItem) {
            $cartItem->update([
                'quantity' => $newQuantity,
                'gross_amount' => $newQuantity * $price
            ]);
        } else {
            $cartItem = Cart::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'quantity' => $request->quantity,
                'gross_amount' => $request->quantity * $price,
                'color' => $request->color
            ]);
        }

        return response()->json([
            'message' => 'Added to cart successfully',
            'cart_id' => $cartItem->id
        ]);
    }

    public function update(Request $request, $id)
    {
        $cart = Cart::with('product')->findOrFail($id);

        if ($request->quantity > $cart->product->stock) {
            return response()->json(['message' => 'Stock limited!'], 422);
        }

        $price = $cart->product->discount_price ?? $cart->product->price;
        $cart->update([
            'quantity' => $request->quantity,
            'gross_amount' => $request->quantity * $price
        ]);

        return response()->json($cart);
    }

    public function destroy($id)
    {
        Cart::findOrFail($id)->delete();
        return response()->json(['message' => 'Item removed']);
    }
}
