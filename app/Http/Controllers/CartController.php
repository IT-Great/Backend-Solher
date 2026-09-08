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

// namespace App\Http\Controllers;

// use App\Models\Cart;
// use App\Models\Product;
// use Illuminate\Http\Request;
// use App\Http\Controllers\Controller;

// class CartController extends Controller
// {
//     // =========================================================================
//     // HELPER: Kalkulasi Total Keranjang Termasuk Bundle Promo Multi-Currency
//     // =========================================================================
//     private function calculateCartTotals($cartItems, $currency = 'IDR')
//     {
//         $totalPrice = 0;
//         $totalDiscount = 0;

//         $groupedByCategory = $cartItems->groupBy(function ($item) {
//             return $item->product->category_id;
//         });

//         foreach ($groupedByCategory as $categoryId => $items) {
//             $category = $items->first()->product->category;
//             if (!$category) continue;

//             // Parsing String JSON dengan aman
//             $rawBundlePrice = $category->bundle_price;
//             $bundlePromo = is_string($rawBundlePrice) ? json_decode($rawBundlePrice, true) : ($rawBundlePrice ?? []);
//             if (is_numeric($bundlePromo)) {
//                 $bundlePromo = ['IDR' => $bundlePromo];
//             }

//             $bundleQty = $category->bundle_qty;
//             $now = now();
//             $isPromoActive = $bundleQty && $bundlePromo &&
//                 (!$category->bundle_start_date || $now >= $category->bundle_start_date) &&
//                 (!$category->bundle_end_date || $now <= $category->bundle_end_date);

//             $totalQtyInCategory = $items->sum('quantity');

//             if ($isPromoActive && $totalQtyInCategory >= $bundleQty) {
//                 // Tentukan harga bundle berdasarkan currency (Fallback ke IDR)
//                 $activeBundlePrice = $bundlePromo[$currency] ?? ($bundlePromo['IDR'] ?? 0);

//                 $bundleCount = floor($totalQtyInCategory / $bundleQty);
//                 $remainderQty = $totalQtyInCategory % $bundleQty;

//                 // 1. Tambah harga paket Bundle
//                 $totalPrice += ($bundleCount * $activeBundlePrice);

//                 // 2. Tambah harga sisa barang di luar paket (Diurutkan dari harga termurah)
//                 $sortedItems = $items->sortBy(function ($item) use ($currency) {
//                     return $this->resolveProductPrice($item->product, $currency);
//                 });

//                 $remainderAssigned = 0;
//                 foreach ($sortedItems as $item) {
//                     if ($remainderAssigned < $remainderQty) {
//                         $takeQty = min($item->quantity, $remainderQty - $remainderAssigned);
//                         $totalPrice += ($takeQty * $this->resolveProductPrice($item->product, $currency));
//                         $remainderAssigned += $takeQty;
//                     }
//                 }

//                 // 3. Hitung selisih diskon (Harga Normal - Harga Setelah Bundle)
//                 $originalPriceSum = 0;
//                 foreach ($items as $item) {
//                     $originalPriceSum += ($item->quantity * $this->resolveProductPrice($item->product, $currency));
//                 }
//                 $totalDiscount += max(0, $originalPriceSum - $totalPrice);

//             } else {
//                 // Hitung Normal jika tidak ada promo
//                 foreach ($items as $item) {
//                     $totalPrice += ($item->quantity * $this->resolveProductPrice($item->product, $currency));
//                 }
//             }
//         }

//         return [
//             'total_price' => $totalPrice,
//             'total_discount' => $totalDiscount,
//         ];
//     }

//     private function resolveProductPrice($product, $currency)
//     {
//         $prices = is_string($product->prices) ? json_decode($product->prices, true) : ($product->prices ?? []);
//         $discountPrices = is_string($product->discount_prices) ? json_decode($product->discount_prices, true) : ($product->discount_prices ?? []);

//         $basePrice = $prices[$currency] ?? $product->price;
//         $discountPrice = $discountPrices[$currency] ?? $product->discount_price;

//         $now = now();
//         if (!empty($discountPrice) &&
//             (!$product->discount_start || $now >= $product->discount_start) &&
//             (!$product->discount_end || $now <= $product->discount_end)) {
//             return $discountPrice;
//         }

//         return $basePrice;
//     }

//     // =========================================================================

//     public function index(Request $request)
//     {
//         $currency = $request->query('currency', 'IDR');
//         $carts = Cart::with(['product.category'])->where('user_id', $request->user()->id)->latest()->get();
//         $calculated = $this->calculateCartTotals($carts, $currency);

//         return response()->json([
//             'items' => $carts,
//             'summary' => [
//                 'currency' => $currency,
//                 'subtotal' => $calculated['total_price'] + $calculated['total_discount'],
//                 'bundle_discount' => $calculated['total_discount'],
//                 'grand_total' => $calculated['total_price']
//             ]
//         ]);
//     }

//     public function store(Request $request)
//     {
//         $request->validate([
//             'product_id' => 'required|exists:products,id',
//             'quantity' => 'required|integer|min:1',
//             'color' => 'nullable|string|max:50'
//         ]);

//         $product = Product::findOrFail($request->product_id);
//         $user = $request->user();

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
//             $cartItem = Cart::create([
//                 'user_id' => $user->id,
//                 'product_id' => $product->id,
//                 'quantity' => $request->quantity,
//                 'gross_amount' => $request->quantity * $price,
//                 'color' => $request->color
//             ]);
//         }

//         return response()->json([
//             'message' => 'Added to cart successfully',
//             'cart_id' => $cartItem->id
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

// namespace App\Http\Controllers;

// use App\Models\Cart;
// use App\Models\Product;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;
// use App\Http\Controllers\Controller;

// class CartController extends Controller
// {
//     // =========================================================================
//     // HELPER: Kalkulasi Total Keranjang Termasuk Bundle Promo Multi-Currency
//     // =========================================================================
//     private function calculateCartTotals($cartItems, $currency = 'IDR')
//     {
//         $totalPrice = 0;
//         $totalDiscount = 0;

//         $groupedByCategory = $cartItems->groupBy(function ($item) {
//             return $item->product->category_id;
//         });

//         foreach ($groupedByCategory as $categoryId => $items) {
//             $category = $items->first()->product->category;
//             if (!$category) continue;

//             $rawBundlePrice = $category->bundle_price;
//             $bundlePromo = is_string($rawBundlePrice) ? json_decode($rawBundlePrice, true) : ($rawBundlePrice ?? []);
//             if (is_numeric($bundlePromo)) {
//                 $bundlePromo = ['IDR' => $bundlePromo];
//             }

//             $bundleQty = $category->bundle_qty;
//             $now = now();
//             $isPromoActive = $bundleQty && $bundlePromo &&
//                 (!$category->bundle_start_date || $now >= $category->bundle_start_date) &&
//                 (!$category->bundle_end_date || $now <= $category->bundle_end_date);

//             $totalQtyInCategory = $items->sum('quantity');

//             if ($isPromoActive && $totalQtyInCategory >= $bundleQty) {
//                 $activeBundlePrice = $bundlePromo[$currency] ?? ($bundlePromo['IDR'] ?? 0);
//                 $bundleCount = floor($totalQtyInCategory / $bundleQty);
//                 $remainderQty = $totalQtyInCategory % $bundleQty;

//                 $totalPrice += ($bundleCount * $activeBundlePrice);

//                 $sortedItems = $items->sortBy(function ($item) use ($currency) {
//                     return $this->resolveProductPrice($item->product, $currency);
//                 });

//                 $remainderAssigned = 0;
//                 foreach ($sortedItems as $item) {
//                     if ($remainderAssigned < $remainderQty) {
//                         $takeQty = min($item->quantity, $remainderQty - $remainderAssigned);
//                         $totalPrice += ($takeQty * $this->resolveProductPrice($item->product, $currency));
//                         $remainderAssigned += $takeQty;
//                     }
//                 }

//                 $originalPriceSum = 0;
//                 foreach ($items as $item) {
//                     $originalPriceSum += ($item->quantity * $this->resolveProductPrice($item->product, $currency));
//                 }
//                 $totalDiscount += max(0, $originalPriceSum - $totalPrice);

//             } else {
//                 foreach ($items as $item) {
//                     $totalPrice += ($item->quantity * $this->resolveProductPrice($item->product, $currency));
//                 }
//             }
//         }

//         return [
//             'total_price' => $totalPrice,
//             'total_discount' => $totalDiscount,
//         ];
//     }

//     private function resolveProductPrice($product, $currency = 'IDR')
//     {
//         $prices = is_string($product->prices) ? json_decode($product->prices, true) : ($product->prices ?? []);
//         $discountPrices = is_string($product->discount_prices) ? json_decode($product->discount_prices, true) : ($product->discount_prices ?? []);

//         $basePrice = $prices[$currency] ?? $product->price;
//         $discountPrice = $discountPrices[$currency] ?? $product->discount_price;

//         $now = now();
//         if (!empty($discountPrice) &&
//             (!$product->discount_start || $now >= $product->discount_start) &&
//             (!$product->discount_end || $now <= $product->discount_end)) {
//             return $discountPrice;
//         }

//         return $basePrice;
//     }

//     // =========================================================================

//     public function index(Request $request)
//     {
//         $currency = $request->query('currency', 'IDR');
//         $carts = Cart::with(['product.category'])
//             ->where('user_id', $request->user()->id)
//             ->latest()
//             ->get();

//         $calculated = $this->calculateCartTotals($carts, $currency);

//         return response()->json([
//             'items' => $carts,
//             'summary' => [
//                 'currency' => $currency,
//                 'subtotal' => $calculated['total_price'] + $calculated['total_discount'],
//                 'bundle_discount' => $calculated['total_discount'],
//                 'grand_total' => $calculated['total_price']
//             ]
//         ]);
//     }

//     public function store(Request $request)
//     {
//         // 👇 PERBAIKAN FATAL 2: Validasi kuantitas yang solid
//         $request->validate([
//             'product_id' => 'required|exists:products,id',
//             'quantity'   => 'required|integer|min:1',
//             'color'      => 'nullable|string|max:50'
//         ]);

//         $user = $request->user();

//         // 👇 PERBAIKAN TIER 2: DB Transaction untuk cegah Race Condition
//         return DB::transaction(function () use ($request, $user) {
//             $product = Product::lockForUpdate()->findOrFail($request->product_id);

//             $cartItem = Cart::where('user_id', $user->id)
//                 ->where('product_id', $product->id)
//                 ->where(function($query) use ($request) {
//                     if ($request->color) {
//                         $query->where('color', $request->color);
//                     } else {
//                         $query->whereNull('color');
//                     }
//                 })
//                 ->lockForUpdate() // Kunci row jika ada
//                 ->first();

//             $newQuantity = $cartItem ? $cartItem->quantity + $request->quantity : $request->quantity;

//             if ($newQuantity > $product->stock) {
//                 return response()->json(['message' => 'Kuantitas melebihi stok yang tersedia!'], 422);
//             }

//             // 👇 PERBAIKAN FATAL 3: Gunakan helper harga yang benar
//             $price = $this->resolveProductPrice($product, 'IDR');

//             if ($cartItem) {
//                 $cartItem->update([
//                     'quantity' => $newQuantity,
//                     'gross_amount' => $newQuantity * $price
//                 ]);
//             } else {
//                 $cartItem = Cart::create([
//                     'user_id' => $user->id,
//                     'product_id' => $product->id,
//                     'quantity' => $request->quantity,
//                     'gross_amount' => $request->quantity * $price,
//                     'color' => $request->color
//                 ]);
//             }

//             return response()->json([
//                 'message' => 'Ditambahkan ke keranjang',
//                 'cart_id' => $cartItem->id
//             ]);
//         });
//     }

//     public function update(Request $request, $id)
//     {
//         // 👇 PERBAIKAN FATAL 2: Validasi strict
//         $request->validate([
//             'quantity' => 'required|integer|min:1',
//         ]);

//         $user = $request->user();

//         // 👇 PERBAIKAN FATAL 1: Cegah IDOR (Hanya edit punya sendiri)
//         $cart = Cart::with('product')
//             ->where('user_id', $user->id)
//             ->findOrFail($id);

//         if ($request->quantity > $cart->product->stock) {
//             return response()->json(['message' => 'Stok tidak mencukupi!'], 422);
//         }

//         // 👇 PERBAIKAN FATAL 3: Selaraskan dengan harga aktif
//         $price = $this->resolveProductPrice($cart->product, 'IDR');

//         $cart->update([
//             'quantity' => $request->quantity,
//             'gross_amount' => $request->quantity * $price
//         ]);

//         return response()->json($cart);
//     }

//     public function destroy(Request $request, $id)
//     {
//         // 👇 PERBAIKAN FATAL 1: Cegah IDOR (Hanya hapus punya sendiri)
//         Cart::where('user_id', $request->user()->id)
//             ->findOrFail($id)
//             ->delete();

//         return response()->json(['message' => 'Item berhasil dihapus']);
//     }
// }

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class CartController extends Controller
{
    // private function calculateCartTotals($cartItems, $currency = 'IDR')
    // {
    //     $totalPrice = 0;
    //     $totalDiscount = 0;
    //     $now = now();
    //     $groupedItems = [];

    //     foreach ($cartItems as $item) {
    //         $cat = $item->product->category;
    //         if (!$cat) continue;

    //         $rawPromo = $cat->bundle_price;
    //         $promoConf = is_array($rawPromo) ? $rawPromo : (is_string($rawPromo) ? json_decode($rawPromo, true) : []);

    //         if (is_numeric($promoConf)) {
    //             $promoConf = ['promo_type' => 'bundle', 'price' => ['IDR' => $promoConf]];
    //         }

    //         $isActive = $cat->bundle_qty &&
    //             (!$cat->bundle_start_date || $now >= $cat->bundle_start_date) &&
    //             (!$cat->bundle_end_date || $now <= $cat->bundle_end_date);

    //         if ($isActive && !empty($promoConf)) {
    //             $mixGroup = !empty($promoConf['mix_group']) ? $promoConf['mix_group'] : 'CAT_' . $cat->id;
    //             if (!isset($groupedItems[$mixGroup])) {
    //                 $groupedItems[$mixGroup] = [
    //                     'config' => $promoConf,
    //                     'bundle_qty' => $cat->bundle_qty,
    //                     'items' => collect()
    //                 ];
    //             }
    //             $groupedItems[$mixGroup]['items']->push($item);
    //         } else {
    //             if (!isset($groupedItems['NO_PROMO'])) {
    //                 $groupedItems['NO_PROMO'] = ['config' => null, 'items' => collect()];
    //             }
    //             $groupedItems['NO_PROMO']['items']->push($item);
    //         }
    //     }

    //     foreach ($groupedItems as $groupKey => $group) {
    //         if ($groupKey === 'NO_PROMO') {
    //             foreach ($group['items'] as $item) {
    //                 $totalPrice += ($item->quantity * $this->resolveProductPrice($item->product, $currency, $now));
    //             }
    //             continue;
    //         }

    //         $conf = $group['config'];
    //         $type = $conf['promo_type'] ?? 'bundle';
    //         $items = $group['items'];
    //         $normalTotalGroup = 0;

    //         foreach ($items as $item) {
    //             $normalTotalGroup += ($item->quantity * $this->resolveProductPrice($item->product, $currency, $now));
    //         }

    //         if ($type === 'bundle') {
    //             $bundlePrice = $conf['price'][$currency] ?? ($conf['price']['IDR'] ?? 0);
    //             $bundleQty = $group['bundle_qty'];
    //             $totalQty = $items->sum('quantity');

    //             $bundleCount = floor($totalQty / $bundleQty);
    //             $remainderQty = $totalQty % $bundleQty;

    //             $groupPromoPrice = ($bundleCount * $bundlePrice);

    //             $sortedItems = $items->sortByDesc(function ($item) use ($currency, $now) {
    //                 return $this->resolveProductPrice($item->product, $currency, $now);
    //             });

    //             $assignedRemainder = 0;
    //             foreach ($sortedItems as $item) {
    //                 $normalPrice = $this->resolveProductPrice($item->product, $currency, $now);
    //                 if ($assignedRemainder < $remainderQty) {
    //                     $take = min($item->quantity, $remainderQty - $assignedRemainder);
    //                     $groupPromoPrice += ($take * $normalPrice);
    //                     $assignedRemainder += $take;
    //                 }
    //             }

    //             $totalPrice += $groupPromoPrice;
    //             $totalDiscount += max(0, $normalTotalGroup - $groupPromoPrice);

    //         } elseif ($type === 'percent') {
    //             $minPurchase = $conf['min_purchase'] ?? 0;
    //             if ($normalTotalGroup >= $minPurchase) {
    //                 $percent = $conf['percent'] ?? 0;
    //                 $maxDiscount = $conf['max_discount'] ?? 0;

    //                 $discount = $normalTotalGroup * ($percent / 100);
    //                 if ($maxDiscount > 0 && $discount > $maxDiscount) {
    //                     $discount = $maxDiscount;
    //                 }

    //                 $totalPrice += ($normalTotalGroup - $discount);
    //                 $totalDiscount += $discount;
    //             } else {
    //                 $totalPrice += $normalTotalGroup;
    //             }
    //         }
    //     }

    //     return [
    //         'total_price' => $totalPrice,
    //         'total_discount' => $totalDiscount,
    //     ];
    // }

    // private function calculateCartTotals($cartItems, $currency = 'IDR')
    // {
    //     $totalPrice = 0;
    //     $totalDiscount = 0;
    //     $now = now();
    //     $groupedItems = [];

    //     foreach ($cartItems as $item) {
    //         $cat = $item->product->category;
    //         if (!$cat) continue;

    //         $rawPromo = $cat->bundle_price;
    //         $promoConf = is_array($rawPromo) ? $rawPromo : (is_string($rawPromo) ? json_decode($rawPromo, true) : []);

    //         if (is_numeric($promoConf)) {
    //             $promoConf = ['promo_type' => 'bundle', 'price' => ['IDR' => $promoConf]];
    //         }

    //         $isActive = $cat->bundle_qty &&
    //             (!$cat->bundle_start_date || $now >= $cat->bundle_start_date) &&
    //             (!$cat->bundle_end_date || $now <= $cat->bundle_end_date);

    //         if ($isActive && !empty($promoConf)) {
    //             $mixGroup = !empty($promoConf['mix_group']) ? $promoConf['mix_group'] : 'CAT_' . $cat->id;
    //             if (!isset($groupedItems[$mixGroup])) {
    //                 $groupedItems[$mixGroup] = [
    //                     'config' => $promoConf,
    //                     'bundle_qty' => $cat->bundle_qty,
    //                     'items' => collect()
    //                 ];
    //             }
    //             $groupedItems[$mixGroup]['items']->push($item);
    //         } else {
    //             if (!isset($groupedItems['NO_PROMO'])) {
    //                 $groupedItems['NO_PROMO'] = ['config' => null, 'items' => collect()];
    //             }
    //             $groupedItems['NO_PROMO']['items']->push($item);
    //         }
    //     }

    //     foreach ($groupedItems as $groupKey => $group) {
    //         if ($groupKey === 'NO_PROMO') {
    //             foreach ($group['items'] as $item) {
    //                 $totalPrice += ($item->quantity * $this->resolveProductPrice($item->product, $currency, $now));
    //             }
    //             continue;
    //         }

    //         $conf = $group['config'];
    //         $type = $conf['promo_type'] ?? 'bundle';
    //         $items = $group['items'];
    //         $normalTotalGroup = 0;

    //         foreach ($items as $item) {
    //             $normalTotalGroup += ($item->quantity * $this->resolveProductPrice($item->product, $currency, $now));
    //         }

    //         if ($type === 'bundle') {
    //             $bundlePrice = $conf['price'][$currency] ?? ($conf['price']['IDR'] ?? 0);

    //             // [ANTI-BUG] Jika admin salah input harga bundle jadi 0
    //             if (empty($bundlePrice) || $bundlePrice <= 0) {
    //                 $totalPrice += $normalTotalGroup;
    //                 continue;
    //             }

    //             $bundleQty = max(1, $group['bundle_qty']);
    //             $totalQty = $items->sum('quantity');

    //             $bundleCount = floor($totalQty / $bundleQty);
    //             $remainderQty = $totalQty % $bundleQty;

    //             $groupPromoPrice = ($bundleCount * $bundlePrice);

    //             $sortedItems = $items->sortByDesc(function ($item) use ($currency, $now) {
    //                 return $this->resolveProductPrice($item->product, $currency, $now);
    //             });

    //             $assignedRemainder = 0;
    //             foreach ($sortedItems as $item) {
    //                 $normalPrice = $this->resolveProductPrice($item->product, $currency, $now);
    //                 if ($assignedRemainder < $remainderQty) {
    //                     $take = min($item->quantity, $remainderQty - $assignedRemainder);
    //                     $groupPromoPrice += ($take * $normalPrice);
    //                     $assignedRemainder += $take;
    //                 }
    //             }

    //             $totalPrice += $groupPromoPrice;
    //             $totalDiscount += max(0, $normalTotalGroup - $groupPromoPrice);

    //         } elseif ($type === 'percent') {
    //             $minPurchase = $conf['min_purchase'] ?? 0;
    //             if ($normalTotalGroup >= $minPurchase) {
    //                 $percent = $conf['percent'] ?? 0;
    //                 $maxDiscount = $conf['max_discount'] ?? 0;

    //                 $discount = $normalTotalGroup * ($percent / 100);
    //                 if ($maxDiscount > 0 && $discount > $maxDiscount) {
    //                     $discount = $maxDiscount;
    //                 }

    //                 $totalPrice += ($normalTotalGroup - $discount);
    //                 $totalDiscount += $discount;
    //             } else {
    //                 $totalPrice += $normalTotalGroup;
    //             }
    //         }
    //     }

    //     return [
    //         'total_price' => $totalPrice,
    //         'total_discount' => $totalDiscount,
    //     ];
    // }

    // private function calculateCartTotals($cartItems, $currency = 'IDR')
    // {
    //     $totalPrice = 0;
    //     $totalDiscount = 0;
    //     $now = now();
    //     $groupedItems = [];

    //     foreach ($cartItems as $item) {
    //         $cat = $item->product->category;
    //         if (!$cat) continue;

    //         $rawPromo = $cat->bundle_price;
    //         $promoConf = is_array($rawPromo) ? $rawPromo : (is_string($rawPromo) ? json_decode($rawPromo, true) : []);

    //         if (is_numeric($promoConf)) {
    //             $promoConf = ['promo_type' => 'bundle', 'price' => ['IDR' => $promoConf]];
    //         }

    //         $isActive = $cat->bundle_qty &&
    //             (!$cat->bundle_start_date || $now >= $cat->bundle_start_date) &&
    //             (!$cat->bundle_end_date || $now <= $cat->bundle_end_date);

    //         if ($isActive && !empty($promoConf)) {
    //             $mixGroup = !empty($promoConf['mix_group']) ? $promoConf['mix_group'] : 'CAT_' . $cat->id;
    //             if (!isset($groupedItems[$mixGroup])) {
    //                 $groupedItems[$mixGroup] = [
    //                     'config' => $promoConf,
    //                     'bundle_qty' => $cat->bundle_qty,
    //                     'items' => collect()
    //                 ];
    //             }
    //             $groupedItems[$mixGroup]['items']->push($item);
    //         } else {
    //             if (!isset($groupedItems['NO_PROMO'])) {
    //                 $groupedItems['NO_PROMO'] = ['config' => null, 'items' => collect()];
    //             }
    //             $groupedItems['NO_PROMO']['items']->push($item);
    //         }
    //     }

    //     foreach ($groupedItems as $groupKey => $group) {
    //         if ($groupKey === 'NO_PROMO') {
    //             foreach ($group['items'] as $item) {
    //                 $totalPrice += ($item->quantity * $this->resolveProductPrice($item->product, $currency, $now));
    //             }
    //             continue;
    //         }

    //         $conf = $group['config'];
    //         $type = $conf['promo_type'] ?? 'bundle';
    //         $items = $group['items'];
    //         $normalTotalGroup = 0;

    //         foreach ($items as $item) {
    //             $normalTotalGroup += ($item->quantity * $this->resolveProductPrice($item->product, $currency, $now));
    //         }

    //         if ($type === 'bundle') {
    //             $bundlePrice = $conf['price'][$currency] ?? ($conf['price']['IDR'] ?? 0);

    //             if (empty($bundlePrice) || $bundlePrice <= 0) {
    //                 $totalPrice += $normalTotalGroup;
    //                 continue;
    //             }

    //             $bundleQty = max(1, $group['bundle_qty']);
    //             $totalQty = $items->sum('quantity');

    //             $bundleCount = floor($totalQty / $bundleQty);
    //             $remainderQty = $totalQty % $bundleQty;

    //             $groupPromoPrice = ($bundleCount * $bundlePrice);

    //             $sortedItems = $items->sortByDesc(function ($item) use ($currency, $now) {
    //                 return $this->resolveProductPrice($item->product, $currency, $now);
    //             });

    //             $assignedRemainder = 0;
    //             foreach ($sortedItems as $item) {
    //                 $normalPrice = $this->resolveProductPrice($item->product, $currency, $now);
    //                 if ($assignedRemainder < $remainderQty) {
    //                     $take = min($item->quantity, $remainderQty - $assignedRemainder);
    //                     $groupPromoPrice += ($take * $normalPrice);
    //                     $assignedRemainder += $take;
    //                 }
    //             }

    //             $totalPrice += $groupPromoPrice;
    //             $totalDiscount += max(0, $normalTotalGroup - $groupPromoPrice);

    //         } elseif ($type === 'percent') {
    //             $minPurchase = $conf['min_purchase'] ?? 0;
    //             if ($normalTotalGroup >= $minPurchase) {
    //                 $percent = $conf['percent'] ?? 0;
    //                 $maxDiscount = $conf['max_discount'] ?? 0;

    //                 $discount = $normalTotalGroup * ($percent / 100);
    //                 if ($maxDiscount > 0 && $discount > $maxDiscount) {
    //                     $discount = $maxDiscount;
    //                 }
    //                 if ($discount > $normalTotalGroup) {
    //                     $discount = $normalTotalGroup;
    //                 }

    //                 $totalPrice += ($normalTotalGroup - $discount);
    //                 $totalDiscount += $discount;
    //             } else {
    //                 $totalPrice += $normalTotalGroup;
    //             }
    //         }
    //     }

    //     return [
    //         'total_price' => $totalPrice,
    //         'total_discount' => $totalDiscount,
    //     ];
    // }

    private function calculateCartTotals($cartItems, $currency = 'IDR')
    {
        $totalPrice = 0;
        $totalDiscount = 0;
        $now = now();
        $groupedItems = [];

        // 1. KELOMPOKKAN ITEM BERDASARKAN KATEGORI & MIX GROUP
        foreach ($cartItems as $item) {
            $cat = $item->product->category;
            if (!$cat) continue;

            $promoConf = $cat->promo_config ?? [];

            $startDate = !empty($promoConf['start_date']) ? \Carbon\Carbon::parse($promoConf['start_date']) : null;
            $endDate = !empty($promoConf['end_date']) ? \Carbon\Carbon::parse($promoConf['end_date']) : null;

            $isActive = !empty($promoConf) &&
                (!$startDate || $now >= $startDate) &&
                (!$endDate || $now <= $endDate);

            if ($isActive) {
                $mixGroup = !empty($promoConf['mix_group']) ? $promoConf['mix_group'] : 'CAT_' . $cat->id;
                if (!isset($groupedItems[$mixGroup])) {
                    $groupedItems[$mixGroup] = [
                        'config' => $promoConf,
                        'bundle_qty' => $promoConf['qty'] ?? 1,
                        'items' => collect()
                    ];
                }
                $groupedItems[$mixGroup]['items']->push($item);
            } else {
                if (!isset($groupedItems['NO_PROMO'])) {
                    $groupedItems['NO_PROMO'] = ['config' => null, 'items' => collect()];
                }
                $groupedItems['NO_PROMO']['items']->push($item);
            }
        }

        // 2. EKSEKUSI KALKULASI BERDASARKAN TIPE PROMO
        foreach ($groupedItems as $groupKey => $group) {
            if ($groupKey === 'NO_PROMO') {
                foreach ($group['items'] as $item) {
                    $totalPrice += ($item->quantity * $this->resolveProductPrice($item->product, $currency, $now));
                }
                continue;
            }

            $conf = $group['config'];
            $type = $conf['promo_type'] ?? 'bundle';
            $items = $group['items'];
            $normalTotalGroup = 0;

            foreach ($items as $item) {
                $normalTotalGroup += ($item->quantity * $this->resolveProductPrice($item->product, $currency, $now));
            }

            if ($type === 'bundle') {
                $bundlePrice = $conf['price'][$currency] ?? ($conf['price']['IDR'] ?? 0);

                if (empty($bundlePrice) || $bundlePrice <= 0) {
                    $totalPrice += $normalTotalGroup;
                    continue;
                }

                $bundleQty = max(1, $group['bundle_qty']);
                $totalQty = $items->sum('quantity');

                $bundleCount = floor($totalQty / $bundleQty);
                $remainderQty = $totalQty % $bundleQty;

                $groupPromoPrice = ($bundleCount * $bundlePrice);

                $sortedItems = $items->sortByDesc(function ($item) use ($currency, $now) {
                    return $this->resolveProductPrice($item->product, $currency, $now);
                });

                $assignedRemainder = 0;
                foreach ($sortedItems as $item) {
                    $normalPrice = $this->resolveProductPrice($item->product, $currency, $now);
                    if ($assignedRemainder < $remainderQty) {
                        $take = min($item->quantity, $remainderQty - $assignedRemainder);
                        $groupPromoPrice += ($take * $normalPrice);
                        $assignedRemainder += $take;
                    }
                }

                $totalPrice += $groupPromoPrice;
                $totalDiscount += max(0, $normalTotalGroup - $groupPromoPrice);

            } elseif ($type === 'percent') {
                $minPurchase = $conf['min_purchase'] ?? 0;
                if ($normalTotalGroup >= $minPurchase) {
                    $percent = $conf['percent'] ?? 0;
                    $maxDiscount = $conf['max_discount'] ?? 0;

                    $discount = $normalTotalGroup * ($percent / 100);
                    if ($maxDiscount > 0 && $discount > $maxDiscount) {
                        $discount = $maxDiscount;
                    }
                    if ($discount > $normalTotalGroup) {
                        $discount = $normalTotalGroup;
                    }

                    $totalPrice += ($normalTotalGroup - $discount);
                    $totalDiscount += $discount;
                } else {
                    $totalPrice += $normalTotalGroup;
                }
            }
        }

        return [
            'total_price' => $totalPrice,
            'total_discount' => $totalDiscount,
        ];
    }

    private function resolveProductPrice($product, $currency, $now)
    {
        $prices = is_string($product->prices) ? json_decode($product->prices, true) : ($product->prices ?? []);
        $discountPrices = is_string($product->discount_prices) ? json_decode($product->discount_prices, true) : ($product->discount_prices ?? []);

        $basePrice = $prices[$currency] ?? $product->price;
        $discountPrice = $discountPrices[$currency] ?? $product->discount_price;

        if (!empty($discountPrice) &&
            (!$product->discount_start_date || $now >= $product->discount_start_date) &&
            (!$product->discount_end_date || $now <= $product->discount_end_date)) {
            return $discountPrice;
        }

        return $basePrice;
    }

    public function index(Request $request)
    {
        $currency = $request->query('currency', 'IDR');
        $carts = Cart::with(['product.category'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

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
            'quantity'   => 'required|integer|min:1',
            'color'      => 'nullable|string|max:50'
        ]);

        $user = $request->user();

        return DB::transaction(function () use ($request, $user) {
            $product = Product::lockForUpdate()->findOrFail($request->product_id);

            $cartItem = Cart::where('user_id', $user->id)
                ->where('product_id', $product->id)
                ->where(function($query) use ($request) {
                    if ($request->color) {
                        $query->where('color', $request->color);
                    } else {
                        $query->whereNull('color');
                    }
                })
                ->lockForUpdate()
                ->first();

            $newQuantity = $cartItem ? $cartItem->quantity + $request->quantity : $request->quantity;

            if ($newQuantity > $product->stock) {
                return response()->json(['message' => 'Kuantitas melebihi stok yang tersedia!'], 422);
            }

            $price = $this->resolveProductPrice($product, 'IDR', now());

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
                'message' => 'Ditambahkan ke keranjang',
                'cart_id' => $cartItem->id
            ]);
        });
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $user = $request->user();

        $cart = Cart::with('product')
            ->where('user_id', $user->id)
            ->findOrFail($id);

        if ($request->quantity > $cart->product->stock) {
            return response()->json(['message' => 'Stok tidak mencukupi!'], 422);
        }

        $price = $this->resolveProductPrice($cart->product, 'IDR', now());

        $cart->update([
            'quantity' => $request->quantity,
            'gross_amount' => $request->quantity * $price
        ]);

        return response()->json($cart);
    }

    public function destroy(Request $request, $id)
    {
        Cart::where('user_id', $request->user()->id)
            ->findOrFail($id)
            ->delete();

        return response()->json(['message' => 'Item berhasil dihapus']);
    }
}
