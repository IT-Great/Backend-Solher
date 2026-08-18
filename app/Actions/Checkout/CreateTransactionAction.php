<?php

namespace App\Actions\Checkout;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CreateTransactionAction
{
    public function execute(User $lockedUser, Request $request, array $totals): Transaction
    {
        $orderId = 'SOL-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

        $affiliateId = null;
        $commissionEarned = 0;
        $commissionStatus = null;

        if (!empty($request->referral_code)) {
            $affiliateUser = User::where('referral_code', $request->referral_code)->where('is_affiliate', true)->first();
            if ($affiliateUser && $affiliateUser->id !== $lockedUser->id) {
                $affiliateId = $affiliateUser->id;
                $commissionRate = $affiliateUser->commission_rate ?? 5.00;
                $commissionEarned = $totals['totalAmount'] * ($commissionRate / 100);
                $commissionStatus = 'pending';
            }
        }

        return Transaction::create([
            'user_id' => $lockedUser->id,
            'address_id' => $request->address_id,
            'shipping_method' => $request->shipping_method,
            'shipping_cost' => $totals['totalShippingCost'],
            'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
            'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
            'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
            'order_id' => $orderId,
            'total_amount' => $totals['totalAmount'],
            'affiliate_id' => $affiliateId,
            'commission_earned' => $commissionEarned,
            'commission_status' => $commissionStatus,
            'status' => 'pending',
            'point' => $totals['earnedPoints'],
            'points_used' => $totals['pointsUsed'],
            'promo_code' => $totals['appliedPromoCode'],
            'promo_discount' => $totals['promoDiscountAmount'],
            'currency_code' => $request->currency,
        ]);
    }
}
