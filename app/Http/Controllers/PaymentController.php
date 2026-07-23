<?php

// namespace App\Http\Controllers;

// use App\Models\Address;
// use App\Models\Cart;
// use App\Models\Payment;
// use App\Models\Transaction;
// // use App\Services\BiteshipService;
// use App\Services\ShippingFactory; // [BARU] Import Shipping Factory
// use App\Services\PaymentFactory; // TAMBAHKAN IMPORT INI
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;

// class PaymentController extends Controller
// {
//     // HAPUS function __construct() karena inisialisasi Xendit tidak lagi dilakukan di sini

//     public function createInvoice(Request $request)
//     {
//         $request->validate([
//             'transaction_id' => 'required|exists:transactions,id',
//             'address_id' => 'required',
//             'shipping_method' => 'required|in:free,biteship',
//             'courier_company' => 'nullable|string',
//             'courier_type' => 'nullable|string',
//             'shipping_cost' => 'nullable|numeric',
//             'delivery_type' => 'nullable|string|in:now,later,scheduled',
//             'delivery_date' => 'nullable|date',
//             'delivery_time' => 'nullable|date_format:H:i',
//             'use_points' => 'nullable|integer|min:0',
//             'currency' => 'required|string|in:IDR,USD,SGD,EUR', // [BARU] Wajibkan Vue mengirim ini
//         ]);

//         $transaction = Transaction::with(['user', 'details.product', 'payment'])
//             ->where('user_id', $request->user()->id)
//             ->findOrFail($request->transaction_id);

//         if ($transaction->payment && $transaction->payment->status === 'pending' && ! empty($transaction->payment->checkout_url)) {
//             return response()->json([
//                 'checkout_url' => $transaction->payment->checkout_url,
//             ]);
//         }

//         $totalQuantity = $transaction->details->sum('quantity') ?: 1;

//         if (! $transaction->shipping_cost || $transaction->shipping_cost == 0) {
//             $baseShippingRate = $request->shipping_method === 'free' ? 0 : $request->shipping_cost;
//             $totalShippingCost = $baseShippingRate * $totalQuantity;

//             $courierCompany = $request->shipping_method === 'free' ? 'Internal' : $request->courier_company;
//             $courierType = $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type;

//             $transaction->update([
//                 'address_id' => $request->address_id,
//                 'shipping_method' => $request->shipping_method,
//                 'courier_company' => $courierCompany,
//                 'courier_type' => $courierType,
//                 'shipping_cost' => $totalShippingCost,
//                 'total_amount' => $transaction->total_amount,
//                 'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
//                 'delivery_date' => $request->delivery_date,
//                 'delivery_time' => $request->delivery_time,
//                 'status' => 'pending',
//                 'currency_code' => $request->currency, // [BARU] Simpan USD/IDR ke database
//             ]);
//         } else {
//             // Jika ongkir sudah ada, pastikan currency tetap diupdate
//             $transaction->update([
//                 'currency_code' => $request->currency,
//             ]);
//         }

//         $user = $request->user();

//         $pointsUsed = $transaction->points_used ?? 0;
//         $conversionRate = 1000;
//         $pointDiscountAmount = $pointsUsed * $conversionRate;

//         $promoDiscount = $transaction->promo_discount ?? 0;
//         $subtotalAfterPromo = max(0, $transaction->total_amount - $promoDiscount);
//         $pointDiscountAmount = min($pointDiscountAmount, $subtotalAfterPromo);

//         $externalId = 'PAY-'.$transaction->order_id.($transaction->payment ? '-'.time() : '');

//         $items = [];
//         foreach ($transaction->details as $detail) {
//             $productName = $detail->product->name;
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

//         if ($promoDiscount > 0) {
//             $items[] = [
//                 'name' => 'Promo Code: '.($transaction->promo_code ?? 'DISCOUNT'),
//                 'quantity' => 1,
//                 'price' => -(int) $promoDiscount,
//                 'category' => 'DISCOUNT',
//             ];
//         }

//         if ($pointDiscountAmount > 0) {
//             $items[] = [
//                 'name' => 'Loyalty Point Discount ('.$pointsUsed.' Pts)',
//                 'quantity' => 1,
//                 'price' => -(int) $pointDiscountAmount,
//                 'category' => 'DISCOUNT',
//             ];
//         }

//         $basePriceXendit = 0;
//         if ($transaction->shipping_cost > 0) {
//             $basePriceXendit = $transaction->shipping_cost / $totalQuantity;
//             $items[] = [
//                 'name' => 'Shipping Cost ('.$transaction->courier_company.')',
//                 'quantity' => (int) $totalQuantity,
//                 'price' => (int) $basePriceXendit,
//                 'category' => 'SHIPPING_FEE',
//             ];
//         }

//         $finalAmount = (int) $transaction->total_amount
//                      + ($basePriceXendit * $totalQuantity)
//                      - $pointDiscountAmount
//                      - $promoDiscount;

//         // [LOGIKA BARU]: Deteksi mata uang dan panggil Factory
//         $currency = $transaction->currency_code ?? 'IDR';

//         $paymentGateway = PaymentFactory::make($currency);

//         // [LOGIKA BARU]: Deteksi mata uang dan panggil Factory
//         $currency = $transaction->currency_code ?? 'IDR';
//         $paymentGateway = PaymentFactory::make($currency);

//         // 1. Tentukan URL sukses standar (Untuk Xendit -> Langsung ke Vue.js)
//         $frontendSuccessUrl = config('app.frontend_url')
//             . '/payment-success?external_id=' . $externalId
//             . '&order_id=' . $transaction->order_id;

//         // 2. Tentukan URL sukses khusus PayPal (Untuk PayPal -> Masuk Jembatan Capture dulu)
//         $paypalCaptureUrl = url('/api/payments/paypal-capture?external_id=' . $externalId . '&order_id=' . $transaction->order_id);

//         // 3. Logika Kondisional Penentu Arah
//         $dynamicSuccessUrl = ($currency === 'IDR') ? $frontendSuccessUrl : $paypalCaptureUrl;

//         $checkoutUrl = $paymentGateway->createInvoice([
//             'order_id' => $transaction->order_id,
//             'external_id' => $externalId,
//             'payer_email' => $transaction->user->email,
//             'amount' => $finalAmount,
//             'currency' => $currency,
//             'items' => $items,

//             // 🔥 Gunakan variabel dinamis di sini
//             'success_redirect_url' => $dynamicSuccessUrl,

//             'failure_redirect_url' => config('app.frontend_url').'/payment-failed',
//         ]);

//         // $checkoutUrl = $paymentGateway->createInvoice([
//         //     'order_id' => $transaction->order_id,
//         //     'external_id' => $externalId,
//         //     'payer_email' => $transaction->user->email,
//         //     'amount' => $finalAmount,
//         //     'currency' => $currency, // Perlu diteruskan ke gateway
//         //     'items' => $items,
//         //     // 'success_redirect_url' => config('app.frontend_url')
//         //     //     .'/payment-success?external_id='.$externalId
//         //     //     .'&order_id='.$transaction->order_id,
//         //     'success_redirect_url' => url('/api/payments/paypal-capture?external_id='.$externalId.'&order_id='.$transaction->order_id),
//         //     'failure_redirect_url' => config('app.frontend_url').'/payment-failed',
//         // ]);

//         Payment::updateOrCreate(
//             ['transaction_id' => $transaction->id],
//             [
//                 'external_id' => $externalId,
//                 'checkout_url' => $checkoutUrl,
//                 'amount' => $transaction->total_amount,
//                 'status' => 'pending',
//             ]
//         );

//         return response()->json([
//             'checkout_url' => $checkoutUrl,
//             'gateway' => $currency === 'IDR' ? 'Xendit' : 'Stripe', // Tambahan info untuk frontend
//         ]);
//     }

//     // Callback ini menangani perubahan status dari Gateway
//     // public function callback(Request $request)
//     // {
//     //     // ... (Logika Webhook akan diatur terpisah nantinya, sementara biarkan seperti ini)

//     //     return DB::transaction(function () use ($request) {
//     //         $payment = Payment::where('external_id', $request->external_id)->lockForUpdate()->first();

//     //         if (! $payment) {
//     //             return response()->json(['message' => 'Payment not found'], 404);
//     //         }

//     //         $status = $request->status;
//     //         $transaction = Transaction::lockForUpdate()->find($payment->transaction_id);

//     //         if ($status === 'PAID') {
//     //             if ($payment->status === 'PAID' || in_array($transaction->status, ['processing', 'completed'])) {
//     //                 return response()->json(['message' => 'Already processed']);
//     //             }

//     //             $payment->update(['status' => $status]);

//     //             $paymentMethod = $request->input('payment_method', 'Unknown');
//     //             $paymentChannel = $request->input('payment_channel', '');
//     //             $fullPaymentMethod = trim($paymentMethod.' '.$paymentChannel);

//     //             $targetTransactionStatus = ($transaction->shipping_method === 'free') ? 'completed' : 'processing';

//     //             $transaction->update([
//     //                 'status' => $targetTransactionStatus,
//     //                 'payment_method' => $fullPaymentMethod,
//     //             ]);

//     //             // if ($transaction->shipping_method === 'biteship') {
//     //             //     DB::afterCommit(function () use ($transaction) {
//     //             //         try {
//     //             //             $biteship = new BiteshipService;
//     //             //             $order = $biteship->createOrder($transaction);

//     //             //             if (isset($order['id'])) {
//     //             //                 $transaction->update([
//     //             //                     'biteship_order_id' => $order['id'],
//     //             //                     'tracking_number' => $order['courier']['waybill_id'] ?? 'Pending',
//     //             //                     'shipping_status' => strtolower($order['status'] ?? 'pending'),
//     //             //                 ]);
//     //             //             }
//     //             //         } catch (\Exception $e) {
//     //             //             \Log::error('Biteship Exception: '.$e->getMessage());
//     //             //         }
//     //             //     });
//     //             // } else {
//     //             //     $transaction->update([
//     //             //         'tracking_number' => 'In-Store Pickup',
//     //             //         'shipping_status' => 'ready_for_pickup',
//     //             //     ]);
//     //             // }

//     //             // [UBAH BAGIAN INI DI DALAM CALLBACK ANDA]
//     //             if (in_array($transaction->shipping_method, ['biteship', 'dhl'])) {
//     //                 DB::afterCommit(function () use ($transaction) {
//     //                     try {
//     //                         // Pastikan relasi termuat
//     //                         $transaction->loadMissing(['address', 'user', 'details.product']);

//     //                         // $destinationCountry = $transaction->address->region ?? 'Indonesia';

//     //                         $destinationCountry = $transaction->address->region ?? ($transaction->address->details['region'] ?? 'Indonesia');
//     //                         $shippingGateway = ShippingFactory::make($destinationCountry);

//     //                         // Format Items
//     //                         $items = [];
//     //                         foreach ($transaction->details as $detail) {
//     //                             $items[] = [
//     //                                 'name' => $detail->product->name,
//     //                                 'value' => (int) $detail->price,
//     //                                 'quantity' => (int) $detail->quantity,
//     //                                 'weight' => (int) ($detail->product->weight ?? 1000),
//     //                             ];
//     //                         }

//     //                         // Format Payload Transaksi
//     //                         $transactionData = [
//     //                             'courier_company' => $transaction->courier_company,
//     //                             'courier_type' => $transaction->courier_type,
//     //                             'delivery_type' => $transaction->delivery_type,
//     //                             'delivery_date' => $transaction->delivery_date,
//     //                             'delivery_time' => $transaction->delivery_time,
//     //                             'destination' => [
//     //                                 'name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
//     //                                 'phone' => $transaction->user->phone ?? '08123456789',
//     //                                 'address' => $transaction->address->address_location,
//     //                                 'postal_code' => $transaction->address->postal_code,
//     //                                 'latitude' => $transaction->address->latitude,
//     //                                 'longitude' => $transaction->address->longitude,
//     //                             ],
//     //                             'items' => $items,
//     //                         ];

//     //                         // Eksekusi pembuatan resi pengiriman
//     //                         $order = $shippingGateway->createOrder($transactionData);

//     //                         if (isset($order['id'])) {
//     //                             $transaction->update([
//     //                                 'biteship_order_id' => $order['id'], // Kolom ini bisa diganti namanya kelak jadi logistics_order_id
//     //                                 'tracking_number' => $order['tracking_number'],
//     //                                 'shipping_status' => $order['status'],
//     //                             ]);
//     //                         }
//     //                     } catch (\Exception $e) {
//     //                         \Log::error('Shipping Factory Exception: '.$e->getMessage());
//     //                     }
//     //                 });
//     //             } else {
//     //                 $transaction->update([
//     //                     'tracking_number' => 'In-Store Pickup',
//     //                     'shipping_status' => 'ready_for_pickup',
//     //                 ]);
//     //             }
//     //         } elseif ($status === 'EXPIRED' || $status === 'FAILED') {
//     //             if ($transaction->status !== 'cancelled') {
//     //                 $payment->update(['status' => $status]);
//     //                 $transaction->update([
//     //                     'status' => 'cancelled',
//     //                     'shipping_status' => 'cancelled',
//     //                 ]);

//     //                 if ($transaction->points_used > 0) {
//     //                     $transaction->user->increment('point', $transaction->points_used);
//     //                 }

//     //                 $transactionController = app(TransactionController::class);
//     //                 foreach ($transaction->details as $detail) {
//     //                     $transactionController->restoreProductStock($detail->product_id, $detail->quantity);
//     //                 }
//     //             }
//     //         } elseif ($status === 'PENDING' && $transaction->status === 'awaiting_payment') {
//     //             $payment->update(['status' => $status]);
//     //             $transaction->update(['status' => 'pending']);
//     //         }

//     //         return response()->json(['message' => 'Callback processed']);
//     //     });
//     // }

//     // =====================================================================
//     // 1. WEBHOOK XENDIT (UNTUK PEMBAYARAN LOKAL - IDR)
//     // =====================================================================
//     public function xenditCallback(Request $request)
//     {
//         // Xendit biasanya mengirimkan token verifikasi di header untuk keamanan
//         // Anda bisa menambahkan logika validasi header X-CALLBACK-TOKEN di sini kelak.

//         return DB::transaction(function () use ($request) {
//             $payment = Payment::where('external_id', $request->external_id)->lockForUpdate()->first();

//             if (! $payment) {
//                 return response()->json(['message' => 'Payment not found'], 404);
//             }

//             $status = $request->status;
//             $transaction = Transaction::lockForUpdate()->find($payment->transaction_id);

//             // Logika ketika sukses dibayar
//             if ($status === 'PAID') {
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

//                 // 👇 TEMPEL KODE AFILIATOR DI SINI 👇
//                 // if ($transaction->affiliate_id) {
//                 //     $affiliate = \App\Models\User::find($transaction->affiliate_id);
//                 //     if ($affiliate) {
//                 //         $commissionEarned = 0;
//                 //         if ($affiliate->commission_type === 'percentage') {
//                 //             $commissionEarned = $transaction->total_amount * ($affiliate->commission_rate / 100);
//                 //         } else {
//                 //             $totalItems = $transaction->details->sum('quantity');
//                 //            // $commissionEarned = $totalItems * $affiliate->commission_rate;
//                 //         }
//                 //         $transaction->update(['commission_earned' => $commissionEarned]);
//                 //         $affiliate->increment('commission_balance', $commissionEarned);
//                 //     }
//                 // }

//                 // if ($transaction->affiliate_id) {
//                 //     $affiliate = \App\Models\User::find($transaction->affiliate_id);
//                 //     if ($affiliate) {
//                 //         $commissionEarned = 0;
//                 //         if ($affiliate->commission_type === 'percentage') {
//                 //             $commissionEarned = $transaction->total_amount * ($affiliate->commission_rate / 100);
//                 //         } else {
//                 //             $totalItems = $transaction->details->sum('quantity');
//                 //             // $commissionEarned = $totalItems * $affiliate->commission_rate;
//                 //         }

//                 //         // [PERUBAHAN]: Hanya catat nominal dan set status menjadi 'pending'
//                 //         // Uang BELUM masuk ke commission_balance milik afiliator
//                 //         $transaction->update([
//                 //             'commission_earned' => $commissionEarned,
//                 //             'commission_status' => 'pending'
//                 //         ]);
//                 //     }
//                 // }
//                 // 👆 BATAS KODE AFILIATOR 👆

//                 // =====================================================================
//                 // 👇 [PERBAIKAN] PENCAIRAN INSTAN KHUSUS IN-STORE PICKUP 👇
//                 // =====================================================================
//                 if ($targetTransactionStatus === 'completed' && $transaction->affiliate_id && $transaction->commission_status === 'pending') {
//                     $transaction->update(['commission_status' => 'settled']);

//                     $affiliateUser = \App\Models\User::find($transaction->affiliate_id);
//                     if ($affiliateUser) {
//                         $affiliateUser->increment('commission_balance', $transaction->commission_earned);
//                     }
//                 }

//                 // Eksekusi API Logistik (Biteship/DHL)
//                 if (in_array($transaction->shipping_method, ['biteship', 'dhl'])) {
//                     DB::afterCommit(function () use ($transaction) {
//                         try {
//                             $transaction->loadMissing(['address', 'user', 'details.product']);
//                             $destinationCountry = $transaction->address->region ?? ($transaction->address->details['region'] ?? 'Indonesia');
//                             $shippingGateway = ShippingFactory::make($destinationCountry);

//                             $items = [];
//                             foreach ($transaction->details as $detail) {
//                                 $items[] = [
//                                     'name' => $detail->product->name,
//                                     'value' => (int) $detail->price,
//                                     'quantity' => (int) $detail->quantity,
//                                     'weight' => (int) ($detail->product->weight ?? 1000),
//                                 ];
//                             }

//                             $transactionData = [
//                                 'courier_company' => $transaction->courier_company,
//                                 'courier_type' => $transaction->courier_type,
//                                 'delivery_type' => $transaction->delivery_type,
//                                 'delivery_date' => $transaction->delivery_date,
//                                 'delivery_time' => $transaction->delivery_time,
//                                 'destination' => [
//                                     'name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
//                                     'phone' => $transaction->user->phone ?? '08123456789',
//                                     'address' => $transaction->address->address_location,
//                                     'postal_code' => $transaction->address->postal_code,
//                                     'latitude' => $transaction->address->latitude,
//                                     'longitude' => $transaction->address->longitude,
//                                     'country' => $destinationCountry // Ditambahkan untuk parsing DHL kelak
//                                 ],
//                                 'items' => $items,
//                             ];

//                             $order = $shippingGateway->createOrder($transactionData);

//                             if (isset($order['id'])) {
//                                 $transaction->update([
//                                     'biteship_order_id' => $order['id'],
//                                     'tracking_number' => $order['tracking_number'],
//                                     'shipping_status' => $order['status'],
//                                 ]);
//                             }
//                         } catch (\Exception $e) {
//                             report($e);
//                             \Log::error('Shipping Factory Exception: '.$e->getMessage());
//                         }
//                     });
//                 } else {
//                     $transaction->update([
//                         'tracking_number' => 'In-Store Pickup',
//                         'shipping_status' => 'ready_for_pickup',
//                     ]);
//                 }
//             }
//             // Logika ketika gagal atau expired
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

//                     $transactionController = app(TransactionController::class);
//                     foreach ($transaction->details as $detail) {
//                         $transactionController->restoreProductStock($detail->product_id, $detail->quantity);
//                     }
//                 }
//             } elseif ($status === 'PENDING' && $transaction->status === 'awaiting_payment') {
//                 $payment->update(['status' => $status]);
//                 $transaction->update(['status' => 'pending']);
//             }

//             return response()->json(['message' => 'Xendit Callback processed']);
//         });
//     }

//     // =====================================================================
//     // 2. WEBHOOK STRIPE (UNTUK PEMBAYARAN INTERNASIONAL - USD/SGD/EUR)
//     // =====================================================================
//     public function stripeWebhook(Request $request)
//     {
//         // 1. Ambil payload murni (dibutuhkan untuk verifikasi signature Stripe)
//         $payload = $request->getContent();
//         $sigHeader = $request->header('Stripe-Signature');
//         $endpointSecret = config('services.stripe.webhook_secret'); // Tambahkan variabel ini di .env kelak

//         try {
//             // Kita coba verifikasi origin-nya benar dari Stripe
//             // Jika Anda belum mensetup secret, lewati blok verifikasi ini dengan menonaktifkan kode ConstructEvent
//             if ($endpointSecret) {
//                 $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
//             } else {
//                 $event = json_decode($payload); // Fallback tanpa secret untuk testing lokal
//             }
//         } catch (\UnexpectedValueException $e) {
//             report($e);
//             \Log::error('Stripe Webhook Error: Invalid payload');
//             return response()->json(['error' => 'Invalid payload'], 400);
//         } catch (\Stripe\Exception\SignatureVerificationException $e) {
//             report($e);
//             \Log::error('Stripe Webhook Error: Invalid signature');
//             return response()->json(['error' => 'Invalid signature'], 400);
//         }

//         // 2. Tangani event sesuai tipenya
//         if ($event->type == 'checkout.session.completed') {
//             $session = $event->data->object;

//             // Xendit menggunakan 'external_id', sedangkan di Stripe kita menyimpan referensi itu di 'client_reference_id'
//             $externalId = $session->client_reference_id;

//             return DB::transaction(function () use ($externalId, $session) {
//                 $payment = Payment::where('external_id', $externalId)->lockForUpdate()->first();

//                 if (! $payment) {
//                     \Log::error("Stripe Webhook: Payment not found for reference {$externalId}");
//                     return response()->json(['message' => 'Payment not found'], 404);
//                 }

//                 $transaction = Transaction::lockForUpdate()->find($payment->transaction_id);

//                 // Cek apakah sudah diproses agar tidak dobel
//                 if ($payment->status === 'PAID' || in_array($transaction->status, ['processing', 'completed'])) {
//                     return response()->json(['message' => 'Already processed']);
//                 }

//                 // Update Status Pembayaran menjadi PAID
//                 $payment->update(['status' => 'PAID']);

//                 // Baca metode pembayaran yang dipakai di Stripe (misal: "card")
//                 $paymentMethodTypes = $session->payment_method_types;
//                 $paymentMethod = !empty($paymentMethodTypes) ? strtoupper($paymentMethodTypes[0]) : 'STRIPE';

//                 $targetTransactionStatus = ($transaction->shipping_method === 'free') ? 'completed' : 'processing';

//                 $transaction->update([
//                     'status' => $targetTransactionStatus,
//                     'payment_method' => 'STRIPE ' . $paymentMethod,
//                 ]);

//                 // 👇 TEMPEL KODE AFILIATOR DI SINI 👇
//                 // if ($transaction->affiliate_id) {
//                 //     $affiliate = \App\Models\User::find($transaction->affiliate_id);
//                 //     if ($affiliate) {
//                 //         $commissionEarned = 0;
//                 //         if ($affiliate->commission_type === 'percentage') {
//                 //             $commissionEarned = $transaction->total_amount * ($affiliate->commission_rate / 100);
//                 //         } else {
//                 //             $totalItems = $transaction->details->sum('quantity');
//                 //            // $commissionEarned = $totalItems * $affiliate->commission_rate;
//                 //         }
//                 //         $transaction->update(['commission_earned' => $commissionEarned]);
//                 //         $affiliate->increment('commission_balance', $commissionEarned);
//                 //     }
//                 // }

//                 // if ($transaction->affiliate_id) {
//                 //     $affiliate = \App\Models\User::find($transaction->affiliate_id);
//                 //     if ($affiliate) {
//                 //         $commissionEarned = 0;
//                 //         if ($affiliate->commission_type === 'percentage') {
//                 //             $commissionEarned = $transaction->total_amount * ($affiliate->commission_rate / 100);
//                 //         } else {
//                 //             $totalItems = $transaction->details->sum('quantity');
//                 //             // $commissionEarned = $totalItems * $affiliate->commission_rate;
//                 //         }

//                 //         // [PERUBAHAN]: Hanya catat nominal dan set status menjadi 'pending'
//                 //         // Uang BELUM masuk ke commission_balance milik afiliator
//                 //         $transaction->update([
//                 //             'commission_earned' => $commissionEarned,
//                 //             'commission_status' => 'pending'
//                 //         ]);
//                 //     }
//                 // }
//                 // 👆 BATAS KODE AFILIATOR 👆

//                 // =====================================================================
//                 // 👇 [PERBAIKAN] PENCAIRAN INSTAN KHUSUS IN-STORE PICKUP 👇
//                 // =====================================================================
//                 if ($targetTransactionStatus === 'completed' && $transaction->affiliate_id && $transaction->commission_status === 'pending') {
//                     $transaction->update(['commission_status' => 'settled']);

//                     $affiliateUser = \App\Models\User::find($transaction->affiliate_id);
//                     if ($affiliateUser) {
//                         $affiliateUser->increment('commission_balance', $transaction->commission_earned);
//                     }
//                 }

//                 // Eksekusi API Logistik (Biteship/DHL) - Logika kembar dengan Xendit
//                 if (in_array($transaction->shipping_method, ['biteship', 'dhl'])) {
//                     DB::afterCommit(function () use ($transaction) {
//                         try {
//                             $transaction->loadMissing(['address', 'user', 'details.product']);
//                             $destinationCountry = $transaction->address->region ?? ($transaction->address->details['region'] ?? 'Indonesia');
//                             $shippingGateway = ShippingFactory::make($destinationCountry);

//                             $items = [];
//                             foreach ($transaction->details as $detail) {
//                                 $items[] = [
//                                     'name' => $detail->product->name,
//                                     'value' => (int) $detail->price,
//                                     'quantity' => (int) $detail->quantity,
//                                     'weight' => (int) ($detail->product->weight ?? 1000),
//                                 ];
//                             }

//                             $transactionData = [
//                                 'courier_company' => $transaction->courier_company,
//                                 'courier_type' => $transaction->courier_type,
//                                 'delivery_type' => $transaction->delivery_type,
//                                 'delivery_date' => $transaction->delivery_date,
//                                 'delivery_time' => $transaction->delivery_time,
//                                 'destination' => [
//                                     'name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
//                                     'phone' => $transaction->user->phone ?? '08123456789',
//                                     'address' => $transaction->address->address_location,
//                                     'postal_code' => $transaction->address->postal_code,
//                                     'latitude' => $transaction->address->latitude,
//                                     'longitude' => $transaction->address->longitude,
//                                     'country' => $destinationCountry
//                                 ],
//                                 'items' => $items,
//                             ];

//                             $order = $shippingGateway->createOrder($transactionData);

//                             if (isset($order['id'])) {
//                                 $transaction->update([
//                                     'biteship_order_id' => $order['id'],
//                                     'tracking_number' => $order['tracking_number'],
//                                     'shipping_status' => $order['status'],
//                                 ]);
//                             }
//                         } catch (\Exception $e) {
//                             report($e);
//                             \Log::error('Stripe Shipping Callback Exception: '.$e->getMessage());
//                         }
//                     });
//                 } else {
//                     $transaction->update([
//                         'tracking_number' => 'In-Store Pickup',
//                         'shipping_status' => 'ready_for_pickup',
//                     ]);
//                 }

//                 return response()->json(['message' => 'Stripe Checkout Session Completed Handled']);
//             });
//         }

//         // Logika ketika sesi Stripe expired / ditutup paksa
//         elseif ($event->type == 'checkout.session.expired') {
//             $session = $event->data->object;
//             $externalId = $session->client_reference_id;

//             DB::transaction(function () use ($externalId) {
//                 $payment = Payment::where('external_id', $externalId)->lockForUpdate()->first();
//                 if ($payment) {
//                     $transaction = Transaction::lockForUpdate()->find($payment->transaction_id);
//                     if ($transaction->status !== 'cancelled') {
//                         $payment->update(['status' => 'EXPIRED']);
//                         $transaction->update([
//                             'status' => 'cancelled',
//                             'shipping_status' => 'cancelled',
//                         ]);

//                         if ($transaction->points_used > 0) {
//                             $transaction->user->increment('point', $transaction->points_used);
//                         }

//                         $transactionController = app(TransactionController::class);
//                         foreach ($transaction->details as $detail) {
//                             $transactionController->restoreProductStock($detail->product_id, $detail->quantity);
//                         }
//                     }
//                 }
//             });
//         }

//         // Return status 200 agar Stripe berhenti mem-ping server
//         return response()->json(['status' => 'success']);
//     }

//     // =====================================================================
//     // 3. WEBHOOK PAYPAL (UNTUK PEMBAYARAN INTERNASIONAL)
//     // =====================================================================
//     // public function paypalWebhook(Request $request)
//     // {
//     //     $payload = $request->all();
//     //     $eventType = $payload['event_type'] ?? null;

//     //     // Kita hanya peduli pada event ketika uang benar-benar sudah ditarik
//     //     if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
//     //         // Ambil Order ID bawaan PayPal dari kedalaman data JSON mereka
//     //         $paypalOrderId = $payload['resource']['supplementary_data']['related_ids']['order_id'] ?? null;

//     //         if (!$paypalOrderId) {
//     //             \Log::error("PayPal Webhook: Order ID tidak ditemukan di payload.");
//     //             return response()->json(['error' => 'Order ID missing'], 400);
//     //         }

//     //         return DB::transaction(function () use ($paypalOrderId) {
//     //             // Trik Cerdas: Karena kita menyimpan Order ID PayPal di dalam tautan checkout_url,
//     //             // kita bisa mencari pesanan yang sesuai menggunakan kata kunci (LIKE)
//     //             $payment = Payment::where('checkout_url', 'LIKE', '%' . $paypalOrderId . '%')->lockForUpdate()->first();

//     //             if (!$payment) {
//     //                 \Log::error("PayPal Webhook: Payment tidak ditemukan untuk Order ID {$paypalOrderId}");
//     //                 return response()->json(['message' => 'Payment not found'], 404);
//     //             }

//     //             $transaction = Transaction::lockForUpdate()->find($payment->transaction_id);

//     //             // Cek apakah status sudah lunas untuk mencegah pemrosesan ganda
//     //             if ($payment->status === 'PAID' || in_array($transaction->status, ['processing', 'completed'])) {
//     //                 return response()->json(['message' => 'Already processed']);
//     //             }

//     //             // 1. Ubah status menjadi LUNAS
//     //             $payment->update(['status' => 'PAID']);

//     //             $targetTransactionStatus = ($transaction->shipping_method === 'free') ? 'completed' : 'processing';

//     //             $transaction->update([
//     //                 'status' => $targetTransactionStatus,
//     //                 'payment_method' => 'PAYPAL',
//     //             ]);

//     //             // 2. Eksekusi API Logistik (Logika ini sama persis dengan Xendit/Stripe)
//     //             if (in_array($transaction->shipping_method, ['biteship', 'dhl'])) {
//     //                 DB::afterCommit(function () use ($transaction) {
//     //                     try {
//     //                         $transaction->loadMissing(['address', 'user', 'details.product']);
//     //                         $destinationCountry = $transaction->address->region ?? ($transaction->address->details['region'] ?? 'Indonesia');
//     //                         $shippingGateway = ShippingFactory::make($destinationCountry);

//     //                         $items = [];
//     //                         foreach ($transaction->details as $detail) {
//     //                             $items[] = [
//     //                                 'name' => $detail->product->name,
//     //                                 'value' => (int) $detail->price,
//     //                                 'quantity' => (int) $detail->quantity,
//     //                                 'weight' => (int) ($detail->product->weight ?? 1000),
//     //                             ];
//     //                         }

//     //                         $transactionData = [
//     //                             'courier_company' => $transaction->courier_company,
//     //                             'courier_type' => $transaction->courier_type,
//     //                             'delivery_type' => $transaction->delivery_type,
//     //                             'delivery_date' => $transaction->delivery_date,
//     //                             'delivery_time' => $transaction->delivery_time,
//     //                             'destination' => [
//     //                                 'name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
//     //                                 'phone' => $transaction->user->phone ?? '08123456789',
//     //                                 'address' => $transaction->address->address_location,
//     //                                 'postal_code' => $transaction->address->postal_code,
//     //                                 'latitude' => $transaction->address->latitude,
//     //                                 'longitude' => $transaction->address->longitude,
//     //                                 'country' => $destinationCountry
//     //                             ],
//     //                             'items' => $items,
//     //                         ];

//     //                         $order = $shippingGateway->createOrder($transactionData);

//     //                         if (isset($order['id'])) {
//     //                             $transaction->update([
//     //                                 'biteship_order_id' => $order['id'],
//     //                                 'tracking_number' => $order['tracking_number'],
//     //                                 'shipping_status' => $order['status'],
//     //                             ]);
//     //                         }
//     //                     } catch (\Exception $e) {
//     //                         \Log::error('PayPal Shipping Callback Exception: '.$e->getMessage());
//     //                     }
//     //                 });
//     //             } else {
//     //                 $transaction->update([
//     //                     'tracking_number' => 'In-Store Pickup',
//     //                     'shipping_status' => 'ready_for_pickup',
//     //                 ]);
//     //             }

//     //             return response()->json(['message' => 'PayPal Webhook Processed Successfully']);
//     //         });
//     //     }

//     //     // Return 200 OK untuk event lain agar server PayPal tenang dan tidak terus mencoba mengirim ulang
//     //     return response()->json(['status' => 'success']);
//     // }

//     // =====================================================================
//     // 3. WEBHOOK PAYPAL (UNTUK PEMBAYARAN INTERNASIONAL)
//     // =====================================================================
//     public function paypalWebhook(Request $request)
//     {
//         $payload = $request->all();
//         $eventType = $payload['event_type'] ?? null;

//         if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {

//             // 🔥 Ambil external_id asli kita yang disimpan PayPal di dalam custom_id
//             $externalId = $payload['resource']['custom_id'] ?? null;

//             if (!$externalId) {
//                 \Log::error("PayPal Webhook: Custom ID (External ID) tidak ditemukan di payload.");
//                 return response()->json(['error' => 'External ID missing'], 400);
//             }

//             return DB::transaction(function () use ($externalId) {

//                 // 🔥 Pencarian sekarang 100% akurat dan instan, sama seperti Xendit & Stripe!
//                 $payment = Payment::where('external_id', $externalId)->lockForUpdate()->first();

//                 if (!$payment) {
//                     \Log::error("PayPal Webhook: Payment tidak ditemukan untuk External ID {$externalId}");
//                     return response()->json(['message' => 'Payment not found'], 404);
//                 }

//                 $transaction = Transaction::lockForUpdate()->find($payment->transaction_id);

//                 if ($payment->status === 'PAID' || in_array($transaction->status, ['processing', 'completed'])) {
//                     return response()->json(['message' => 'Already processed']);
//                 }

//                 $payment->update(['status' => 'PAID']);

//                 $targetTransactionStatus = ($transaction->shipping_method === 'free') ? 'completed' : 'processing';

//                 $transaction->update([
//                     'status' => $targetTransactionStatus,
//                     'payment_method' => 'PAYPAL',
//                 ]);

//                 // 👇 TEMPEL KODE AFILIATOR DI SINI 👇
//                 // if ($transaction->affiliate_id) {
//                 //     $affiliate = \App\Models\User::find($transaction->affiliate_id);
//                 //     if ($affiliate) {
//                 //         $commissionEarned = 0;
//                 //         if ($affiliate->commission_type === 'percentage') {
//                 //             $commissionEarned = $transaction->total_amount * ($affiliate->commission_rate / 100);
//                 //         } else {
//                 //             $totalItems = $transaction->details->sum('quantity');
//                 //            // $commissionEarned = $totalItems * $affiliate->commission_rate;
//                 //         }
//                 //         $transaction->update(['commission_earned' => $commissionEarned]);
//                 //         $affiliate->increment('commission_balance', $commissionEarned);
//                 //     }
//                 // }

//                 // if ($transaction->affiliate_id) {
//                 //     $affiliate = \App\Models\User::find($transaction->affiliate_id);
//                 //     if ($affiliate) {
//                 //         $commissionEarned = 0;
//                 //         if ($affiliate->commission_type === 'percentage') {
//                 //             $commissionEarned = $transaction->total_amount * ($affiliate->commission_rate / 100);
//                 //         } else {
//                 //             $totalItems = $transaction->details->sum('quantity');
//                 //             // $commissionEarned = $totalItems * $affiliate->commission_rate;
//                 //         }

//                 //         // [PERUBAHAN]: Hanya catat nominal dan set status menjadi 'pending'
//                 //         // Uang BELUM masuk ke commission_balance milik afiliator
//                 //         $transaction->update([
//                 //             'commission_earned' => $commissionEarned,
//                 //             'commission_status' => 'pending'
//                 //         ]);
//                 //     }
//                 // }
//                 // 👆 BATAS KODE AFILIATOR 👆

//                 // =====================================================================
//                 // 👇 [PERBAIKAN] PENCAIRAN INSTAN KHUSUS IN-STORE PICKUP 👇
//                 // =====================================================================
//                 if ($targetTransactionStatus === 'completed' && $transaction->affiliate_id && $transaction->commission_status === 'pending') {
//                     $transaction->update(['commission_status' => 'settled']);

//                     $affiliateUser = \App\Models\User::find($transaction->affiliate_id);
//                     if ($affiliateUser) {
//                         $affiliateUser->increment('commission_balance', $transaction->commission_earned);
//                     }
//                 }

//                 if (in_array($transaction->shipping_method, ['biteship', 'dhl'])) {
//                     DB::afterCommit(function () use ($transaction) {
//                         try {
//                             $transaction->loadMissing(['address', 'user', 'details.product']);
//                             $destinationCountry = $transaction->address->region ?? ($transaction->address->details['region'] ?? 'Indonesia');
//                             $shippingGateway = ShippingFactory::make($destinationCountry);

//                             $items = [];
//                             foreach ($transaction->details as $detail) {
//                                 $items[] = [
//                                     'name' => $detail->product->name,
//                                     'value' => (int) $detail->price,
//                                     'quantity' => (int) $detail->quantity,
//                                     'weight' => (int) ($detail->product->weight ?? 1000),
//                                 ];
//                             }

//                             $transactionData = [
//                                 'courier_company' => $transaction->courier_company,
//                                 'courier_type' => $transaction->courier_type,
//                                 'delivery_type' => $transaction->delivery_type,
//                                 'delivery_date' => $transaction->delivery_date,
//                                 'delivery_time' => $transaction->delivery_time,
//                                 'destination' => [
//                                     'name' => trim($transaction->address->first_name_address . ' ' . $transaction->address->last_name_address),
//                                     'phone' => $transaction->user->phone ?? '08123456789',
//                                     'address' => $transaction->address->address_location,
//                                     'postal_code' => $transaction->address->postal_code,
//                                     'latitude' => $transaction->address->latitude,
//                                     'longitude' => $transaction->address->longitude,
//                                     'country' => $destinationCountry
//                                 ],
//                                 'items' => $items,
//                             ];

//                             $order = $shippingGateway->createOrder($transactionData);

//                             if (isset($order['id'])) {
//                                 $transaction->update([
//                                     'biteship_order_id' => $order['id'],
//                                     'tracking_number' => $order['tracking_number'],
//                                     'shipping_status' => $order['status'],
//                                 ]);
//                             }
//                         } catch (\Exception $e) {
//                             report($e);
//                             \Log::error('PayPal Shipping Callback Exception: '.$e->getMessage());
//                         }
//                     });
//                 } else {
//                     $transaction->update([
//                         'tracking_number' => 'In-Store Pickup',
//                         'shipping_status' => 'ready_for_pickup',
//                     ]);
//                 }

//                 return response()->json(['message' => 'PayPal Webhook Processed Successfully']);
//             });
//         }

//         return response()->json(['status' => 'success']);
//     }

//     public function capturePayPal(Request $request)
//     {
//         // PayPal otomatis menyisipkan Order ID mereka ke dalam parameter URL bernama 'token'
//         $paypalToken = $request->query('token');
//         $externalId = $request->query('external_id');
//         $orderId = $request->query('order_id');

//         // Lakukan penarikan dana (Capture)
//         $paypalService = app(\App\Services\PayPalService::class);
//         $paypalService->capturePayment($paypalToken);

//         // Setelah ditarik, lemparkan pembeli ke halaman sukses Vue.js Anda seperti biasa
//         $frontendSuccessUrl = config('app.frontend_url')
//             . '/payment-success?external_id=' . $externalId
//             . '&order_id=' . $orderId;

//         return redirect($frontendSuccessUrl);
//     }

//     // public function getShippingRates(Request $request)
//     // {
//     //     $user = $request->user();
//     //     if (! $user) {
//     //         return response()->json([
//     //             'message' => 'Unauthorized. Please login again.',
//     //         ], 401);
//     //     }

//     //     $request->validate([
//     //         'address_id' => 'required|exists:addresses,id',
//     //         'cart_ids' => 'required|array',
//     //         'cart_ids.*' => 'exists:carts,id',
//     //     ]);

//     //     $address = Address::find($request->address_id);

//     //     if (! $address || ! $address->postal_code) {
//     //         return response()->json([
//     //             'message' => 'Alamat tidak valid atau kodepos tidak ditemukan.',
//     //         ], 400);
//     //     }

//     //     try {
//     //         $biteship = new BiteshipService;

//     //         $cartItems = Cart::with('product')->whereIn('id', $request->cart_ids)->where('user_id', $user->id)->get();

//     //         $totalWeight = 0;
//     //         foreach ($cartItems as $item) {
//     //             $itemWeight = $item->product->weight ?? 1000;
//     //             $totalWeight += ($itemWeight * $item->quantity);
//     //         }

//     //         if ($totalWeight <= 0) {
//     //             $totalWeight = 1000;
//     //         }

//     //         $rates = $biteship->getRates($address, $totalWeight);

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

//     // public function getShippingRates(Request $request)
//     // {
//     //     $user = $request->user();
//     //     if (! $user) {
//     //         return response()->json(['message' => 'Unauthorized. Please login again.'], 401);
//     //     }

//     //     $request->validate([
//     //         'address_id' => 'required|exists:addresses,id',
//     //         'cart_ids' => 'required|array',
//     //         'cart_ids.*' => 'exists:carts,id',
//     //     ]);

//     //     $address = Address::find($request->address_id);

//     //     if (! $address || ! $address->postal_code) {
//     //         return response()->json(['message' => 'Alamat tidak valid atau kodepos tidak ditemukan.'], 400);
//     //     }

//     //     try {
//     //         $cartItems = Cart::with('product')->whereIn('id', $request->cart_ids)->where('user_id', $user->id)->get();

//     //         // 1. Format Data Origin (Gudang/Toko)
//     //         $origin = [
//     //             'postal_code' => config('services.biteship.origin_postal_code', '60272'),
//     //             'latitude' => -7.25653,
//     //             'longitude' => 112.74877,
//     //         ];

//     //         // 2. Format Data Destination (Pelanggan)
//     //         // $destinationCountry = $address->region ?? 'Indonesia'; // Fallback ke Indonesia jika kosong

//     //         $destinationCountry = $address->region ?? ($address->details['region'] ?? 'Indonesia');
//     //         $destination = [
//     //             'name' => trim($address->first_name_address . ' ' . $address->last_name_address),
//     //             'phone' => $user->phone ?? '08123456789',
//     //             'address' => $address->address_location,
//     //             'postal_code' => $address->postal_code,
//     //             'latitude' => $address->latitude,
//     //             'longitude' => $address->longitude,
//     //         ];

//     //         // 3. Format Data Items
//     //         $items = [];
//     //         foreach ($cartItems as $item) {
//     //             $items[] = [
//     //                 'name' => $item->product->name,
//     //                 'value' => $item->product->discount_price ?? $item->product->price,
//     //                 'quantity' => $item->quantity,
//     //                 'weight' => $item->product->weight ?? 1000,
//     //             ];
//     //         }

//     //         // =========================================================================
//     //         // [LOGIKA BARU] Panggil Shipping Factory berdasarkan Negara Tujuan!
//     //         // =========================================================================
//     //         $shippingGateway = ShippingFactory::make($destinationCountry);
//     //         $rates = $shippingGateway->calculateRates($origin, $destination, $items);

//     //         return response()->json($rates);

//     //     } catch (\Exception $e) {
//     //         return response()->json([
//     //             'message' => 'Gagal mengambil ongkos kirim: '.$e->getMessage(),
//     //         ], 500);
//     //     }
//     // }

//     public function getShippingRates(Request $request)
//     {
//         $user = $request->user();
//         if (! $user) {
//             return response()->json(['message' => 'Unauthorized. Please login again.'], 401);
//         }

//         $request->validate([
//             'address_id' => 'required|exists:addresses,id',
//             'cart_ids' => 'required|array',
//             'cart_ids.*' => 'exists:carts,id',
//         ]);

//         $address = Address::find($request->address_id);

//         if (! $address || ! $address->postal_code) {
//             return response()->json(['message' => 'Alamat tidak valid atau kodepos tidak ditemukan.'], 400);
//         }

//         try {
//             $cartItems = Cart::with('product')->whereIn('id', $request->cart_ids)->where('user_id', $user->id)->get();

//             // 1. Format Data Origin (Gudang/Toko)
//             $origin = [
//                 'postal_code' => config('services.biteship.origin_postal_code', '60272'),
//                 'latitude' => -7.25653,
//                 'longitude' => 112.74877,
//             ];

//             // 2. Format Data Destination (Pelanggan)
//             $destinationCountry = $address->region ?? ($address->details['region'] ?? 'Indonesia');

//             // [BARU] Konversi nama negara menjadi kode ISO-2 huruf untuk API Shippo
//             $countryCode = match (strtolower(trim($destinationCountry))) {
//                 'indonesia' => 'ID',
//                 'singapore' => 'SG',
//                 'malaysia' => 'MY',
//                 'united states' => 'US',
//                 'australia' => 'AU',
//                 'japan' => 'JP',
//                 'united kingdom' => 'GB',
//                 'taiwan' => 'TW',
//                 'china' => 'CN',
//                 'tiongkok' => 'CN',
//                 default => 'US' // Fallback aman
//             };

//             $destination = [
//                 'name'         => trim($address->first_name_address . ' ' . $address->last_name_address),
//                 'phone'        => $user->phone ?? '08123456789',
//                 'address'      => $address->address_location,
//                 'postal_code'  => $address->postal_code,
//                 'latitude'     => $address->latitude,
//                 'longitude'    => $address->longitude,
//                 // [BARU] Data krusial yang dibutuhkan oleh kurir Internasional
//                 'city'         => $address->city ?? 'Unknown City',
//                 'province'     => $address->province ?? 'Unknown Province',
//                 'country_code' => $countryCode,
//             ];

//             // 3. Format Data Items (Biteship Format)
//             // $items = [];
//             // $totalWeightGrams = 0; // [BARU] Kalkulasi total berat untuk Shippo

//             // foreach ($cartItems as $item) {
//             //     $itemWeight = $item->product->weight ?? 1000;
//             //     $items[] = [
//             //         'name'     => $item->product->name,
//             //         'value'    => $item->product->discount_price ?? $item->product->price,
//             //         'quantity' => $item->quantity,
//             //         'weight'   => $itemWeight,
//             //     ];
//             //     $totalWeightGrams += ($itemWeight * $item->quantity);
//             // }

//             // 3. Format Data Items (Biteship Format)
//             $items = [];
//             $totalWeightGrams = 0;

//             foreach ($cartItems as $item) {
//                 $itemWeight = $item->product->weight ?? 1000;

//                 // 👇 [PERBAIKAN LOGIKA HARGA DISKON] 👇
//                 $validPrice = $item->product->price;
//                 if (
//                     !empty($item->product->discount_price) &&
//                     $item->product->discount_start <= now() &&
//                     $item->product->discount_end >= now()
//                 ) {
//                     $validPrice = $item->product->discount_price;
//                 }

//                 $items[] = [
//                     'name'     => $item->product->name,
//                     'value'    => $validPrice, // Gunakan harga yang sudah tervalidasi
//                     'quantity' => $item->quantity,
//                     'weight'   => $itemWeight,
//                 ];
//                 $totalWeightGrams += ($itemWeight * $item->quantity);
//             }

//             // [BARU] Menyisipkan total berat dalam kilogram untuk Shippo
//             // Shippo menerima parameter ke-3 ($parcel), jadi kita bungkus items & ringkasan beratnya
//             $parcelData = [
//                 'items'  => $items,
//                 'weight' => max(0.1, $totalWeightGrams / 1000), // Konversi gram ke KG (minimal 0.1 kg)
//                 'length' => '20', // Asumsi standar ukuran paket
//                 'width'  => '20',
//                 'height' => '10'
//             ];

//             // =========================================================================
//             // Panggil Shipping Factory berdasarkan Negara Tujuan!
//             // =========================================================================
//             $shippingGateway = ShippingFactory::make($destinationCountry);

//             // Oper $parcelData ke Factory agar Biteship dapat 'items' dan Shippo dapat 'weight'
//             $rates = $shippingGateway->calculateRates($origin, $destination, $parcelData);

//             return response()->json($rates);

//         } catch (\Exception $e) {
//             report($e);
//             return response()->json([
//                 'message' => 'Gagal mengambil ongkos kirim: '.$e->getMessage(),
//             ], 500);
//         }
//     }

//     private function checkAndAssignMembership($user)
//     {
//         if ($user->is_membership) {
//             return;
//         }

//         $totalSpent = Transaction::where('user_id', $user->id)
//             ->where('status', 'completed')
//             ->sum('total_amount');

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
use App\Services\ShippingFactory;
use App\Services\PaymentFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http; // [BARU] Import Facade HTTP untuk akses API Facebook
use Illuminate\Support\Facades\Log; // [BARU] Import Log untuk mencatat jika ada error CAPI

class PaymentController extends Controller
{
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
            'currency' => 'required|string|in:IDR,USD,SGD,EUR',
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
                'currency_code' => $request->currency,
            ]);
        } else {
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

        $currency = $transaction->currency_code ?? 'IDR';
        $paymentGateway = PaymentFactory::make($currency);

        $frontendSuccessUrl = config('app.frontend_url')
            . '/payment-success?external_id=' . $externalId
            . '&order_id=' . $transaction->order_id;

        $paypalCaptureUrl = url('/api/payments/paypal-capture?external_id=' . $externalId . '&order_id=' . $transaction->order_id);
        $dynamicSuccessUrl = ($currency === 'IDR') ? $frontendSuccessUrl : $paypalCaptureUrl;

        $checkoutUrl = $paymentGateway->createInvoice([
            'order_id' => $transaction->order_id,
            'external_id' => $externalId,
            'payer_email' => $transaction->user->email,
            'amount' => $finalAmount,
            'currency' => $currency,
            'items' => $items,
            'success_redirect_url' => $dynamicSuccessUrl,
            'failure_redirect_url' => config('app.frontend_url').'/payment-failed',
        ]);

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
            'gateway' => $currency === 'IDR' ? 'Xendit' : 'Stripe',
        ]);
    }

    // =====================================================================
    // 1. WEBHOOK XENDIT
    // =====================================================================
    public function xenditCallback(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $payment = Payment::where('external_id', $request->external_id)->lockForUpdate()->first();

            if (! $payment) {
                return response()->json(['message' => 'Payment not found'], 404);
            }

            $status = $request->status;
            $transaction = Transaction::lockForUpdate()->find($payment->transaction_id);

            if ($status === 'PAID') {
                if ($payment->status === 'PAID' || in_array($transaction->status, ['processing', 'completed'])) {
                    return response()->json(['message' => 'Already processed']);
                }

                $payment->update(['status' => $status]);

                // 👇 [BARU] MENGIRIM DATA KE FACEBOOK CAPI 👇
                $this->sendFacebookConversionAPI($transaction);

                $paymentMethod = $request->input('payment_method', 'Unknown');
                $paymentChannel = $request->input('payment_channel', '');
                $fullPaymentMethod = trim($paymentMethod.' '.$paymentChannel);

                $targetTransactionStatus = ($transaction->shipping_method === 'free') ? 'completed' : 'processing';

                $transaction->update([
                    'status' => $targetTransactionStatus,
                    'payment_method' => $fullPaymentMethod,
                ]);

                if ($targetTransactionStatus === 'completed' && $transaction->affiliate_id && $transaction->commission_status === 'pending') {
                    $transaction->update(['commission_status' => 'settled']);

                    $affiliateUser = \App\Models\User::find($transaction->affiliate_id);
                    if ($affiliateUser) {
                        $affiliateUser->increment('commission_balance', $transaction->commission_earned);
                    }
                }

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
                            report($e);
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
    // 2. WEBHOOK STRIPE
    // =====================================================================
    public function stripeWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = config('services.stripe.webhook_secret');

        try {
            if ($endpointSecret) {
                $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
            } else {
                $event = json_decode($payload);
            }
        } catch (\UnexpectedValueException $e) {
            report($e);
            \Log::error('Stripe Webhook Error: Invalid payload');
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            report($e);
            \Log::error('Stripe Webhook Error: Invalid signature');
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        if ($event->type == 'checkout.session.completed') {
            $session = $event->data->object;
            $externalId = $session->client_reference_id;

            return DB::transaction(function () use ($externalId, $session) {
                $payment = Payment::where('external_id', $externalId)->lockForUpdate()->first();

                if (! $payment) {
                    \Log::error("Stripe Webhook: Payment not found for reference {$externalId}");
                    return response()->json(['message' => 'Payment not found'], 404);
                }

                $transaction = Transaction::lockForUpdate()->find($payment->transaction_id);

                if ($payment->status === 'PAID' || in_array($transaction->status, ['processing', 'completed'])) {
                    return response()->json(['message' => 'Already processed']);
                }

                $payment->update(['status' => 'PAID']);

                // 👇 [BARU] MENGIRIM DATA KE FACEBOOK CAPI 👇
                $this->sendFacebookConversionAPI($transaction);

                $paymentMethodTypes = $session->payment_method_types;
                $paymentMethod = !empty($paymentMethodTypes) ? strtoupper($paymentMethodTypes[0]) : 'STRIPE';

                $targetTransactionStatus = ($transaction->shipping_method === 'free') ? 'completed' : 'processing';

                $transaction->update([
                    'status' => $targetTransactionStatus,
                    'payment_method' => 'STRIPE ' . $paymentMethod,
                ]);

                if ($targetTransactionStatus === 'completed' && $transaction->affiliate_id && $transaction->commission_status === 'pending') {
                    $transaction->update(['commission_status' => 'settled']);

                    $affiliateUser = \App\Models\User::find($transaction->affiliate_id);
                    if ($affiliateUser) {
                        $affiliateUser->increment('commission_balance', $transaction->commission_earned);
                    }
                }

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
                            report($e);
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

        return response()->json(['status' => 'success']);
    }

    // =====================================================================
    // 3. WEBHOOK PAYPAL
    // =====================================================================
    public function paypalWebhook(Request $request)
    {
        $payload = $request->all();
        $eventType = $payload['event_type'] ?? null;

        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {

            $externalId = $payload['resource']['custom_id'] ?? null;

            if (!$externalId) {
                \Log::error("PayPal Webhook: Custom ID (External ID) tidak ditemukan di payload.");
                return response()->json(['error' => 'External ID missing'], 400);
            }

            return DB::transaction(function () use ($externalId) {
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

                // 👇 [BARU] MENGIRIM DATA KE FACEBOOK CAPI 👇
                $this->sendFacebookConversionAPI($transaction);

                $targetTransactionStatus = ($transaction->shipping_method === 'free') ? 'completed' : 'processing';

                $transaction->update([
                    'status' => $targetTransactionStatus,
                    'payment_method' => 'PAYPAL',
                ]);

                if ($targetTransactionStatus === 'completed' && $transaction->affiliate_id && $transaction->commission_status === 'pending') {
                    $transaction->update(['commission_status' => 'settled']);

                    $affiliateUser = \App\Models\User::find($transaction->affiliate_id);
                    if ($affiliateUser) {
                        $affiliateUser->increment('commission_balance', $transaction->commission_earned);
                    }
                }

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
                            report($e);
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
        $paypalToken = $request->query('token');
        $externalId = $request->query('external_id');
        $orderId = $request->query('order_id');

        $paypalService = app(\App\Services\PayPalService::class);
        $paypalService->capturePayment($paypalToken);

        $frontendSuccessUrl = config('app.frontend_url')
            . '/payment-success?external_id=' . $externalId
            . '&order_id=' . $orderId;

        return redirect($frontendSuccessUrl);
    }

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

            $origin = [
                'postal_code' => config('services.biteship.origin_postal_code', '60272'),
                'latitude' => -7.25653,
                'longitude' => 112.74877,
            ];

            $destinationCountry = $address->region ?? ($address->details['region'] ?? 'Indonesia');

            $countryCode = match (strtolower(trim($destinationCountry))) {
                'indonesia' => 'ID',
                'singapore' => 'SG',
                'malaysia' => 'MY',
                'united states' => 'US',
                'australia' => 'AU',
                'japan' => 'JP',
                'united kingdom' => 'GB',
                'taiwan' => 'TW',
                'china' => 'CN',
                'tiongkok' => 'CN',
                default => 'US'
            };

            $destination = [
                'name'         => trim($address->first_name_address . ' ' . $address->last_name_address),
                'phone'        => $user->phone ?? '08123456789',
                'address'      => $address->address_location,
                'postal_code'  => $address->postal_code,
                'latitude'     => $address->latitude,
                'longitude'    => $address->longitude,
                'city'         => $address->city ?? 'Unknown City',
                'province'     => $address->province ?? 'Unknown Province',
                'country_code' => $countryCode,
            ];

            $items = [];
            $totalWeightGrams = 0;

            foreach ($cartItems as $item) {
                $itemWeight = $item->product->weight ?? 1000;

                $validPrice = $item->product->price;
                if (
                    !empty($item->product->discount_price) &&
                    $item->product->discount_start <= now() &&
                    $item->product->discount_end >= now()
                ) {
                    $validPrice = $item->product->discount_price;
                }

                $items[] = [
                    'name'     => $item->product->name,
                    'value'    => $validPrice,
                    'quantity' => $item->quantity,
                    'weight'   => $itemWeight,
                ];
                $totalWeightGrams += ($itemWeight * $item->quantity);
            }

            $parcelData = [
                'items'  => $items,
                'weight' => max(0.1, $totalWeightGrams / 1000),
                'length' => '20',
                'width'  => '20',
                'height' => '10'
            ];

            $shippingGateway = ShippingFactory::make($destinationCountry);

            $rates = $shippingGateway->calculateRates($origin, $destination, $parcelData);

            return response()->json($rates);

        } catch (\Exception $e) {
            report($e);
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

    // =====================================================================
    // 👇 FUNGSI HELPER BARU UNTUK MENGIRIM DATA KE FB CAPI 👇
    // =====================================================================
    private function sendFacebookConversionAPI(Transaction $transaction)
    {
        $pixelId = '1060021089748617';
        // Token dari Mas Sean
        $accessToken = 'EAATOy9uvwuMBSKF7gr9mSNTZCB6DYnAXDcgEmCMxLZA61GPs5hxHUfFjfNBZAQ2alYezpyGyU7zLZA6ubbM1yxADm36gBVLcYwDVyzVxfZCen9Rja5aQASYRIlgM0KgFZBbEZCWmTa60PuCGllmAJzByaa9kAvR4lWeg2SApuKCZCcWNqEnpU376xCrzfJ7hMQZDZD';

        $url = "https://graph.facebook.com/v19.0/{$pixelId}/events";

        // Pastikan relasi data user dan produk termuat
        $transaction->loadMissing(['user', 'details.product']);
        $user = $transaction->user;

        if (!$user) return; // Mencegah error jika data user tidak ditemukan

        // 1. Enkripsi Email (SHA256, huruf kecil)
        $hashedEmail = hash('sha256', strtolower(trim($user->email)));

        // 2. Enkripsi Nomor HP (SHA256, format internasional tanpa +)
        $cleanPhone = preg_replace('/[^0-9]/', '', $user->phone ?? '');
        if (!str_starts_with($cleanPhone, '62') && !empty($cleanPhone)) {
            $cleanPhone = '62' . ltrim($cleanPhone, '0');
        }
        $hashedPhone = !empty($cleanPhone) ? hash('sha256', $cleanPhone) : null;

        // 3. Ekstrak data produk
        $contents = [];
        foreach ($transaction->details as $detail) {
            $contents[] = [
                'id'         => (string) $detail->product_id,
                'quantity'   => (int) $detail->quantity,
                'item_price' => (float) $detail->price
            ];
        }

        // 4. Struktur Payload Facebook
        $userData = [
            'em' => [$hashedEmail],
        ];

        if ($hashedPhone) {
            $userData['ph'] = [$hashedPhone];
        }

        $payload = [
            'data' => [
                [
                    'event_name'    => 'Purchase',
                    'event_time'    => time(),
                    'action_source' => 'website',
                    'user_data'     => $userData,
                    'custom_data'   => [
                        'currency' => $transaction->currency_code ?? 'IDR', // Support multicurrency
                        'value'    => (float) $transaction->total_amount, // Total nilai belanja
                        'contents' => $contents // Data produk
                    ]
                ]
            ],
            // 'test_event_code' => 'TEST4450' // Ganti dengan kode dari Mas Sean
        ];

        // 5. Eksekusi HTTP Request secara background agar tidak mengganggu response payment
        try {
            $response = Http::post($url . '?access_token=' . $accessToken, $payload);

            if ($response->failed()) {
                Log::error('Facebook CAPI Error: ' . $response->body());
            }
        } catch (\Exception $e) {
            Log::error('Facebook CAPI Exception: ' . $e->getMessage());
        }
    }
}
