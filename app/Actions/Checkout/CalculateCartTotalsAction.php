<?php

// namespace App\Actions\Checkout;

// use App\Models\User;
// use App\Models\PromoClaim;
// use Illuminate\Http\Request;
// use App\Services\PromoMerdekaService;

// class CalculateCartTotalsAction
// {
//     public function execute(User $lockedUser, $cartItems, Request $request, PromoMerdekaService $promoService): array
//     {
//         $currency = $request->currency;
//         $now = now();
//         $totalAmount = 0;
//         $finalItemPrices = [];

//         // 1. Kalkulasi Harga Dasar & Promo Bundle
//         $groupedByCategory = $cartItems->groupBy(function ($item) {
//             return $item->product->category_id;
//         });

//         foreach ($groupedByCategory as $categoryId => $items) {
//             $category = $items->first()->product->category;
//             $rawBundlePrice = $category->bundle_price;
//             $bundlePromo = is_string($rawBundlePrice) ? json_decode($rawBundlePrice, true) : ($rawBundlePrice ?? []);

//             if (is_numeric($bundlePromo)) {
//                 $bundlePromo = ['IDR' => $bundlePromo];
//             }

//             $bundleQty = $category->bundle_qty;
//             $isPromoActive = $bundleQty && $bundlePromo &&
//                 (! $category->bundle_start_date || $now >= $category->bundle_start_date) &&
//                 (! $category->bundle_end_date || $now <= $category->bundle_end_date);

//             $totalQtyInCategory = $items->sum('quantity');

//             if ($isPromoActive && $totalQtyInCategory >= $bundleQty) {
//                 $activeBundlePrice = $bundlePromo[$currency] ?? ($bundlePromo['IDR'] ?? 0);
//                 $bundleCount = floor($totalQtyInCategory / $bundleQty);
//                 $remainderQty = $totalQtyInCategory % $bundleQty;

//                 $totalAmount += ($bundleCount * $activeBundlePrice);

//                 $sortedItems = $items->sortBy(function ($item) use ($currency, $now) {
//                     $prices = is_string($item->product->prices) ? json_decode($item->product->prices, true) : ($item->product->prices ?? []);
//                     $discountPrices = is_string($item->product->discount_prices) ? json_decode($item->product->discount_prices, true) : ($item->product->discount_prices ?? []);
//                     $basePrice = $prices[$currency] ?? $item->product->price;
//                     $discountPrice = $discountPrices[$currency] ?? $item->product->discount_price;

//                     return (! empty($discountPrice) && (! $item->product->discount_start_date || $now >= $item->product->discount_start_date) && (! $item->product->discount_end_date || $now <= $item->product->discount_end_date)) ? $discountPrice : $basePrice;
//                 });

//                 $remainderAssigned = 0;
//                 foreach ($sortedItems as $item) {
//                     if ($remainderAssigned < $remainderQty) {
//                         $takeQty = min($item->quantity, $remainderQty - $remainderAssigned);
//                         // Perhitungan harga normal untuk sisa barang di luar bundle
//                         $prices = is_string($item->product->prices) ? json_decode($item->product->prices, true) : ($item->product->prices ?? []);
//                         $discountPrices = is_string($item->product->discount_prices) ? json_decode($item->product->discount_prices, true) : ($item->product->discount_prices ?? []);
//                         $basePrice = $prices[$currency] ?? $item->product->price;
//                         $discountPrice = $discountPrices[$currency] ?? $item->product->discount_price;
//                         $normalPrice = (! empty($discountPrice) && (! $item->product->discount_start_date || $now >= $item->product->discount_start_date) && (! $item->product->discount_end_date || $now <= $item->product->discount_end_date)) ? $discountPrice : $basePrice;

//                         $totalAmount += ($takeQty * $normalPrice);
//                         $finalItemPrices[$item->id] = $normalPrice;
//                         $remainderAssigned += $takeQty;
//                     } else {
//                         // Barang yang masuk bundle
//                         $prices = is_string($item->product->prices) ? json_decode($item->product->prices, true) : ($item->product->prices ?? []);
//                         $discountPrices = is_string($item->product->discount_prices) ? json_decode($item->product->discount_prices, true) : ($item->product->discount_prices ?? []);
//                         $basePrice = $prices[$currency] ?? $item->product->price;
//                         $discountPrice = $discountPrices[$currency] ?? $item->product->discount_price;
//                         $normalPrice = (! empty($discountPrice) && (! $item->product->discount_start_date || $now >= $item->product->discount_start_date) && (! $item->product->discount_end_date || $now <= $item->product->discount_end_date)) ? $discountPrice : $basePrice;

//                         $finalItemPrices[$item->id] = $normalPrice;
//                     }
//                 }
//             } else {
//                 foreach ($items as $item) {
//                     $prices = is_string($item->product->prices) ? json_decode($item->product->prices, true) : ($item->product->prices ?? []);
//                     $discountPrices = is_string($item->product->discount_prices) ? json_decode($item->product->discount_prices, true) : ($item->product->discount_prices ?? []);
//                     $basePrice = $prices[$currency] ?? $item->product->price;
//                     $discountPrice = $discountPrices[$currency] ?? $item->product->discount_price;

//                     $normalPrice = (! empty($discountPrice) && (! $item->product->discount_start_date || $now >= $item->product->discount_start_date) && (! $item->product->discount_end_date || $now <= $item->product->discount_end_date)) ? $discountPrice : $basePrice;

//                     $totalAmount += ($item->quantity * $normalPrice);
//                     $finalItemPrices[$item->id] = $normalPrice;
//                 }
//             }
//         }

//         // 2. Kalkulasi Promo/Voucher
//         $promoDiscountAmount = 0;
//         $appliedPromoCode = null;

//         if (!empty($request->promo_code)) {
//             $promoCode = strtoupper(trim($request->promo_code));

//             if ($promoCode === 'SOLHER17') {
//                 $claimCheck = PromoClaim::where('email', $lockedUser->email)
//                     ->where('promo_code', 'SOLHER17')
//                     ->lockForUpdate()
//                     ->first();

//                 if (!$claimCheck) throw new \Exception('Akses ditolak: Anda belum mengklaim promo ini.');
//                 if ($claimCheck->is_used) throw new \Exception('Voucher SOLHER17 Anda sudah hangus/terpakai.');

//                 $promoResult = $promoService->calculatePromo($cartItems, []);
//                 if (!$promoResult['is_valid']) throw new \Exception($promoResult['message']);

//                 $promoDiscountAmount = $promoResult['discount_amount'];
//                 $appliedPromoCode = $promoResult['code'];
//                 $claimCheck->update(['is_used' => true, 'used_at' => now()]);

//             } elseif ($promoCode === 'SOLHOST34') {
//                 $totalQuantityInCart = $cartItems->sum('quantity');
//                 if ($totalQuantityInCart > 1) throw new \Exception('Voucher Subsidi Tas hanya berlaku untuk 1 barang.');
//                 if ($request->use_points > 0) throw new \Exception('Voucher tidak dapat digabung dengan Poin.');

//                 $item = $cartItems->first();
//                 $catCode = strtoupper(trim($item->product->category->code ?? ''));
//                 if (!in_array($catCode, ['C001', 'C002', 'C003', 'C004'])) {
//                     throw new \Exception('Voucher ini khusus untuk produk Tas.');
//                 }

//                 $product = $item->product;
//                 if (!empty($product->discount_price) && (!$product->discount_start_date || $now >= $product->discount_start_date) && (!$product->discount_end_date || $now <= $product->discount_end_date)) {
//                     throw new \Exception('Tidak berlaku pada barang yang sedang diskon.');
//                 }

//                 $claimCheck = PromoClaim::where('email', $lockedUser->email)->where('promo_code', 'SOLHOST34')->where('is_used', true)->first();
//                 if ($claimCheck) throw new \Exception('Voucher sudah pernah digunakan.');

//                 $promoDiscountAmount = 3400000;
//                 $appliedPromoCode = 'SOLHOST34';

//                 PromoClaim::updateOrCreate(
//                     ['email' => $lockedUser->email, 'promo_code' => 'SOLHOST34'],
//                     ['is_used' => true, 'used_at' => now(), 'discount_value' => 3400000, 'expires_at' => now()->addDays(365)]
//                 );
//             } elseif ($promoCode === 'SOLHERMEMBER') {
//                 if (! $lockedUser->is_membership) throw new \Exception('Hanya untuk VIP Member.');
//                 if ($lockedUser->has_used_member_voucher) throw new \Exception('Voucher sudah pernah digunakan.');

//                 $promoDiscountAmount = ($currency === 'IDR') ? 500000 : 35;
//                 $appliedPromoCode = 'SOLHERMEMBER';
//                 $lockedUser->update(['has_used_member_voucher' => true]);
//             } else {
//                 $promoClaim = PromoClaim::where('email', $lockedUser->email)->where('promo_code', $promoCode)->lockForUpdate()->first();
//                 if (! $promoClaim) throw new \Exception('Kode Promo tidak valid.');
//                 if ($promoClaim->is_used) throw new \Exception('Kode Promo sudah digunakan.');

//                 $minPurchase = ($currency === 'IDR') ? 499000 : 35;
//                 if ($totalAmount < $minPurchase) throw new \Exception("Minimum purchase is {$minPurchase}");

//                 $promoDiscountAmount = min($promoClaim->discount_value, $totalAmount);
//                 $appliedPromoCode = $promoClaim->promo_code;
//                 $promoClaim->update(['is_used' => true, 'used_at' => now()]);
//             }
//         }

//         $totalAfterPromo = max(0, $totalAmount - $promoDiscountAmount);

//         // 3. Kalkulasi Poin Loyalitas
//         // $earnedPoints = $lockedUser->is_membership ? floor($totalAmount / 100000) : 0;
//         $earnedPoints = floor($totalAfterPromo / 100000);
//         $pointsUsed = 0;

//         if ($request->use_points > 0 && $lockedUser->is_membership) {
//             $pointsUsed = min($request->use_points, $lockedUser->point);
//             $maxUsableDiscount = min($pointsUsed * 1000, $totalAfterPromo);
//             $pointsUsed = floor($maxUsableDiscount / 1000);

//             if ($pointsUsed > 0) {
//                 $lockedUser->decrement('point', $pointsUsed);
//             }
//         }

//         // 4. Kalkulasi Ongkos Kirim
//         $totalQuantity = $cartItems->sum('quantity') ?: 1;
//         $totalShippingCost = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);

//         return [
//             'totalAmount' => $totalAmount,
//             'finalItemPrices' => $finalItemPrices,
//             'promoDiscountAmount' => $promoDiscountAmount,
//             'appliedPromoCode' => $appliedPromoCode,
//             'earnedPoints' => $earnedPoints,
//             'pointsUsed' => $pointsUsed,
//             'totalShippingCost' => $totalShippingCost,
//             'totalQuantity' => $totalQuantity
//         ];
//     }
// }

namespace App\Actions\Checkout;

use App\Models\User;
use App\Models\PromoClaim;
use Illuminate\Http\Request;
use App\Services\PromoMerdekaService;

class CalculateCartTotalsAction
{
    public function execute(User $lockedUser, $cartItems, Request $request, PromoMerdekaService $promoService): array
    {
        $currency = $request->currency;
        $now = now();
        $totalAmount = 0;
        $finalItemPrices = [];

        // 1. KELOMPOKKAN ITEM BERDASARKAN KATEGORI & MIX GROUP
        $groupedItems = [];

        foreach ($cartItems as $item) {
            $cat = $item->product->category;
            $rawPromo = $cat->bundle_price;
            $promoConf = is_array($rawPromo) ? $rawPromo : (is_string($rawPromo) ? json_decode($rawPromo, true) : []);

            // Normalisasi struktur JSON lama ke format Engine baru
            if (is_numeric($promoConf)) {
                $promoConf = ['promo_type' => 'bundle', 'price' => ['IDR' => $promoConf]];
            }

            $isActive = $cat->bundle_qty &&
                (!$cat->bundle_start_date || $now >= $cat->bundle_start_date) &&
                (!$cat->bundle_end_date || $now <= $cat->bundle_end_date);

            if ($isActive && !empty($promoConf)) {
                $mixGroup = !empty($promoConf['mix_group']) ? $promoConf['mix_group'] : 'CAT_' . $cat->id;
                if (!isset($groupedItems[$mixGroup])) {
                    $groupedItems[$mixGroup] = [
                        'config' => $promoConf,
                        'bundle_qty' => $cat->bundle_qty,
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
                    $price = $this->resolveProductPrice($item->product, $currency, $now);
                    $totalAmount += ($item->quantity * $price);
                    $finalItemPrices[$item->id] = $price;
                }
                continue;
            }

            $conf = $group['config'];
            $type = $conf['promo_type'] ?? 'bundle';
            $items = $group['items'];

            if ($type === 'bundle') {
                $bundlePrice = $conf['price'][$currency] ?? ($conf['price']['IDR'] ?? 0);
                $bundleQty = $group['bundle_qty'];
                $totalQty = $items->sum('quantity');

                $bundleCount = floor($totalQty / $bundleQty);
                $remainderQty = $totalQty % $bundleQty;

                $totalAmount += ($bundleCount * $bundlePrice);

                // Urutkan dari termahal untuk didiskon, sisanya harga normal
                $sortedItems = $items->sortByDesc(function ($item) use ($currency, $now) {
                    return $this->resolveProductPrice($item->product, $currency, $now);
                });

                $assignedRemainder = 0;
                foreach ($sortedItems as $item) {
                    $normalPrice = $this->resolveProductPrice($item->product, $currency, $now);
                    if ($assignedRemainder < $remainderQty) {
                        $take = min($item->quantity, $remainderQty - $assignedRemainder);
                        $totalAmount += ($take * $normalPrice);
                        $assignedRemainder += $take;
                        $finalItemPrices[$item->id] = $normalPrice; // Harga satuan untuk refund
                    } else {
                        // Secara matematis, harga satuan barang di dalam bundle
                        $finalItemPrices[$item->id] = $bundlePrice / $bundleQty;
                    }
                }

            } elseif ($type === 'percent') {
                $groupSubtotal = 0;
                foreach ($items as $item) {
                    $price = $this->resolveProductPrice($item->product, $currency, $now);
                    $groupSubtotal += ($price * $item->quantity);
                }

                $minPurchase = $conf['min_purchase'] ?? 0;
                if ($groupSubtotal >= $minPurchase) {
                    $percent = $conf['percent'] ?? 0;
                    $maxDiscount = $conf['max_discount'] ?? 0;

                    $discount = $groupSubtotal * ($percent / 100);
                    if ($maxDiscount > 0 && $discount > $maxDiscount) {
                        $discount = $maxDiscount;
                    }

                    $totalAmount += ($groupSubtotal - $discount);
                    $ratio = ($groupSubtotal - $discount) / $groupSubtotal;

                    foreach ($items as $item) {
                        $price = $this->resolveProductPrice($item->product, $currency, $now);
                        $finalItemPrices[$item->id] = $price * $ratio; // Potongan proporsional
                    }
                } else {
                    $totalAmount += $groupSubtotal;
                    foreach ($items as $item) {
                        $finalItemPrices[$item->id] = $this->resolveProductPrice($item->product, $currency, $now);
                    }
                }
            }
        }

        // 3. Kalkulasi Promo/Voucher Manual
        $promoDiscountAmount = 0;
        $appliedPromoCode = null;

        if (!empty($request->promo_code)) {
            $promoCode = strtoupper(trim($request->promo_code));

            if ($promoCode === 'SOLHER17') {
                $claimCheck = PromoClaim::where('email', $lockedUser->email)->where('promo_code', 'SOLHER17')->lockForUpdate()->first();
                if (!$claimCheck) throw new \Exception('Akses ditolak: Anda belum mengklaim promo ini.');
                if ($claimCheck->is_used) throw new \Exception('Voucher SOLHER17 Anda sudah hangus/terpakai.');

                $promoResult = $promoService->calculatePromo($cartItems, []);
                if (!$promoResult['is_valid']) throw new \Exception($promoResult['message']);

                $promoDiscountAmount = $promoResult['discount_amount'];
                $appliedPromoCode = $promoResult['code'];
                $claimCheck->update(['is_used' => true, 'used_at' => now()]);

            } elseif ($promoCode === 'SOLHOST34') {
                $totalQuantityInCart = $cartItems->sum('quantity');
                if ($totalQuantityInCart > 1) throw new \Exception('Voucher Subsidi Tas hanya berlaku untuk 1 barang.');
                if ($request->use_points > 0) throw new \Exception('Voucher tidak dapat digabung dengan Poin.');

                $item = $cartItems->first();
                $catCode = strtoupper(trim($item->product->category->code ?? ''));
                if (!in_array($catCode, ['C001', 'C002', 'C003', 'C004'])) throw new \Exception('Voucher ini khusus untuk produk Tas.');

                $product = $item->product;
                if (!empty($product->discount_price) && (!$product->discount_start_date || $now >= $product->discount_start_date) && (!$product->discount_end_date || $now <= $product->discount_end_date)) {
                    throw new \Exception('Tidak berlaku pada barang yang sedang diskon.');
                }

                $claimCheck = PromoClaim::where('email', $lockedUser->email)->where('promo_code', 'SOLHOST34')->where('is_used', true)->first();
                if ($claimCheck) throw new \Exception('Voucher sudah pernah digunakan.');

                $promoDiscountAmount = 3400000;
                $appliedPromoCode = 'SOLHOST34';

                PromoClaim::updateOrCreate(
                    ['email' => $lockedUser->email, 'promo_code' => 'SOLHOST34'],
                    ['is_used' => true, 'used_at' => now(), 'discount_value' => 3400000, 'expires_at' => now()->addDays(365)]
                );
            } elseif ($promoCode === 'SOLHERMEMBER') {
                if (!$lockedUser->is_membership) throw new \Exception('Hanya untuk VIP Member.');
                if ($lockedUser->has_used_member_voucher) throw new \Exception('Voucher sudah pernah digunakan.');

                $promoDiscountAmount = ($currency === 'IDR') ? 500000 : 35;
                $appliedPromoCode = 'SOLHERMEMBER';
                $lockedUser->update(['has_used_member_voucher' => true]);
            } else {
                $promoClaim = PromoClaim::where('email', $lockedUser->email)->where('promo_code', $promoCode)->lockForUpdate()->first();
                if (!$promoClaim) throw new \Exception('Kode Promo tidak valid.');
                if ($promoClaim->is_used) throw new \Exception('Kode Promo sudah digunakan.');

                $minPurchase = ($currency === 'IDR') ? 499000 : 35;
                if ($totalAmount < $minPurchase) throw new \Exception("Minimum purchase is {$minPurchase}");

                $promoDiscountAmount = min($promoClaim->discount_value, $totalAmount);
                $appliedPromoCode = $promoClaim->promo_code;
                $promoClaim->update(['is_used' => true, 'used_at' => now()]);
            }
        }

        $totalAfterPromo = max(0, $totalAmount - $promoDiscountAmount);

        // 4. Kalkulasi Poin Loyalitas
        $earnedPoints = floor($totalAfterPromo / 100000);
        $pointsUsed = 0;

        if ($request->use_points > 0 && $lockedUser->is_membership) {
            $pointsUsed = min($request->use_points, $lockedUser->point);
            $maxUsableDiscount = min($pointsUsed * 1000, $totalAfterPromo);
            $pointsUsed = floor($maxUsableDiscount / 1000);

            if ($pointsUsed > 0) {
                $lockedUser->decrement('point', $pointsUsed);
            }
        }

        // 5. Kalkulasi Ongkos Kirim
        $totalQuantity = $cartItems->sum('quantity') ?: 1;
        $totalShippingCost = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);

        return [
            'totalAmount' => $totalAmount,
            'finalItemPrices' => $finalItemPrices,
            'promoDiscountAmount' => $promoDiscountAmount,
            'appliedPromoCode' => $appliedPromoCode,
            'earnedPoints' => $earnedPoints,
            'pointsUsed' => $pointsUsed,
            'totalShippingCost' => $totalShippingCost,
            'totalQuantity' => $totalQuantity
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
}
