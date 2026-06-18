<?php

// namespace App\Http\Controllers;

// use App\Models\Address;
// use App\Models\Cart;
// use App\Models\Payment;
// use App\Models\Transaction;
// use App\Services\BiteshipService;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;
// use Xendit\Configuration;
// use Xendit\Invoice\CreateInvoiceRequest;
// use Xendit\Invoice\InvoiceApi;

// class PaymentController extends Controller
// {
//     public function __construct()
//     {
//         Configuration::setXenditKey(config('services.xendit.secret_key'));
//     }

//     public function createInvoice(Request $request)
//     {
//         $request->validate([
//             'transaction_id' => 'required|exists:transactions,id',
//             'address_id' => 'required',
//             'shipping_method' => 'required|in:free,biteship',
//             'courier_company' => 'nullable|string',
//             'courier_type' => 'nullable|string',
//             'shipping_cost' => 'nullable|numeric', // Ini adalah Harga Dasar (Base Rate) dari Frontend
//             'delivery_type' => 'nullable|string|in:now,later,scheduled',
//             'delivery_date' => 'nullable|date',
//             'delivery_time' => 'nullable|date_format:H:i',
//             'use_points' => 'nullable|integer|min:0',
//         ]);

//         // $transaction = Transaction::with(['user', 'details.product', 'payment'])
//         //     ->findOrFail($request->transaction_id)->where('user_id', $request->user()->id);

//         // KODE BARU YANG BENAR:
//         $transaction = Transaction::with(['user', 'details.product', 'payment'])
//             ->where('user_id', $request->user()->id)
//             ->findOrFail($request->transaction_id);

//         if ($transaction->payment && $transaction->payment->status === 'pending' && ! empty($transaction->payment->checkout_url)) {
//             return response()->json([
//                 'checkout_url' => $transaction->payment->checkout_url,
//             ]);
//         }

//         // [PERBAIKAN LOGIKA] Hitung Total Quantity Barang
//         $totalQuantity = $transaction->details->sum('quantity') ?: 1;

//         if (! $transaction->shipping_cost || $transaction->shipping_cost == 0) {

//             // Harga dasar pengiriman per item (atau per kg)
//             $baseShippingRate = $request->shipping_method === 'free' ? 0 : $request->shipping_cost;

//             // [PERBAIKAN LOGIKA] Total Shipping = Harga Dasar x Total Item
//             $totalShippingCost = $baseShippingRate * $totalQuantity;

//             $courierCompany = $request->shipping_method === 'free' ? 'Internal' : $request->courier_company;
//             $courierType = $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type;

//             $transaction->update([
//                 'address_id' => $request->address_id,
//                 'shipping_method' => $request->shipping_method,
//                 'courier_company' => $courierCompany,
//                 'courier_type' => $courierType,
//                 'shipping_cost' => $totalShippingCost, // Simpan Total Ongkir
//                 'total_amount' => $transaction->total_amount, // Tambahkan Total Ongkir ke Total Harga
//                 'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
//                 'delivery_date' => $request->delivery_date,
//                 'delivery_time' => $request->delivery_time,
//                 'status' => 'pending',
//             ]);
//         }

//         // $user = $request->user();
//         // $pointsUsed = 0;
//         // $pointDiscountAmount = 0;
//         // $conversionRate = 1000; // 1 Poin = Rp 1.000 Diskon

//         // if ($request->use_points > 0 && $user->is_membership) {
//         //     // Pastikan user tidak menggunakan poin lebih dari yang mereka miliki
//         //     $pointsUsed = min($request->use_points, $user->point);
//         //     $pointDiscountAmount = $pointsUsed * $conversionRate;

//         //     // Pastikan diskon poin tidak melebihi harga produk (Subtotal)
//         //     // Biasanya ongkir tidak boleh dipotong pakai poin, hanya harga barang
//         //     $pointDiscountAmount = min($pointDiscountAmount, $transaction->total_amount);

//         //     // Jika poin jadi dipakai, potong dari saldo user SEKARANG
//         //     if ($pointsUsed > 0) {
//         //         $user->decrement('point', $pointsUsed);
//         //     }
//         // }

//         $user = $request->user();

//         // [PERBAIKAN MUTLAK] Jangan pernah potong poin lagi di sini!
//         // Ambil data poin yang sudah dipotong saat checkout awal di TransactionController.
//         $pointsUsed = $transaction->points_used ?? 0;
//         $conversionRate = 1000;
//         $pointDiscountAmount = $pointsUsed * $conversionRate;

//         // // Pastikan diskon poin tidak melebihi harga produk (Subtotal)
//         // $pointDiscountAmount = min($pointDiscountAmount, $transaction->total_amount);

//         // Pastikan diskon poin tidak melebihi harga produk (Subtotal) SETELAH promo
//         // Kita hitung dulu sisa subtotal setelah promo
//         $promoDiscount = $transaction->promo_discount ?? 0;
//         $subtotalAfterPromo = max(0, $transaction->total_amount - $promoDiscount);
//         $pointDiscountAmount = min($pointDiscountAmount, $subtotalAfterPromo);

//         $externalId = 'PAY-'.$transaction->order_id.($transaction->payment ? '-'.time() : '');

//         $items = [];
//         foreach ($transaction->details as $detail) {

//             // Ambil nama produk dasar
//             $productName = $detail->product->name;

//             // Tambahkan embel-embel warna jika ada di dalam detail transaksi
//             if (! empty($detail->color)) {
//                 $productName .= ' - '.$detail->color;
//             }

//             $items[] = [
//                 'name' => $productName,
//                 'quantity' => $detail->quantity,
//                 'price' => (int) $detail->price,
//                 'category' => 'PHYSICAL_PRODUCT',
//             ];
//         }

//         // [PERBAIKAN 1]: Tambahkan Promo Code ke Xendit Items
//         if ($promoDiscount > 0) {
//             $items[] = [
//                 'name' => 'Promo Code: '.($transaction->promo_code ?? 'DISCOUNT'),
//                 'quantity' => 1,
//                 'price' => -(int) $promoDiscount,
//                 'category' => 'DISCOUNT',
//             ];
//         }

//         // Tambahkan item "Diskon Poin" ke Invoice Xendit sebagai nilai minus
//         if ($pointDiscountAmount > 0) {
//             $items[] = [
//                 'name' => 'Loyalty Point Discount ('.$pointsUsed.' Pts)',
//                 'quantity' => 1,
//                 'price' => -(int) $pointDiscountAmount, // Nilai minus agar memotong total tagihan Xendit
//                 'category' => 'DISCOUNT',
//             ];
//         }

//         // Penambahan Ongkir ke Xendit Invoice
//         $basePriceXendit = 0;
//         if ($transaction->shipping_cost > 0) {
//             // Xendit butuh harga satuan (Base Price), jadi kita bagi kembali dari total_shipping_cost yang tersimpan
//             $basePriceXendit = $transaction->shipping_cost / $totalQuantity;
//             // $basePriceXendit = $transaction->shipping_cost;
//             $items[] = [
//                 'name' => 'Shipping Cost ('.$transaction->courier_company.')',
//                 'quantity' => (int) $totalQuantity,
//                 'price' => (int) $basePriceXendit,
//                 'category' => 'SHIPPING_FEE',
//             ];
//         }

//         // Hitung Total Pembayaran Akhir
//         // $finalAmount = (int) $transaction->total_amount + ($basePriceXendit * $totalQuantity) - $pointDiscountAmount;

//         // [PERBAIKAN 2]: Hitung Total Pembayaran Akhir dengan mengurangi Promo
//         $finalAmount = (int) $transaction->total_amount
//                      + ($basePriceXendit * $totalQuantity)
//                      - $pointDiscountAmount
//                      - $promoDiscount; // Kurangi promo di sini!

//         $invoiceRequest = new CreateInvoiceRequest([
//             'external_id' => $externalId,
//             'payer_email' => $transaction->user->email,
//             // 'amount' => (int) $transaction->total_amount + $basePriceXendit * $totalQuantity, // Sekarang nilainya sudah tepat secara matematika!
//             'amount' => $finalAmount,
//             'description' => 'Payment for Order '.$transaction->order_id,
//             'items' => $items,
//             'success_redirect_url' => config('app.frontend_url')
//                 .'/payment-success?external_id='.$externalId
//                 .'&order_id='.$transaction->order_id,
//             'failure_redirect_url' => config('app.frontend_url').'/payment-failed',
//         ]);

//         $api = new InvoiceApi;
//         $invoice = $api->createInvoice($invoiceRequest);

//         // Payment::create([
//         //     'transaction_id' => $transaction->id,
//         //     'external_id' => $externalId,
//         //     'checkout_url' => $invoice['invoice_url'],
//         //     'amount' => $transaction->total_amount,
//         //     'status' => 'pending',
//         // ]);

//         // return response()->json([
//         //     'checkout_url' => $invoice['invoice_url'],
//         // ]);

//         // [PERBAIKAN MUTLAK] Gunakan updateOrCreate agar 1 Transaksi hanya memiliki 1 baris Payment
//         Payment::updateOrCreate(
//             ['transaction_id' => $transaction->id],
//             [
//                 'external_id' => $externalId,
//                 'checkout_url' => $invoice['invoice_url'],
//                 'amount' => $transaction->total_amount,
//                 'status' => 'pending',
//             ]
//         );

//         return response()->json([
//             'checkout_url' => $invoice['invoice_url'],
//         ]);
//     }

//     // Callback ini menangani perubahan status dari Xendit
//     public function callback(Request $request)
//     {
//         // $xenditToken = config('services.xendit.webhook_token');
//         // if ($request->header('x-callback-token') !== $xenditToken) {
//         //     \Illuminate\Support\Facades\Log::critical('Fake Xendit Webhook Detected!', $request->all());

//         //     return response()->json(['message' => 'Forbidden - Invalid Token'], 403);
//         // }

//         // [PERBAIKAN MUTLAK] Gunakan DB Transaction & LockForUpdate agar webhook antre!
//         return DB::transaction(function () use ($request) {
//             $payment = Payment::where('external_id', $request->external_id)->lockForUpdate()->first();

//             if (! $payment) {
//                 return response()->json(['message' => 'Payment not found'], 404);
//             }

//             $status = $request->status;
//             $transaction = Transaction::lockForUpdate()->find($payment->transaction_id);

//             if ($status === 'PAID') {
//                 // Cegah eksekusi ganda jika status SUDAH PAID atau transaksi sudah diproses
//                 if ($payment->status === 'PAID' || in_array($transaction->status, ['processing', 'completed'])) {
//                     return response()->json(['message' => 'Already processed']);
//                 }

//                 $payment->update(['status' => $status]);

//                 $paymentMethod = $request->input('payment_method', 'Unknown');
//                 $paymentChannel = $request->input('payment_channel', '');
//                 $fullPaymentMethod = trim($paymentMethod.' '.$paymentChannel);

//                 $targetTransactionStatus = ($transaction->shipping_method === 'free') ? 'completed' : 'processing';

//                 $transaction->update([
//                     'status' => $targetTransactionStatus,
//                     'payment_method' => $fullPaymentMethod,
//                 ]);

//                 // if ($targetTransactionStatus === 'completed') {
//                 //     $this->checkAndAssignMembership($transaction->user);
//                 //     $transaction->user->refresh();

//                 //     if ($transaction->point > 0 && $transaction->user->is_membership) {
//                 //         $transaction->user->increment('point', $transaction->point);
//                 //     }
//                 // }

//                 // [PERBAIKAN] Jangan panggil Biteship di dalam transaksi yang sedang berjalan!
//                 if ($transaction->shipping_method === 'biteship') {
//                     DB::afterCommit(function () use ($transaction) {
//                         // Blok ini hanya akan dieksekusi SETELAH transaksi database sukses disimpan dan LOCK dilepas.
//                         // Server database aman, web tidak akan nge-hang!
//                         try {
//                             $biteship = new BiteshipService;
//                             $order = $biteship->createOrder($transaction);

//                             if (isset($order['id'])) {
//                                 // Update tabel (tidak butuh lock lagi karena status sudah berubah)
//                                 $transaction->update([
//                                     'biteship_order_id' => $order['id'],
//                                     'tracking_number' => $order['courier']['waybill_id'] ?? 'Pending',
//                                     'shipping_status' => strtolower($order['status'] ?? 'pending'),
//                                 ]);
//                             }
//                         } catch (\Exception $e) {
//                             \Log::error('Biteship Exception: '.$e->getMessage());
//                         }
//                     });
//                 } else {
//                     // Jika kurir internal/pickup
//                     $transaction->update([
//                         'tracking_number' => 'In-Store Pickup',
//                         'shipping_status' => 'ready_for_pickup',
//                     ]);
//                 }

//                 // --- EKSEKUSI PEMESANAN KURIR (HANYA SEKALI!) ---
//                 // if ($transaction->shipping_method === 'biteship') {
//                 //     try {
//                 //         $biteship = new BiteshipService;
//                 //         $order = $biteship->createOrder($transaction);

//                 //         if (isset($order['id'])) {
//                 //             $transaction->update([
//                 //                 'biteship_order_id' => $order['id'],
//                 //                 'tracking_number' => $order['courier']['waybill_id'] ?? 'Pending',
//                 //                 'shipping_status' => strtolower($order['status'] ?? 'pending'),
//                 //             ]);
//                 //         } else {
//                 //             $errorMsg = $order['error'] ?? ($order['message'] ?? 'Unknown Biteship API Error');
//                 //             $transaction->update([
//                 //                 'tracking_number' => 'API ERR: '.substr($errorMsg, 0, 200),
//                 //                 'shipping_status' => 'error',
//                 //             ]);
//                 //             \Log::error('Biteship Create Order Failed: '.json_encode($order));
//                 //         }
//                 //     } catch (\Exception $e) {
//                 //         $transaction->update([
//                 //             'tracking_number' => 'SYS ERR: '.substr($e->getMessage(), 0, 200),
//                 //             'shipping_status' => 'error',
//                 //         ]);
//                 //         \Log::error('Biteship Exception: '.$e->getMessage());
//                 //     }
//                 // } else {
//                 //     $transaction->update([
//                 //         'tracking_number' => 'In-Store Pickup',
//                 //         'shipping_status' => 'ready_for_pickup',
//                 //     ]);
//                 // }
//             }
//             elseif ($status === 'EXPIRED' || $status === 'FAILED') {
//                 if ($transaction->status !== 'cancelled') {
//                     $payment->update(['status' => $status]);
//                     $transaction->update([
//                         'status' => 'cancelled',
//                         'shipping_status' => 'cancelled',
//                     ]);

//                     if ($transaction->points_used > 0) {
//                         $transaction->user->increment('point', $transaction->points_used);
//                     }

//                     // [PERBAIKAN MUTLAK] Kembalikan stok barang yang gagal dibayar!
//                     $transactionController = app(TransactionController::class);
//                     foreach ($transaction->details as $detail) {
//                         $transactionController->restoreProductStock($detail->product_id, $detail->quantity);
//                     }
//                 }
//             } elseif ($status === 'PENDING' && $transaction->status === 'awaiting_payment') {
//                 $payment->update(['status' => $status]);
//                 $transaction->update(['status' => 'pending']);
//             }

//             return response()->json(['message' => 'Callback processed']);
//         });
//     }

//     // public function getShippingRates(Request $request)
//     // {
//     //     $request->validate([
//     //         'address_id' => 'required|exists:addresses,id',
//     //         // [PERBAIKAN 1] Tangkap total barang dari keranjang
//     //         'total_quantity' => 'required|integer|min:1',
//     //     ]);

//     //     $address = Address::find($request->address_id);

//     //     if (! $address || ! $address->postal_code) {
//     //         return response()->json([
//     //             'message' => 'Alamat tidak valid atau kodepos tidak ditemukan.',
//     //         ], 400);
//     //     }

//     //     try {
//     //         $biteship = new BiteshipService;

//     //         // [PERBAIKAN 2] Hitung berat riil (Asumsi 1 Tas = 1000 gram / 1 KG)
//     //         $weight = $request->total_quantity * 1000;

//     //         // Kirim berat riil ke Biteship
//     //         $rates = $biteship->getRates($address, $weight);

//     //         if (isset($rates['success']) && $rates['success'] === false) {
//     //             return response()->json([
//     //                 'message' => 'Biteship API Error: '.($rates['error'] ?? 'Unknown error'),
//     //             ], 400);
//     //         }

//     //         return response()->json($rates);
//     //     } catch (\Exception $e) {
//     //         return response()->json([
//     //             'message' => 'Gagal mengambil ongkos kirim: '.$e->getMessage(),
//     //         ], 500);
//     //     }
//     // }

//     public function getShippingRates(Request $request)
//     {
//         // [PERBAIKAN PENTING] Pengaman ganda jika User tidak terdeteksi (Token expired/hilang)
//         $user = $request->user();
//         if (! $user) {
//             return response()->json([
//                 'message' => 'Unauthorized. Please login again.',
//             ], 401);
//         }

//         $request->validate([
//             'address_id' => 'required|exists:addresses,id',
//             // [PERBAIKAN 1] Ganti total_quantity menjadi cart_ids array
//             'cart_ids' => 'required|array',
//             'cart_ids.*' => 'exists:carts,id',
//         ]);

//         $address = Address::find($request->address_id);

//         if (! $address || ! $address->postal_code) {
//             return response()->json([
//                 'message' => 'Alamat tidak valid atau kodepos tidak ditemukan.',
//             ], 400);
//         }

//         try {
//             $biteship = new BiteshipService;

//             // [PERBAIKAN 2] Hitung Total Berat Aktual (Gram) dari Database secara aman
//             // Pastikan Anda memuat relasi 'product'
//             $cartItems = Cart::with('product')->whereIn('id', $request->cart_ids)->where('user_id', $user->id)->get();

//             $totalWeight = 0;
//             foreach ($cartItems as $item) {
//                 // Ambil weight dari produk, jika null/kosong fallback ke 1000 gram
//                 $itemWeight = $item->product->weight ?? 1000;

//                 // Kalikan berat 1 barang dengan kuantitas yang dibeli
//                 $totalWeight += ($itemWeight * $item->quantity);
//             }

//             // Cegah berat 0 jika ada error data (Minimal 1 gram)
//             if ($totalWeight <= 0) {
//                 $totalWeight = 1000;
//             }

//             // Kirim total berat riil ke Biteship
//             $rates = $biteship->getRates($address, $totalWeight);

//             if (isset($rates['success']) && $rates['success'] === false) {
//                 return response()->json([
//                     'message' => 'Biteship API Error: '.($rates['error'] ?? 'Unknown error'),
//                 ], 400);
//             }

//             return response()->json($rates);
//         } catch (\Exception $e) {
//             return response()->json([
//                 'message' => 'Gagal mengambil ongkos kirim: '.$e->getMessage(),
//             ], 500);
//         }
//     }

//     // --- [BARU] HELPER FUNGSI UNTUK CEK MEMBERSHIP ---
//     private function checkAndAssignMembership($user)
//     {
//         // Jika user sudah member, tidak perlu cek lagi
//         if ($user->is_membership) {
//             return;
//         }

//         // Hitung total belanja dari semua transaksi yang BERHASIL (completed)
//         $totalSpent = Transaction::where('user_id', $user->id)
//             ->where('status', 'completed')
//             ->sum('total_amount'); // Hanya hitung harga barang, ongkir tidak termasuk

//         // Jika total belanja >= 100.000, jadikan member
//         if ($totalSpent >= 100000) {
//             $user->update(['is_membership' => true]);
//         }
//     }
// }

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\Cart;
use App\Models\Payment;
use App\Models\Transaction;
// use App\Services\BiteshipService;
use App\Services\ShippingFactory; // [BARU] Import Shipping Factory
use App\Services\PaymentFactory; // TAMBAHKAN IMPORT INI
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    // HAPUS function __construct() karena inisialisasi Xendit tidak lagi dilakukan di sini

    public function createInvoice(Request $request)
    {
        $request->validate([
            'transaction_id' => 'required|exists:transactions,id',
            'address_id' => 'required',
            'shipping_method' => 'required|in:free,biteship',
            'courier_company' => 'nullable|string',
            'courier_type' => 'nullable|string',
            'shipping_cost' => 'nullable|numeric',
            'delivery_type' => 'nullable|string|in:now,later,scheduled',
            'delivery_date' => 'nullable|date',
            'delivery_time' => 'nullable|date_format:H:i',
            'use_points' => 'nullable|integer|min:0',
            'currency' => 'required|string|in:IDR,USD,SGD,EUR', // [BARU] Wajibkan Vue mengirim ini
        ]);

        $transaction = Transaction::with(['user', 'details.product', 'payment'])
            ->where('user_id', $request->user()->id)
            ->findOrFail($request->transaction_id);

        if ($transaction->payment && $transaction->payment->status === 'pending' && ! empty($transaction->payment->checkout_url)) {
            return response()->json([
                'checkout_url' => $transaction->payment->checkout_url,
            ]);
        }

        $totalQuantity = $transaction->details->sum('quantity') ?: 1;

        if (! $transaction->shipping_cost || $transaction->shipping_cost == 0) {
            $baseShippingRate = $request->shipping_method === 'free' ? 0 : $request->shipping_cost;
            $totalShippingCost = $baseShippingRate * $totalQuantity;

            $courierCompany = $request->shipping_method === 'free' ? 'Internal' : $request->courier_company;
            $courierType = $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type;

            $transaction->update([
                'address_id' => $request->address_id,
                'shipping_method' => $request->shipping_method,
                'courier_company' => $courierCompany,
                'courier_type' => $courierType,
                'shipping_cost' => $totalShippingCost,
                'total_amount' => $transaction->total_amount,
                'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
                'delivery_date' => $request->delivery_date,
                'delivery_time' => $request->delivery_time,
                'status' => 'pending',
                'currency_code' => $request->currency, // [BARU] Simpan USD/IDR ke database
            ]);
        } else {
            // Jika ongkir sudah ada, pastikan currency tetap diupdate
            $transaction->update([
                'currency_code' => $request->currency,
            ]);
        }

        $user = $request->user();

        $pointsUsed = $transaction->points_used ?? 0;
        $conversionRate = 1000;
        $pointDiscountAmount = $pointsUsed * $conversionRate;

        $promoDiscount = $transaction->promo_discount ?? 0;
        $subtotalAfterPromo = max(0, $transaction->total_amount - $promoDiscount);
        $pointDiscountAmount = min($pointDiscountAmount, $subtotalAfterPromo);

        $externalId = 'PAY-'.$transaction->order_id.($transaction->payment ? '-'.time() : '');

        $items = [];
        foreach ($transaction->details as $detail) {
            $productName = $detail->product->name;
            if (! empty($detail->color)) {
                $productName .= ' - '.$detail->color;
            }

            $items[] = [
                'name' => $productName,
                'quantity' => $detail->quantity,
                'price' => (int) $detail->price,
                'category' => 'PHYSICAL_PRODUCT',
            ];
        }

        if ($promoDiscount > 0) {
            $items[] = [
                'name' => 'Promo Code: '.($transaction->promo_code ?? 'DISCOUNT'),
                'quantity' => 1,
                'price' => -(int) $promoDiscount,
                'category' => 'DISCOUNT',
            ];
        }

        if ($pointDiscountAmount > 0) {
            $items[] = [
                'name' => 'Loyalty Point Discount ('.$pointsUsed.' Pts)',
                'quantity' => 1,
                'price' => -(int) $pointDiscountAmount,
                'category' => 'DISCOUNT',
            ];
        }

        $basePriceXendit = 0;
        if ($transaction->shipping_cost > 0) {
            $basePriceXendit = $transaction->shipping_cost / $totalQuantity;
            $items[] = [
                'name' => 'Shipping Cost ('.$transaction->courier_company.')',
                'quantity' => (int) $totalQuantity,
                'price' => (int) $basePriceXendit,
                'category' => 'SHIPPING_FEE',
            ];
        }

        $finalAmount = (int) $transaction->total_amount
                     + ($basePriceXendit * $totalQuantity)
                     - $pointDiscountAmount
                     - $promoDiscount;

        // [LOGIKA BARU]: Deteksi mata uang dan panggil Factory
        $currency = $transaction->currency_code ?? 'IDR';

        $paymentGateway = PaymentFactory::make($currency);

        // [LOGIKA BARU]: Deteksi mata uang dan panggil Factory
        $currency = $transaction->currency_code ?? 'IDR';
        $paymentGateway = PaymentFactory::make($currency);

        // 1. Tentukan URL sukses standar (Untuk Xendit -> Langsung ke Vue.js)
        $frontendSuccessUrl = config('app.frontend_url')
            . '/payment-success?external_id=' . $externalId
            . '&order_id=' . $transaction->order_id;

        // 2. Tentukan URL sukses khusus PayPal (Untuk PayPal -> Masuk Jembatan Capture dulu)
        $paypalCaptureUrl = url('/api/payments/paypal-capture?external_id=' . $externalId . '&order_id=' . $transaction->order_id);

        // 3. Logika Kondisional Penentu Arah
        $dynamicSuccessUrl = ($currency === 'IDR') ? $frontendSuccessUrl : $paypalCaptureUrl;

        $checkoutUrl = $paymentGateway->createInvoice([
            'order_id' => $transaction->order_id,
            'external_id' => $externalId,
            'payer_email' => $transaction->user->email,
            'amount' => $finalAmount,
            'currency' => $currency, 
            'items' => $items,
            
            // 🔥 Gunakan variabel dinamis di sini
            'success_redirect_url' => $dynamicSuccessUrl, 
            
            'failure_redirect_url' => config('app.frontend_url').'/payment-failed',
        ]);

        // $checkoutUrl = $paymentGateway->createInvoice([
        //     'order_id' => $transaction->order_id,
        //     'external_id' => $externalId,
        //     'payer_email' => $transaction->user->email,
        //     'amount' => $finalAmount,
        //     'currency' => $currency, // Perlu diteruskan ke gateway
        //     'items' => $items,
        //     // 'success_redirect_url' => config('app.frontend_url')
        //     //     .'/payment-success?external_id='.$externalId
        //     //     .'&order_id='.$transaction->order_id,
        //     'success_redirect_url' => url('/api/payments/paypal-capture?external_id='.$externalId.'&order_id='.$transaction->order_id),
        //     'failure_redirect_url' => config('app.frontend_url').'/payment-failed',
        // ]);

        Payment::updateOrCreate(
            ['transaction_id' => $transaction->id],
            [
                'external_id' => $externalId,
                'checkout_url' => $checkoutUrl,
                'amount' => $transaction->total_amount,
                'status' => 'pending',
            ]
        );

        return response()->json([
            'checkout_url' => $checkoutUrl,
            'gateway' => $currency === 'IDR' ? 'Xendit' : 'Stripe', // Tambahan info untuk frontend
        ]);
    }

    // Callback ini menangani perubahan status dari Gateway
    // public function callback(Request $request)
    // {
    //     // ... (Logika Webhook akan diatur terpisah nantinya, sementara biarkan seperti ini)

    //     return DB::transaction(function () use ($request) {
    //         $payment = Payment::where('external_id', $request->external_id)->lockForUpdate()->first();

    //         if (! $payment) {
    //             return response()->json(['message' => 'Payment not found'], 404);
    //         }

    //         $status = $request->status;
    //         $transaction = Transaction::lockForUpdate()->find($payment->transaction_id);

    //         if ($status === 'PAID') {
    //             if ($payment->status === 'PAID' || in_array($transaction->status, ['processing', 'completed'])) {
    //                 return response()->json(['message' => 'Already processed']);
    //             }

    //             $payment->update(['status' => $status]);

    //             $paymentMethod = $request->input('payment_method', 'Unknown');
    //             $paymentChannel = $request->input('payment_channel', '');
    //             $fullPaymentMethod = trim($paymentMethod.' '.$paymentChannel);

    //             $targetTransactionStatus = ($transaction->shipping_method === 'free') ? 'completed' : 'processing';

    //             $transaction->update([
    //                 'status' => $targetTransactionStatus,
    //                 'payment_method' => $fullPaymentMethod,
    //             ]);

    //             // if ($transaction->shipping_method === 'biteship') {
    //             //     DB::afterCommit(function () use ($transaction) {
    //             //         try {
    //             //             $biteship = new BiteshipService;
    //             //             $order = $biteship->createOrder($transaction);

    //             //             if (isset($order['id'])) {
    //             //                 $transaction->update([
    //             //                     'biteship_order_id' => $order['id'],
    //             //                     'tracking_number' => $order['courier']['waybill_id'] ?? 'Pending',
    //             //                     'shipping_status' => strtolower($order['status'] ?? 'pending'),
    //             //                 ]);
    //             //             }
    //             //         } catch (\Exception $e) {
    //             //             \Log::error('Biteship Exception: '.$e->getMessage());
    //             //         }
    //             //     });
    //             // } else {
    //             //     $transaction->update([
    //             //         'tracking_number' => 'In-Store Pickup',
    //             //         'shipping_status' => 'ready_for_pickup',
    //             //     ]);
    //             // }

    //             // [UBAH BAGIAN INI DI DALAM CALLBACK ANDA]
    //             if (in_array($transaction->shipping_method, ['biteship', 'dhl'])) {
    //                 DB::afterCommit(function () use ($transaction) {
    //                     try {
    //                         // Pastikan relasi termuat
    //                         $transaction->loadMissing(['address', 'user', 'details.product']);

    //                         // $destinationCountry = $transaction->address->region ?? 'Indonesia';

    //                         $destinationCountry = $transaction->address->region ?? ($transaction->address->details['region'] ?? 'Indonesia');
    //                         $shippingGateway = ShippingFactory::make($destinationCountry);

    //                         // Format Items
    //                         $items = [];
    //                         foreach ($transaction->details as $detail) {
    //                             $items[] = [
    //                                 'name' => $detail->product->name,
    //                                 'value' => (int) $detail->price,
    //                                 'quantity' => (int) $detail->quantity,
    //                                 'weight' => (int) ($detail->product->weight ?? 1000),
    //                             ];
    //                         }

    //                         // Format Payload Transaksi
    //                         $transactionData = [
    //                             'courier_company' => $transaction->courier_company,
    //                             'courier_type' => $transaction->courier_type,
    //                             'delivery_type' => $transaction->delivery_type,
    //                             'delivery_date' => $transaction->delivery_date,
    //                             'delivery_time' => $transaction->delivery_time,
    //                             'destination' => [
    //                                 'name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
    //                                 'phone' => $transaction->user->phone ?? '08123456789',
    //                                 'address' => $transaction->address->address_location,
    //                                 'postal_code' => $transaction->address->postal_code,
    //                                 'latitude' => $transaction->address->latitude,
    //                                 'longitude' => $transaction->address->longitude,
    //                             ],
    //                             'items' => $items,
    //                         ];

    //                         // Eksekusi pembuatan resi pengiriman
    //                         $order = $shippingGateway->createOrder($transactionData);

    //                         if (isset($order['id'])) {
    //                             $transaction->update([
    //                                 'biteship_order_id' => $order['id'], // Kolom ini bisa diganti namanya kelak jadi logistics_order_id
    //                                 'tracking_number' => $order['tracking_number'],
    //                                 'shipping_status' => $order['status'],
    //                             ]);
    //                         }
    //                     } catch (\Exception $e) {
    //                         \Log::error('Shipping Factory Exception: '.$e->getMessage());
    //                     }
    //                 });
    //             } else {
    //                 $transaction->update([
    //                     'tracking_number' => 'In-Store Pickup',
    //                     'shipping_status' => 'ready_for_pickup',
    //                 ]);
    //             }
    //         } elseif ($status === 'EXPIRED' || $status === 'FAILED') {
    //             if ($transaction->status !== 'cancelled') {
    //                 $payment->update(['status' => $status]);
    //                 $transaction->update([
    //                     'status' => 'cancelled',
    //                     'shipping_status' => 'cancelled',
    //                 ]);

    //                 if ($transaction->points_used > 0) {
    //                     $transaction->user->increment('point', $transaction->points_used);
    //                 }

    //                 $transactionController = app(TransactionController::class);
    //                 foreach ($transaction->details as $detail) {
    //                     $transactionController->restoreProductStock($detail->product_id, $detail->quantity);
    //                 }
    //             }
    //         } elseif ($status === 'PENDING' && $transaction->status === 'awaiting_payment') {
    //             $payment->update(['status' => $status]);
    //             $transaction->update(['status' => 'pending']);
    //         }

    //         return response()->json(['message' => 'Callback processed']);
    //     });
    // }

    // =====================================================================
    // 1. WEBHOOK XENDIT (UNTUK PEMBAYARAN LOKAL - IDR)
    // =====================================================================
    public function xenditCallback(Request $request)
    {
        // Xendit biasanya mengirimkan token verifikasi di header untuk keamanan
        // Anda bisa menambahkan logika validasi header X-CALLBACK-TOKEN di sini kelak.

        return DB::transaction(function () use ($request) {
            $payment = Payment::where('external_id', $request->external_id)->lockForUpdate()->first();

            if (! $payment) {
                return response()->json(['message' => 'Payment not found'], 404);
            }

            $status = $request->status;
            $transaction = Transaction::lockForUpdate()->find($payment->transaction_id);

            // Logika ketika sukses dibayar
            if ($status === 'PAID') {
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

                // Eksekusi API Logistik (Biteship/DHL)
                if (in_array($transaction->shipping_method, ['biteship', 'dhl'])) {
                    DB::afterCommit(function () use ($transaction) {
                        try {
                            $transaction->loadMissing(['address', 'user', 'details.product']);
                            $destinationCountry = $transaction->address->region ?? ($transaction->address->details['region'] ?? 'Indonesia');
                            $shippingGateway = ShippingFactory::make($destinationCountry);

                            $items = [];
                            foreach ($transaction->details as $detail) {
                                $items[] = [
                                    'name' => $detail->product->name,
                                    'value' => (int) $detail->price,
                                    'quantity' => (int) $detail->quantity,
                                    'weight' => (int) ($detail->product->weight ?? 1000),
                                ];
                            }

                            $transactionData = [
                                'courier_company' => $transaction->courier_company,
                                'courier_type' => $transaction->courier_type,
                                'delivery_type' => $transaction->delivery_type,
                                'delivery_date' => $transaction->delivery_date,
                                'delivery_time' => $transaction->delivery_time,
                                'destination' => [
                                    'name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
                                    'phone' => $transaction->user->phone ?? '08123456789',
                                    'address' => $transaction->address->address_location,
                                    'postal_code' => $transaction->address->postal_code,
                                    'latitude' => $transaction->address->latitude,
                                    'longitude' => $transaction->address->longitude,
                                    'country' => $destinationCountry // Ditambahkan untuk parsing DHL kelak
                                ],
                                'items' => $items,
                            ];

                            $order = $shippingGateway->createOrder($transactionData);

                            if (isset($order['id'])) {
                                $transaction->update([
                                    'biteship_order_id' => $order['id'],
                                    'tracking_number' => $order['tracking_number'],
                                    'shipping_status' => $order['status'],
                                ]);
                            }
                        } catch (\Exception $e) {
                            \Log::error('Shipping Factory Exception: '.$e->getMessage());
                        }
                    });
                } else {
                    $transaction->update([
                        'tracking_number' => 'In-Store Pickup',
                        'shipping_status' => 'ready_for_pickup',
                    ]);
                }
            }
            // Logika ketika gagal atau expired
            elseif ($status === 'EXPIRED' || $status === 'FAILED') {
                if ($transaction->status !== 'cancelled') {
                    $payment->update(['status' => $status]);
                    $transaction->update([
                        'status' => 'cancelled',
                        'shipping_status' => 'cancelled',
                    ]);

                    if ($transaction->points_used > 0) {
                        $transaction->user->increment('point', $transaction->points_used);
                    }

                    $transactionController = app(TransactionController::class);
                    foreach ($transaction->details as $detail) {
                        $transactionController->restoreProductStock($detail->product_id, $detail->quantity);
                    }
                }
            } elseif ($status === 'PENDING' && $transaction->status === 'awaiting_payment') {
                $payment->update(['status' => $status]);
                $transaction->update(['status' => 'pending']);
            }

            return response()->json(['message' => 'Xendit Callback processed']);
        });
    }

    // =====================================================================
    // 2. WEBHOOK STRIPE (UNTUK PEMBAYARAN INTERNASIONAL - USD/SGD/EUR)
    // =====================================================================
    public function stripeWebhook(Request $request)
    {
        // 1. Ambil payload murni (dibutuhkan untuk verifikasi signature Stripe)
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = config('services.stripe.webhook_secret'); // Tambahkan variabel ini di .env kelak

        try {
            // Kita coba verifikasi origin-nya benar dari Stripe
            // Jika Anda belum mensetup secret, lewati blok verifikasi ini dengan menonaktifkan kode ConstructEvent
            if ($endpointSecret) {
                $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
            } else {
                $event = json_decode($payload); // Fallback tanpa secret untuk testing lokal
            }
        } catch (\UnexpectedValueException $e) {
            \Log::error('Stripe Webhook Error: Invalid payload');
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            \Log::error('Stripe Webhook Error: Invalid signature');
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // 2. Tangani event sesuai tipenya
        if ($event->type == 'checkout.session.completed') {
            $session = $event->data->object;

            // Xendit menggunakan 'external_id', sedangkan di Stripe kita menyimpan referensi itu di 'client_reference_id'
            $externalId = $session->client_reference_id;

            return DB::transaction(function () use ($externalId, $session) {
                $payment = Payment::where('external_id', $externalId)->lockForUpdate()->first();

                if (! $payment) {
                    \Log::error("Stripe Webhook: Payment not found for reference {$externalId}");
                    return response()->json(['message' => 'Payment not found'], 404);
                }

                $transaction = Transaction::lockForUpdate()->find($payment->transaction_id);

                // Cek apakah sudah diproses agar tidak dobel
                if ($payment->status === 'PAID' || in_array($transaction->status, ['processing', 'completed'])) {
                    return response()->json(['message' => 'Already processed']);
                }

                // Update Status Pembayaran menjadi PAID
                $payment->update(['status' => 'PAID']);

                // Baca metode pembayaran yang dipakai di Stripe (misal: "card")
                $paymentMethodTypes = $session->payment_method_types;
                $paymentMethod = !empty($paymentMethodTypes) ? strtoupper($paymentMethodTypes[0]) : 'STRIPE';

                $targetTransactionStatus = ($transaction->shipping_method === 'free') ? 'completed' : 'processing';

                $transaction->update([
                    'status' => $targetTransactionStatus,
                    'payment_method' => 'STRIPE ' . $paymentMethod,
                ]);

                // Eksekusi API Logistik (Biteship/DHL) - Logika kembar dengan Xendit
                if (in_array($transaction->shipping_method, ['biteship', 'dhl'])) {
                    DB::afterCommit(function () use ($transaction) {
                        try {
                            $transaction->loadMissing(['address', 'user', 'details.product']);
                            $destinationCountry = $transaction->address->region ?? ($transaction->address->details['region'] ?? 'Indonesia');
                            $shippingGateway = ShippingFactory::make($destinationCountry);

                            $items = [];
                            foreach ($transaction->details as $detail) {
                                $items[] = [
                                    'name' => $detail->product->name,
                                    'value' => (int) $detail->price,
                                    'quantity' => (int) $detail->quantity,
                                    'weight' => (int) ($detail->product->weight ?? 1000),
                                ];
                            }

                            $transactionData = [
                                'courier_company' => $transaction->courier_company,
                                'courier_type' => $transaction->courier_type,
                                'delivery_type' => $transaction->delivery_type,
                                'delivery_date' => $transaction->delivery_date,
                                'delivery_time' => $transaction->delivery_time,
                                'destination' => [
                                    'name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
                                    'phone' => $transaction->user->phone ?? '08123456789',
                                    'address' => $transaction->address->address_location,
                                    'postal_code' => $transaction->address->postal_code,
                                    'latitude' => $transaction->address->latitude,
                                    'longitude' => $transaction->address->longitude,
                                    'country' => $destinationCountry
                                ],
                                'items' => $items,
                            ];

                            $order = $shippingGateway->createOrder($transactionData);

                            if (isset($order['id'])) {
                                $transaction->update([
                                    'biteship_order_id' => $order['id'],
                                    'tracking_number' => $order['tracking_number'],
                                    'shipping_status' => $order['status'],
                                ]);
                            }
                        } catch (\Exception $e) {
                            \Log::error('Stripe Shipping Callback Exception: '.$e->getMessage());
                        }
                    });
                } else {
                    $transaction->update([
                        'tracking_number' => 'In-Store Pickup',
                        'shipping_status' => 'ready_for_pickup',
                    ]);
                }

                return response()->json(['message' => 'Stripe Checkout Session Completed Handled']);
            });
        }

        // Logika ketika sesi Stripe expired / ditutup paksa
        elseif ($event->type == 'checkout.session.expired') {
            $session = $event->data->object;
            $externalId = $session->client_reference_id;

            DB::transaction(function () use ($externalId) {
                $payment = Payment::where('external_id', $externalId)->lockForUpdate()->first();
                if ($payment) {
                    $transaction = Transaction::lockForUpdate()->find($payment->transaction_id);
                    if ($transaction->status !== 'cancelled') {
                        $payment->update(['status' => 'EXPIRED']);
                        $transaction->update([
                            'status' => 'cancelled',
                            'shipping_status' => 'cancelled',
                        ]);

                        if ($transaction->points_used > 0) {
                            $transaction->user->increment('point', $transaction->points_used);
                        }

                        $transactionController = app(TransactionController::class);
                        foreach ($transaction->details as $detail) {
                            $transactionController->restoreProductStock($detail->product_id, $detail->quantity);
                        }
                    }
                }
            });
        }

        // Return status 200 agar Stripe berhenti mem-ping server
        return response()->json(['status' => 'success']);
    }

    // =====================================================================
    // 3. WEBHOOK PAYPAL (UNTUK PEMBAYARAN INTERNASIONAL)
    // =====================================================================
    // public function paypalWebhook(Request $request)
    // {
    //     $payload = $request->all();
    //     $eventType = $payload['event_type'] ?? null;

    //     // Kita hanya peduli pada event ketika uang benar-benar sudah ditarik
    //     if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
    //         // Ambil Order ID bawaan PayPal dari kedalaman data JSON mereka
    //         $paypalOrderId = $payload['resource']['supplementary_data']['related_ids']['order_id'] ?? null;

    //         if (!$paypalOrderId) {
    //             \Log::error("PayPal Webhook: Order ID tidak ditemukan di payload.");
    //             return response()->json(['error' => 'Order ID missing'], 400);
    //         }

    //         return DB::transaction(function () use ($paypalOrderId) {
    //             // Trik Cerdas: Karena kita menyimpan Order ID PayPal di dalam tautan checkout_url,
    //             // kita bisa mencari pesanan yang sesuai menggunakan kata kunci (LIKE)
    //             $payment = Payment::where('checkout_url', 'LIKE', '%' . $paypalOrderId . '%')->lockForUpdate()->first();

    //             if (!$payment) {
    //                 \Log::error("PayPal Webhook: Payment tidak ditemukan untuk Order ID {$paypalOrderId}");
    //                 return response()->json(['message' => 'Payment not found'], 404);
    //             }

    //             $transaction = Transaction::lockForUpdate()->find($payment->transaction_id);

    //             // Cek apakah status sudah lunas untuk mencegah pemrosesan ganda
    //             if ($payment->status === 'PAID' || in_array($transaction->status, ['processing', 'completed'])) {
    //                 return response()->json(['message' => 'Already processed']);
    //             }

    //             // 1. Ubah status menjadi LUNAS
    //             $payment->update(['status' => 'PAID']);

    //             $targetTransactionStatus = ($transaction->shipping_method === 'free') ? 'completed' : 'processing';

    //             $transaction->update([
    //                 'status' => $targetTransactionStatus,
    //                 'payment_method' => 'PAYPAL',
    //             ]);

    //             // 2. Eksekusi API Logistik (Logika ini sama persis dengan Xendit/Stripe)
    //             if (in_array($transaction->shipping_method, ['biteship', 'dhl'])) {
    //                 DB::afterCommit(function () use ($transaction) {
    //                     try {
    //                         $transaction->loadMissing(['address', 'user', 'details.product']);
    //                         $destinationCountry = $transaction->address->region ?? ($transaction->address->details['region'] ?? 'Indonesia');
    //                         $shippingGateway = ShippingFactory::make($destinationCountry);

    //                         $items = [];
    //                         foreach ($transaction->details as $detail) {
    //                             $items[] = [
    //                                 'name' => $detail->product->name,
    //                                 'value' => (int) $detail->price,
    //                                 'quantity' => (int) $detail->quantity,
    //                                 'weight' => (int) ($detail->product->weight ?? 1000),
    //                             ];
    //                         }

    //                         $transactionData = [
    //                             'courier_company' => $transaction->courier_company,
    //                             'courier_type' => $transaction->courier_type,
    //                             'delivery_type' => $transaction->delivery_type,
    //                             'delivery_date' => $transaction->delivery_date,
    //                             'delivery_time' => $transaction->delivery_time,
    //                             'destination' => [
    //                                 'name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
    //                                 'phone' => $transaction->user->phone ?? '08123456789',
    //                                 'address' => $transaction->address->address_location,
    //                                 'postal_code' => $transaction->address->postal_code,
    //                                 'latitude' => $transaction->address->latitude,
    //                                 'longitude' => $transaction->address->longitude,
    //                                 'country' => $destinationCountry
    //                             ],
    //                             'items' => $items,
    //                         ];

    //                         $order = $shippingGateway->createOrder($transactionData);

    //                         if (isset($order['id'])) {
    //                             $transaction->update([
    //                                 'biteship_order_id' => $order['id'],
    //                                 'tracking_number' => $order['tracking_number'],
    //                                 'shipping_status' => $order['status'],
    //                             ]);
    //                         }
    //                     } catch (\Exception $e) {
    //                         \Log::error('PayPal Shipping Callback Exception: '.$e->getMessage());
    //                     }
    //                 });
    //             } else {
    //                 $transaction->update([
    //                     'tracking_number' => 'In-Store Pickup',
    //                     'shipping_status' => 'ready_for_pickup',
    //                 ]);
    //             }

    //             return response()->json(['message' => 'PayPal Webhook Processed Successfully']);
    //         });
    //     }

    //     // Return 200 OK untuk event lain agar server PayPal tenang dan tidak terus mencoba mengirim ulang
    //     return response()->json(['status' => 'success']);
    // }

    // =====================================================================
    // 3. WEBHOOK PAYPAL (UNTUK PEMBAYARAN INTERNASIONAL)
    // =====================================================================
    public function paypalWebhook(Request $request)
    {
        $payload = $request->all();
        $eventType = $payload['event_type'] ?? null;

        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
            
            // 🔥 Ambil external_id asli kita yang disimpan PayPal di dalam custom_id
            $externalId = $payload['resource']['custom_id'] ?? null;

            if (!$externalId) {
                \Log::error("PayPal Webhook: Custom ID (External ID) tidak ditemukan di payload.");
                return response()->json(['error' => 'External ID missing'], 400);
            }

            return DB::transaction(function () use ($externalId) {
                
                // 🔥 Pencarian sekarang 100% akurat dan instan, sama seperti Xendit & Stripe!
                $payment = Payment::where('external_id', $externalId)->lockForUpdate()->first();

                if (!$payment) {
                    \Log::error("PayPal Webhook: Payment tidak ditemukan untuk External ID {$externalId}");
                    return response()->json(['message' => 'Payment not found'], 404);
                }

                $transaction = Transaction::lockForUpdate()->find($payment->transaction_id);

                if ($payment->status === 'PAID' || in_array($transaction->status, ['processing', 'completed'])) {
                    return response()->json(['message' => 'Already processed']);
                }

                $payment->update(['status' => 'PAID']);

                $targetTransactionStatus = ($transaction->shipping_method === 'free') ? 'completed' : 'processing';

                $transaction->update([
                    'status' => $targetTransactionStatus,
                    'payment_method' => 'PAYPAL',
                ]);

                if (in_array($transaction->shipping_method, ['biteship', 'dhl'])) {
                    DB::afterCommit(function () use ($transaction) {
                        try {
                            $transaction->loadMissing(['address', 'user', 'details.product']);
                            $destinationCountry = $transaction->address->region ?? ($transaction->address->details['region'] ?? 'Indonesia');
                            $shippingGateway = ShippingFactory::make($destinationCountry);

                            $items = [];
                            foreach ($transaction->details as $detail) {
                                $items[] = [
                                    'name' => $detail->product->name,
                                    'value' => (int) $detail->price,
                                    'quantity' => (int) $detail->quantity,
                                    'weight' => (int) ($detail->product->weight ?? 1000),
                                ];
                            }

                            $transactionData = [
                                'courier_company' => $transaction->courier_company,
                                'courier_type' => $transaction->courier_type,
                                'delivery_type' => $transaction->delivery_type,
                                'delivery_date' => $transaction->delivery_date,
                                'delivery_time' => $transaction->delivery_time,
                                'destination' => [
                                    'name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
                                    'phone' => $transaction->user->phone ?? '08123456789',
                                    'address' => $transaction->address->address_location,
                                    'postal_code' => $transaction->address->postal_code,
                                    'latitude' => $transaction->address->latitude,
                                    'longitude' => $transaction->address->longitude,
                                    'country' => $destinationCountry
                                ],
                                'items' => $items,
                            ];

                            $order = $shippingGateway->createOrder($transactionData);

                            if (isset($order['id'])) {
                                $transaction->update([
                                    'biteship_order_id' => $order['id'],
                                    'tracking_number' => $order['tracking_number'],
                                    'shipping_status' => $order['status'],
                                ]);
                            }
                        } catch (\Exception $e) {
                            \Log::error('PayPal Shipping Callback Exception: '.$e->getMessage());
                        }
                    });
                } else {
                    $transaction->update([
                        'tracking_number' => 'In-Store Pickup',
                        'shipping_status' => 'ready_for_pickup',
                    ]);
                }

                return response()->json(['message' => 'PayPal Webhook Processed Successfully']);
            });
        }

        return response()->json(['status' => 'success']);
    }

    public function capturePayPal(Request $request)
    {
        // PayPal otomatis menyisipkan Order ID mereka ke dalam parameter URL bernama 'token'
        $paypalToken = $request->query('token'); 
        $externalId = $request->query('external_id');
        $orderId = $request->query('order_id');

        // Lakukan penarikan dana (Capture)
        $paypalService = app(\App\Services\PayPalService::class);
        $paypalService->capturePayment($paypalToken);

        // Setelah ditarik, lemparkan pembeli ke halaman sukses Vue.js Anda seperti biasa
        $frontendSuccessUrl = config('app.frontend_url') 
            . '/payment-success?external_id=' . $externalId 
            . '&order_id=' . $orderId;
            
        return redirect($frontendSuccessUrl);
    }

    // public function getShippingRates(Request $request)
    // {
    //     $user = $request->user();
    //     if (! $user) {
    //         return response()->json([
    //             'message' => 'Unauthorized. Please login again.',
    //         ], 401);
    //     }

    //     $request->validate([
    //         'address_id' => 'required|exists:addresses,id',
    //         'cart_ids' => 'required|array',
    //         'cart_ids.*' => 'exists:carts,id',
    //     ]);

    //     $address = Address::find($request->address_id);

    //     if (! $address || ! $address->postal_code) {
    //         return response()->json([
    //             'message' => 'Alamat tidak valid atau kodepos tidak ditemukan.',
    //         ], 400);
    //     }

    //     try {
    //         $biteship = new BiteshipService;

    //         $cartItems = Cart::with('product')->whereIn('id', $request->cart_ids)->where('user_id', $user->id)->get();

    //         $totalWeight = 0;
    //         foreach ($cartItems as $item) {
    //             $itemWeight = $item->product->weight ?? 1000;
    //             $totalWeight += ($itemWeight * $item->quantity);
    //         }

    //         if ($totalWeight <= 0) {
    //             $totalWeight = 1000;
    //         }

    //         $rates = $biteship->getRates($address, $totalWeight);

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
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Please login again.'], 401);
        }

        $request->validate([
            'address_id' => 'required|exists:addresses,id',
            'cart_ids' => 'required|array',
            'cart_ids.*' => 'exists:carts,id',
        ]);

        $address = Address::find($request->address_id);

        if (! $address || ! $address->postal_code) {
            return response()->json(['message' => 'Alamat tidak valid atau kodepos tidak ditemukan.'], 400);
        }

        try {
            $cartItems = Cart::with('product')->whereIn('id', $request->cart_ids)->where('user_id', $user->id)->get();

            // 1. Format Data Origin (Gudang/Toko)
            $origin = [
                'postal_code' => config('services.biteship.origin_postal_code', '60272'),
                'latitude' => -7.25653,
                'longitude' => 112.74877,
            ];

            // 2. Format Data Destination (Pelanggan)
            // $destinationCountry = $address->region ?? 'Indonesia'; // Fallback ke Indonesia jika kosong

            $destinationCountry = $address->region ?? ($address->details['region'] ?? 'Indonesia');
            $destination = [
                'name' => trim($address->first_name_address . ' ' . $address->last_name_address),
                'phone' => $user->phone ?? '08123456789',
                'address' => $address->address_location,
                'postal_code' => $address->postal_code,
                'latitude' => $address->latitude,
                'longitude' => $address->longitude,
            ];

            // 3. Format Data Items
            $items = [];
            foreach ($cartItems as $item) {
                $items[] = [
                    'name' => $item->product->name,
                    'value' => $item->product->discount_price ?? $item->product->price,
                    'quantity' => $item->quantity,
                    'weight' => $item->product->weight ?? 1000,
                ];
            }

            // =========================================================================
            // [LOGIKA BARU] Panggil Shipping Factory berdasarkan Negara Tujuan!
            // =========================================================================
            $shippingGateway = ShippingFactory::make($destinationCountry);
            $rates = $shippingGateway->calculateRates($origin, $destination, $items);

            return response()->json($rates);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Gagal mengambil ongkos kirim: '.$e->getMessage(),
            ], 500);
        }
    }

    private function checkAndAssignMembership($user)
    {
        if ($user->is_membership) {
            return;
        }

        $totalSpent = Transaction::where('user_id', $user->id)
            ->where('status', 'completed')
            ->sum('total_amount');

        if ($totalSpent >= 100000) {
            $user->update(['is_membership' => true]);
        }
    }
}
