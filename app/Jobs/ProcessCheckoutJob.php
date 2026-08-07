<?php

namespace App\Jobs;

use App\Events\ShippingStatusUpdated; // Atau Event khusus Checkout
use App\Http\Controllers\PaymentController;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\PromoClaim;
use App\Models\PromoCode;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessCheckoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $userId;
    protected $requestData;

    public function __construct($userId, array $requestData)
    {
        $this->userId = $userId;
        $this->requestData = $requestData;
    }

    public function handle(): void
    {
        try {
            $user = User::find($this->userId);
            if (!$user) return;

            $request = new \Illuminate\Http\Request($this->requestData);
            $cartItems = Cart::with('product.category')
                ->where('user_id', $user->id)
                ->whereIn('id', $this->requestData['cart_ids'])
                ->get();

            if ($cartItems->isEmpty()) return;

            // Memindahkan Logika Berat Checkout ke dalam Job
            $transactionData = DB::transaction(function () use ($user, $cartItems, $request) {
                $lockedUser = User::lockForUpdate()->find($user->id);
                $currency = $request->currency;
                $now = now();

                $totalAmount = 0;
                $gatewayItems = [];
                $finalItemPrices = [];

                $groupedByCategory = $cartItems->groupBy(function ($item) {
                    return $item->product->category_id;
                });

                foreach ($groupedByCategory as $categoryId => $items) {
                    $category = $items->first()->product->category;
                    $rawBundlePrice = $category->bundle_price;
                    $bundlePromo = is_string($rawBundlePrice) ? json_decode($rawBundlePrice, true) : ($rawBundlePrice ?? []);
                    if (is_numeric($bundlePromo)) {
                        $bundlePromo = ['IDR' => $bundlePromo];
                    }

                    $bundleQty = $category->bundle_qty;
                    $isPromoActive = $bundleQty && $bundlePromo &&
                        (!$category->bundle_start_date || $now >= $category->bundle_start_date) &&
                        (!$category->bundle_end_date || $now <= $category->bundle_end_date);

                    $totalQtyInCategory = $items->sum('quantity');

                    if ($isPromoActive && $totalQtyInCategory >= $bundleQty) {
                        $activeBundlePrice = $bundlePromo[$currency] ?? ($bundlePromo['IDR'] ?? 0);
                        $bundleCount = floor($totalQtyInCategory / $bundleQty);
                        $remainderQty = $totalQtyInCategory % $bundleQty;
                        $totalAmount += ($bundleCount * $activeBundlePrice);

                        $sortedItems = $items->sortBy(function ($item) use ($currency, $now) {
                            $prices = is_string($item->product->prices) ? json_decode($item->product->prices, true) : ($item->product->prices ?? []);
                            $discountPrices = is_string($item->product->discount_prices) ? json_decode($item->product->discount_prices, true) : ($item->product->discount_prices ?? []);
                            $basePrice = $prices[$currency] ?? $item->product->price;
                            $discountPrice = $discountPrices[$currency] ?? $item->product->discount_price;
                            return (!empty($discountPrice) && (!$item->product->discount_start_date || $now >= $item->product->discount_start_date) && (!$item->product->discount_end_date || $now <= $item->product->discount_end_date)) ? $discountPrice : $basePrice;
                        });

                        $remainderAssigned = 0;
                        foreach ($sortedItems as $item) {
                            $prices = is_string($item->product->prices) ? json_decode($item->product->prices, true) : ($item->product->prices ?? []);
                            $discountPrices = is_string($item->product->discount_prices) ? json_decode($item->product->discount_prices, true) : ($item->product->discount_prices ?? []);
                            $basePrice = $prices[$currency] ?? $item->product->price;
                            $discountPrice = $discountPrices[$currency] ?? $item->product->discount_price;
                            $normalPrice = (!empty($discountPrice) && (!$item->product->discount_start_date || $now >= $item->product->discount_start_date) && (!$item->product->discount_end_date || $now <= $item->product->discount_end_date)) ? $discountPrice : $basePrice;

                            if ($remainderAssigned < $remainderQty) {
                                $takeQty = min($item->quantity, $remainderQty - $remainderAssigned);
                                $totalAmount += ($takeQty * $normalPrice);
                                $remainderAssigned += $takeQty;
                            }
                            $finalItemPrices[$item->id] = $normalPrice;
                        }
                    } else {
                        foreach ($items as $item) {
                            $prices = is_string($item->product->prices) ? json_decode($item->product->prices, true) : ($item->product->prices ?? []);
                            $discountPrices = is_string($item->product->discount_prices) ? json_decode($item->product->discount_prices, true) : ($item->product->discount_prices ?? []);
                            $basePrice = $prices[$currency] ?? $item->product->price;
                            $discountPrice = $discountPrices[$currency] ?? $item->product->discount_price;
                            $normalPrice = (!empty($discountPrice) && (!$item->product->discount_start_date || $now >= $item->product->discount_start_date) && (!$item->product->discount_end_date || $now <= $item->product->discount_end_date)) ? $discountPrice : $basePrice;

                            $totalAmount += ($item->quantity * $normalPrice);
                            $finalItemPrices[$item->id] = $normalPrice;
                        }
                    }
                }

                $promoDiscountAmount = 0;
                $appliedPromoCode = null;

                if (!empty($request->promo_code)) {
                    $promoCode = strtoupper(trim($request->promo_code));
                    if ($promoCode === 'SOLHOST34') {
                        $promoDiscountAmount = 3400000;
                        $appliedPromoCode = 'SOLHOST34';
                        PromoClaim::updateOrCreate(
                            ['email' => $lockedUser->email, 'promo_code' => 'SOLHOST34'],
                            ['is_used' => true, 'used_at' => now(), 'discount_value' => 3400000, 'expires_at' => now()->addDays(365)]
                        );
                    } elseif ($promoCode === 'SOLHERMEMBER') {
                        $promoDiscountAmount = ($currency === 'IDR') ? 500000 : 35;
                        $appliedPromoCode = 'SOLHERMEMBER';
                        $lockedUser->update(['has_used_member_voucher' => true]);
                    } else {
                        $promoClaim = PromoClaim::where('email', $lockedUser->email)->where('promo_code', $promoCode)->lockForUpdate()->first();
                        if ($promoClaim && !$promoClaim->is_used) {
                            $promoDiscountAmount = min($promoClaim->discount_value, $totalAmount);
                            $appliedPromoCode = $promoClaim->promo_code;
                            $promoClaim->update(['is_used' => true, 'used_at' => now()]);
                        }
                    }
                }

                $totalAfterPromo = max(0, $totalAmount - $promoDiscountAmount);
                $orderId = 'SOL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
                $earnedPoints = $lockedUser->is_membership ? floor($totalAmount / 100000) : 0;
                $pointsUsed = 0;

                if ($request->use_points > 0 && $lockedUser->is_membership) {
                    $pointsUsed = min($request->use_points, $lockedUser->point);
                    $maxUsableDiscount = min($pointsUsed * 1000, $totalAfterPromo);
                    $pointsUsed = floor($maxUsableDiscount / 1000);
                    if ($pointsUsed > 0) {
                        $lockedUser->decrement('point', $pointsUsed);
                    }
                }

                $totalShippingCost = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);

                $transaction = Transaction::create([
                    'user_id' => $lockedUser->id,
                    'address_id' => $request->address_id,
                    'shipping_method' => $request->shipping_method,
                    'shipping_cost' => $totalShippingCost,
                    'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
                    'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
                    'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
                    'order_id' => $orderId,
                    'total_amount' => $totalAmount,
                    'status' => 'pending',
                    'point' => $earnedPoints,
                    'points_used' => $pointsUsed,
                    'promo_code' => $appliedPromoCode,
                    'promo_discount' => $promoDiscountAmount,
                    'currency_code' => $currency,
                ]);

                foreach ($cartItems as $item) {
                    $product = Product::lockForUpdate()->find($item->product_id);
                    $savedPrice = $finalItemPrices[$item->id] ?? $product->price;

                    TransactionDetail::create([
                        'transaction_id' => $transaction->id,
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                        'price' => $savedPrice,
                        'color' => $item->color,
                    ]);

                    $product->decrement('stock', $item->quantity);
                }

                // Hapus Keranjang
                Cart::where('user_id', $lockedUser->id)->whereIn('id', $this->requestData['cart_ids'])->delete();

                return $transaction;
            });

            // Generate Invoice via PaymentController
            $paymentController = app(PaymentController::class);
            $invoiceRequest = new \Illuminate\Http\Request([
                'transaction_id' => $transactionData->id,
                'address_id' => $this->requestData['address_id'],
                'shipping_method' => $this->requestData['shipping_method'],
                'courier_company' => $this->requestData['courier_company'] ?? null,
                'courier_type' => $this->requestData['courier_type'] ?? null,
                'shipping_cost' => $this->requestData['shipping_cost'] ?? 0,
                'currency' => $this->requestData['currency'],
            ]);

            $invoiceRequest->setUserResolver(fn () => $user);
            $invoiceResponse = $paymentController->createInvoice($invoiceRequest);

            $responseData = json_decode($invoiceResponse->getContent(), true);

            // Broadcast Event WebSockets ke User bahwa invoice siap
            broadcast(new ShippingStatusUpdated(
                $transactionData,
                $responseData['checkout_url'] ?? null
            ));

        } catch (\Throwable $e) {
            Log::error('ASYNC CHECKOUT JOB ERROR: ' . $e->getMessage());
        }
    }
}
