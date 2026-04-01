<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\Payment;
use App\Models\Transaction;
use App\Services\BiteshipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Xendit\Configuration;
use Xendit\Invoice\CreateInvoiceRequest;
use Xendit\Invoice\InvoiceApi;

class PaymentController extends Controller
{
    public function __construct()
    {
        Configuration::setXenditKey(config('services.xendit.secret_key'));
    }

    public function createInvoice(Request $request)
    {
        $request->validate([
            'transaction_id' => 'required|exists:transactions,id',
            'address_id' => 'required',
            'shipping_method' => 'required|in:free,biteship',
            'courier_company' => 'nullable|string',
            'courier_type' => 'nullable|string',
            'shipping_cost' => 'nullable|numeric', // Ini adalah Harga Dasar (Base Rate) dari Frontend
            'delivery_type' => 'nullable|string|in:now,later,scheduled',
            'delivery_date' => 'nullable|date',
            'delivery_time' => 'nullable|date_format:H:i',
            'use_points' => 'nullable|integer|min:0',
        ]);

        // $transaction = Transaction::with(['user', 'details.product', 'payment'])
        //     ->findOrFail($request->transaction_id)->where('user_id', $request->user()->id);

        // KODE BARU YANG BENAR:
        $transaction = Transaction::with(['user', 'details.product', 'payment'])
            ->where('user_id', $request->user()->id)
            ->findOrFail($request->transaction_id);

        if ($transaction->payment && $transaction->payment->status === 'pending' && ! empty($transaction->payment->checkout_url)) {
            return response()->json([
                'checkout_url' => $transaction->payment->checkout_url,
            ]);
        }

        // [PERBAIKAN LOGIKA] Hitung Total Quantity Barang
        $totalQuantity = $transaction->details->sum('quantity') ?: 1;

        if (! $transaction->shipping_cost || $transaction->shipping_cost == 0) {

            // Harga dasar pengiriman per item (atau per kg)
            $baseShippingRate = $request->shipping_method === 'free' ? 0 : $request->shipping_cost;

            // [PERBAIKAN LOGIKA] Total Shipping = Harga Dasar x Total Item
            $totalShippingCost = $baseShippingRate * $totalQuantity;

            $courierCompany = $request->shipping_method === 'free' ? 'Internal' : $request->courier_company;
            $courierType = $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type;

            $transaction->update([
                'address_id' => $request->address_id,
                'shipping_method' => $request->shipping_method,
                'courier_company' => $courierCompany,
                'courier_type' => $courierType,
                'shipping_cost' => $totalShippingCost, // Simpan Total Ongkir
                'total_amount' => $transaction->total_amount, // Tambahkan Total Ongkir ke Total Harga
                'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
                'delivery_date' => $request->delivery_date,
                'delivery_time' => $request->delivery_time,
                'status' => 'pending',
            ]);
        }

        // $user = $request->user();
        // $pointsUsed = 0;
        // $pointDiscountAmount = 0;
        // $conversionRate = 1000; // 1 Poin = Rp 1.000 Diskon

        // if ($request->use_points > 0 && $user->is_membership) {
        //     // Pastikan user tidak menggunakan poin lebih dari yang mereka miliki
        //     $pointsUsed = min($request->use_points, $user->point);
        //     $pointDiscountAmount = $pointsUsed * $conversionRate;

        //     // Pastikan diskon poin tidak melebihi harga produk (Subtotal)
        //     // Biasanya ongkir tidak boleh dipotong pakai poin, hanya harga barang
        //     $pointDiscountAmount = min($pointDiscountAmount, $transaction->total_amount);

        //     // Jika poin jadi dipakai, potong dari saldo user SEKARANG
        //     if ($pointsUsed > 0) {
        //         $user->decrement('point', $pointsUsed);
        //     }
        // }

        $user = $request->user();

        // [PERBAIKAN MUTLAK] Jangan pernah potong poin lagi di sini!
        // Ambil data poin yang sudah dipotong saat checkout awal di TransactionController.
        $pointsUsed = $transaction->points_used ?? 0;
        $conversionRate = 1000;
        $pointDiscountAmount = $pointsUsed * $conversionRate;

        // // Pastikan diskon poin tidak melebihi harga produk (Subtotal)
        // $pointDiscountAmount = min($pointDiscountAmount, $transaction->total_amount);

        // Pastikan diskon poin tidak melebihi harga produk (Subtotal) SETELAH promo
        // Kita hitung dulu sisa subtotal setelah promo
        $promoDiscount = $transaction->promo_discount ?? 0;
        $subtotalAfterPromo = max(0, $transaction->total_amount - $promoDiscount);
        $pointDiscountAmount = min($pointDiscountAmount, $subtotalAfterPromo);

        $externalId = 'PAY-'.$transaction->order_id.($transaction->payment ? '-'.time() : '');

        $items = [];
        foreach ($transaction->details as $detail) {

        // Ambil nama produk dasar
            $productName = $detail->product->name;

            // Tambahkan embel-embel warna jika ada di dalam detail transaksi
            if (!empty($detail->color)) {
                $productName .= ' - ' . $detail->color;
            }
            
            $items[] = [
                'name' => $productName,
                'quantity' => $detail->quantity,
                'price' => (int) $detail->price,
                'category' => 'PHYSICAL_PRODUCT',
            ];
        }

        // [PERBAIKAN 1]: Tambahkan Promo Code ke Xendit Items
        if ($promoDiscount > 0) {
            $items[] = [
                'name' => 'Promo Code: ' . ($transaction->promo_code ?? 'DISCOUNT'),
                'quantity' => 1,
                'price' => -(int) $promoDiscount,
                'category' => 'DISCOUNT',
            ];
        }

        // Tambahkan item "Diskon Poin" ke Invoice Xendit sebagai nilai minus
        if ($pointDiscountAmount > 0) {
            $items[] = [
                'name' => 'Loyalty Point Discount ('.$pointsUsed.' Pts)',
                'quantity' => 1,
                'price' => -(int) $pointDiscountAmount, // Nilai minus agar memotong total tagihan Xendit
                'category' => 'DISCOUNT',
            ];
        }

        // Penambahan Ongkir ke Xendit Invoice
        $basePriceXendit = 0;
        if ($transaction->shipping_cost > 0) {
            // Xendit butuh harga satuan (Base Price), jadi kita bagi kembali dari total_shipping_cost yang tersimpan
            $basePriceXendit = $transaction->shipping_cost / $totalQuantity;
            // $basePriceXendit = $transaction->shipping_cost;
            $items[] = [
                'name' => 'Shipping Cost ('.$transaction->courier_company.')',
                'quantity' => (int) $totalQuantity,
                'price' => (int) $basePriceXendit,
                'category' => 'SHIPPING_FEE',
            ];
        }

        // Hitung Total Pembayaran Akhir
        // $finalAmount = (int) $transaction->total_amount + ($basePriceXendit * $totalQuantity) - $pointDiscountAmount;

        // [PERBAIKAN 2]: Hitung Total Pembayaran Akhir dengan mengurangi Promo
        $finalAmount = (int) $transaction->total_amount
                     + ($basePriceXendit * $totalQuantity)
                     - $pointDiscountAmount
                     - $promoDiscount; // Kurangi promo di sini!

        $invoiceRequest = new CreateInvoiceRequest([
            'external_id' => $externalId,
            'payer_email' => $transaction->user->email,
            // 'amount' => (int) $transaction->total_amount + $basePriceXendit * $totalQuantity, // Sekarang nilainya sudah tepat secara matematika!
            'amount' => $finalAmount,
            'description' => 'Payment for Order '.$transaction->order_id,
            'items' => $items,
            'success_redirect_url' => config('app.frontend_url')
                .'/payment-success?external_id='.$externalId
                .'&order_id='.$transaction->order_id,
            'failure_redirect_url' => config('app.frontend_url').'/payment-failed',
        ]);

        $api = new InvoiceApi;
        $invoice = $api->createInvoice($invoiceRequest);

        // Payment::create([
        //     'transaction_id' => $transaction->id,
        //     'external_id' => $externalId,
        //     'checkout_url' => $invoice['invoice_url'],
        //     'amount' => $transaction->total_amount,
        //     'status' => 'pending',
        // ]);

        // return response()->json([
        //     'checkout_url' => $invoice['invoice_url'],
        // ]);

        // [PERBAIKAN MUTLAK] Gunakan updateOrCreate agar 1 Transaksi hanya memiliki 1 baris Payment
        Payment::updateOrCreate(
            ['transaction_id' => $transaction->id],
            [
                'external_id' => $externalId,
                'checkout_url' => $invoice['invoice_url'],
                'amount' => $transaction->total_amount,
                'status' => 'pending',
            ]
        );

        return response()->json([
            'checkout_url' => $invoice['invoice_url'],
        ]);
    }

    // Callback ini menangani perubahan status dari Xendit
    public function callback(Request $request)
    {
        // $xenditToken = config('services.xendit.webhook_token');
        // if ($request->header('x-callback-token') !== $xenditToken) {
        //     \Illuminate\Support\Facades\Log::critical('Fake Xendit Webhook Detected!', $request->all());

        //     return response()->json(['message' => 'Forbidden - Invalid Token'], 403);
        // }

        // [PERBAIKAN MUTLAK] Gunakan DB Transaction & LockForUpdate agar webhook antre!
        return DB::transaction(function () use ($request) {
            $payment = Payment::where('external_id', $request->external_id)->lockForUpdate()->first();

            if (! $payment) {
                return response()->json(['message' => 'Payment not found'], 404);
            }

            $status = $request->status;
            $transaction = Transaction::lockForUpdate()->find($payment->transaction_id);

            if ($status === 'PAID') {
                // Cegah eksekusi ganda jika status SUDAH PAID atau transaksi sudah diproses
                if ($payment->status === 'PAID' || in_array($transaction->status, ['processing', 'completed'])) {
                    return response()->json(['message' => 'Already processed']);
                }

                $payment->update(['status' => $status]);

                $paymentMethod = $request->input('payment_method', 'Unknown');
                $paymentChannel = $request->input('payment_channel', '');
                $fullPaymentMethod = trim($paymentMethod.' '.$paymentChannel);

                $targetTransactionStatus = ($transaction->shipping_method === 'free') ? 'completed' : 'processing';

                $transaction->update([
                    'status' => $targetTransactionStatus,
                    'payment_method' => $fullPaymentMethod,
                ]);

                if ($targetTransactionStatus === 'completed') {
                    $this->checkAndAssignMembership($transaction->user);
                    $transaction->user->refresh();

                    if ($transaction->point > 0 && $transaction->user->is_membership) {
                        $transaction->user->increment('point', $transaction->point);
                    }
                }

                // --- EKSEKUSI PEMESANAN KURIR (HANYA SEKALI!) ---
                if ($transaction->shipping_method === 'biteship') {
                    try {
                        $biteship = new BiteshipService;
                        $order = $biteship->createOrder($transaction);

                        if (isset($order['id'])) {
                            $transaction->update([
                                'biteship_order_id' => $order['id'],
                                'tracking_number' => $order['courier']['waybill_id'] ?? 'Pending',
                                'shipping_status' => strtolower($order['status'] ?? 'pending'),
                            ]);
                        } else {
                            $errorMsg = $order['error'] ?? ($order['message'] ?? 'Unknown Biteship API Error');
                            $transaction->update([
                                'tracking_number' => 'API ERR: '.substr($errorMsg, 0, 200),
                                'shipping_status' => 'error',
                            ]);
                            \Log::error('Biteship Create Order Failed: '.json_encode($order));
                        }
                    } catch (\Exception $e) {
                        $transaction->update([
                            'tracking_number' => 'SYS ERR: '.substr($e->getMessage(), 0, 200),
                            'shipping_status' => 'error',
                        ]);
                        \Log::error('Biteship Exception: '.$e->getMessage());
                    }
                } else {
                    $transaction->update([
                        'tracking_number' => 'In-Store Pickup',
                        'shipping_status' => 'ready_for_pickup',
                    ]);
                }
            } elseif ($status === 'EXPIRED' || $status === 'FAILED') {
                if ($transaction->status !== 'cancelled') {
                    $payment->update(['status' => $status]);
                    $transaction->update([
                        'status' => 'cancelled',
                        'shipping_status' => 'cancelled',
                    ]);

                    if ($transaction->points_used > 0) {
                        $transaction->user->increment('point', $transaction->points_used);
                    }

                    // [PERBAIKAN MUTLAK] Kembalikan stok barang yang gagal dibayar!
                    $transactionController = app(\App\Http\Controllers\TransactionController::class);
                    foreach ($transaction->details as $detail) {
                        $transactionController->restoreProductStock($detail->product_id, $detail->quantity);
                    }
                }
            } elseif ($status === 'PENDING' && $transaction->status === 'awaiting_payment') {
                $payment->update(['status' => $status]);
                $transaction->update(['status' => 'pending']);
            }

            return response()->json(['message' => 'Callback processed']);
        });
    }

    // public function getShippingRates(Request $request)
    // {
    //     $request->validate([
    //         'address_id' => 'required|exists:addresses,id',
    //         // [PERBAIKAN 1] Tangkap total barang dari keranjang
    //         'total_quantity' => 'required|integer|min:1',
    //     ]);

    //     $address = Address::find($request->address_id);

    //     if (! $address || ! $address->postal_code) {
    //         return response()->json([
    //             'message' => 'Alamat tidak valid atau kodepos tidak ditemukan.',
    //         ], 400);
    //     }

    //     try {
    //         $biteship = new BiteshipService;

    //         // [PERBAIKAN 2] Hitung berat riil (Asumsi 1 Tas = 1000 gram / 1 KG)
    //         $weight = $request->total_quantity * 1000;

    //         // Kirim berat riil ke Biteship
    //         $rates = $biteship->getRates($address, $weight);

    //         if (isset($rates['success']) && $rates['success'] === false) {
    //             return response()->json([
    //                 'message' => 'Biteship API Error: '.($rates['error'] ?? 'Unknown error'),
    //             ], 400);
    //         }

    //         return response()->json($rates);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'message' => 'Gagal mengambil ongkos kirim: '.$e->getMessage(),
    //         ], 500);
    //     }
    // }

    public function getShippingRates(Request $request)
    {
        $request->validate([
            'address_id' => 'required|exists:addresses,id',
            // [PERBAIKAN 1] Ganti total_quantity menjadi cart_ids array
            'cart_ids' => 'required|array',
            'cart_ids.*' => 'exists:carts,id',
        ]);

        $address = Address::find($request->address_id);

        if (! $address || ! $address->postal_code) {
            return response()->json([
                'message' => 'Alamat tidak valid atau kodepos tidak ditemukan.',
            ], 400);
        }

        try {
            $biteship = new BiteshipService;

            // [PERBAIKAN 2] Hitung Total Berat Aktual (Gram) dari Database secara aman
            // Pastikan Anda memuat relasi 'product'
            $cartItems = \App\Models\Cart::with('product')->whereIn('id', $request->cart_ids)->where('user_id', $request->user()->id)->get();

            $totalWeight = 0;
            foreach ($cartItems as $item) {
                // Ambil weight dari produk, jika null/kosong fallback ke 1000 gram
                $itemWeight = $item->product->weight ?? 1000;

                // Kalikan berat 1 barang dengan kuantitas yang dibeli
                $totalWeight += ($itemWeight * $item->quantity);
            }

            // Cegah berat 0 jika ada error data (Minimal 1 gram)
            if ($totalWeight <= 0) $totalWeight = 1000;

            // Kirim total berat riil ke Biteship
            $rates = $biteship->getRates($address, $totalWeight);

            if (isset($rates['success']) && $rates['success'] === false) {
                return response()->json([
                    'message' => 'Biteship API Error: '.($rates['error'] ?? 'Unknown error'),
                ], 400);
            }

            return response()->json($rates);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengambil ongkos kirim: '.$e->getMessage(),
            ], 500);
        }
    }

    // --- [BARU] HELPER FUNGSI UNTUK CEK MEMBERSHIP ---
    private function checkAndAssignMembership($user)
    {
        // Jika user sudah member, tidak perlu cek lagi
        if ($user->is_membership) {
            return;
        }

        // Hitung total belanja dari semua transaksi yang BERHASIL (completed)
        $totalSpent = Transaction::where('user_id', $user->id)
            ->where('status', 'completed')
            ->sum('total_amount'); // Hanya hitung harga barang, ongkir tidak termasuk

        // Jika total belanja >= 100.000, jadikan member
        if ($totalSpent >= 100000) {
            $user->update(['is_membership' => true]);
        }
    }
}
