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
    // HELPER: Kalkulasi Total Keranjang Termasuk Bundle Promo Multi-Currency
    // =========================================================================
    private function calculateCartTotals($cartItems, $currency = 'IDR')
    {
        $totalPrice = 0;
        $totalDiscount = 0;

        $groupedByCategory = $cartItems->groupBy(function ($item) {
            return $item->product->category_id;
        });

        foreach ($groupedByCategory as $categoryId => $items) {
            $category = $items->first()->product->category;
            if (!$category) continue;

            // Parsing String JSON dengan aman
            $rawBundlePrice = $category->bundle_price;
            $bundlePromo = is_string($rawBundlePrice) ? json_decode($rawBundlePrice, true) : ($rawBundlePrice ?? []);
            if (is_numeric($bundlePromo)) {
                $bundlePromo = ['IDR' => $bundlePromo]; 
            }

            $bundleQty = $category->bundle_qty;
            $now = now();
            $isPromoActive = $bundleQty && $bundlePromo &&
                (!$category->bundle_start_date || $now >= $category->bundle_start_date) &&
                (!$category->bundle_end_date || $now <= $category->bundle_end_date);

            $totalQtyInCategory = $items->sum('quantity');

            if ($isPromoActive && $totalQtyInCategory >= $bundleQty) {
                // Tentukan harga bundle berdasarkan currency (Fallback ke IDR)
                $activeBundlePrice = $bundlePromo[$currency] ?? ($bundlePromo['IDR'] ?? 0);

                $bundleCount = floor($totalQtyInCategory / $bundleQty);
                $remainderQty = $totalQtyInCategory % $bundleQty;

                // 1. Tambah harga paket Bundle
                $totalPrice += ($bundleCount * $activeBundlePrice);

                // 2. Tambah harga sisa barang di luar paket (Diurutkan dari harga termurah)
                $sortedItems = $items->sortBy(function ($item) use ($currency) {
                    return $this->resolveProductPrice($item->product, $currency);
                });

                $remainderAssigned = 0;
                foreach ($sortedItems as $item) {
                    if ($remainderAssigned < $remainderQty) {
                        $takeQty = min($item->quantity, $remainderQty - $remainderAssigned);
                        $totalPrice += ($takeQty * $this->resolveProductPrice($item->product, $currency));
                        $remainderAssigned += $takeQty;
                    }
                }

                // 3. Hitung selisih diskon (Harga Normal - Harga Setelah Bundle)
                $originalPriceSum = 0;
                foreach ($items as $item) {
                    $originalPriceSum += ($item->quantity * $this->resolveProductPrice($item->product, $currency));
                }
                $totalDiscount += max(0, $originalPriceSum - $totalPrice);

            } else {
                // Hitung Normal jika tidak ada promo
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
        $currency = $request->query('currency', 'IDR');
        $carts = Cart::with(['product.category'])->where('user_id', $request->user()->id)->latest()->get();
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