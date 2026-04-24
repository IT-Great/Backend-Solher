<?php

namespace App\Http\Controllers;

use App\Mail\RefundResultMail;
use App\Models\Cart;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\PromoClaim;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Xendit\Configuration;
use Xendit\Invoice\CreateInvoiceRequest;
use Xendit\Invoice\InvoiceApi;
use Xendit\Refund\CreateRefund;
use Xendit\Refund\RefundApi;
use Xendit\XenditSdkException;

class TransactionController extends Controller
{
    public function __construct()
    {
        Configuration::setXenditKey(config('services.xendit.secret_key'));
    }

    // =================================================================================
    // [BARU] HELPER FUNGSI UNTUK MENGEMBALIKAN STOK (FIFO RESTORE & ANTI RACE CONDITION)
    // =================================================================================
    public function restoreProductStock($productId, $quantityToRestore)
    {
        if ($quantityToRestore <= 0) {
            return;
        }

        // 1. Kunci (Lock) baris produk utama untuk mencegah modifikasi berbarengan
        $product = Product::lockForUpdate()->find($productId);
        if (! $product) {
            return;
        }

        $remainingToRestore = $quantityToRestore;

        // 2. Ambil batch stok yang TIDAK PENUH (quantity < initial_quantity)
        // Urutkan dari yang PALING LAMA (ASC) untuk mengembalikan secara FIFO
        $incompleteBatches = ProductStock::where('product_id', $productId)
            ->whereColumn('quantity', '<', 'initial_quantity')
            ->orderBy('created_at', 'asc')
            ->lockForUpdate() // Kunci baris batch ini selama transaksi berlangsung
            ->get();

        foreach ($incompleteBatches as $batch) {
            if ($remainingToRestore <= 0) {
                break;
            }

            $spaceAvailable = $batch->initial_quantity - $batch->quantity;

            if ($spaceAvailable >= $remainingToRestore) {
                // Jika lubang di batch ini cukup untuk menampung semua barang kembalian
                $batch->increment('quantity', $remainingToRestore);
                $remainingToRestore = 0;
            } else {
                // Jika tidak cukup, penuhi batch ini, sisanya cari di batch berikutnya
                $batch->increment('quantity', $spaceAvailable);
                $remainingToRestore -= $spaceAvailable;
            }
        }

        // 3. Fallback/Penyelamat: Jika ternyata masih ada sisa (misal: batch lama terhapus manual oleh admin)
        if ($remainingToRestore > 0) {
            $latestBatch = ProductStock::where('product_id', $productId)
                ->orderBy('created_at', 'desc')
                ->lockForUpdate()
                ->first();

            if ($latestBatch) {
                // Masukkan ke batch terbaru dan naikkan kapasitas awalnya agar tidak error
                $latestBatch->increment('quantity', $remainingToRestore);
                $latestBatch->increment('initial_quantity', $remainingToRestore);
            } else {
                // Jika benar-benar tidak ada batch sama sekali, buat batch pengembalian khusus
                ProductStock::create([
                    'product_id' => $productId,
                    'batch_code' => 'RET-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4)),
                    'quantity' => $remainingToRestore,
                    'initial_quantity' => $remainingToRestore,
                ]);
            }
        }

        // 4. Kembalikan total stok di tabel master
        $product->increment('stock', $quantityToRestore);
    }

    // --- USER ACTIONS ---
    public function checkout(Request $request)
    {
        try { // <--- Bungkus dengan Try
            // ... (Validasi request tetap sama) ...
            $request->validate([
                'address_id' => 'required',
                'shipping_method' => 'required|in:free,biteship',
                'use_points' => 'nullable|integer|min:0',
                'cart_ids' => 'required|array',
                'cart_ids.*' => 'exists:carts,id',
                'shipping_cost' => 'nullable|numeric',
                'courier_company' => 'nullable|string',
                'courier_type' => 'nullable|string',
                'delivery_type' => 'nullable|string',
            ]);

            $user = $request->user();
            $cartItems = Cart::with('product')
                ->where('user_id', $user->id)
                ->whereIn('id', $request->cart_ids)
                ->get();

            if ($cartItems->isEmpty()) {
                return response()->json(['message' => 'No items selected for checkout'], 400);
            }

            // // 1. LAKUKAN PROSES DATABASE DENGAN KILAT (TANPA API PIHAK KETIGA)
            // $transactionData = DB::transaction(function () use ($user, $cartItems, $request) {
            //     $totalAmount = 0;
            //     foreach ($cartItems as $item) {
            //         $currentPrice = $item->product->discount_price ?? $item->product->price;
            //         $totalAmount += ($currentPrice * $item->quantity);
            //     }

            //     // =========================================================================
            //     // [LOGIKA BARU] 1. POTONG PROMO CODE TERLEBIH DAHULU (ZERO-FLOOR)
            //     // =========================================================================
            //     $promoDiscountAmount = 0;
            //     $appliedPromoCode = null;

            //     if (! empty($request->promo_code)) {
            //         // Kunci (Lock) baris promo claim agar tidak bisa di-klik ganda bersamaan
            //         $promoClaim = PromoClaim::where('email', $user->email)
            //             ->where('promo_code', strtoupper($request->promo_code))
            //             ->lockForUpdate()
            //             ->first();

            //         if (! $promoClaim) {
            //             throw new \Exception('Kode Promo tidak valid untuk akun email ini.');
            //         }
            //         if ($promoClaim->is_used) {
            //             throw new \Exception('Kode Promo sudah pernah digunakan.');
            //         }
            //         if ($totalAmount < 50000) { // Syarat batas minimal belanja
            //             throw new \Exception('Minimum belanja untuk memakai promo ini adalah Rp 50.000');
            //         }

            //         $promoDiscountAmount = min($promoClaim->discount_value, $totalAmount);
            //         $appliedPromoCode = $promoClaim->promo_code;

            //         // Tandai promo sudah digunakan
            //         $promoClaim->update([
            //             'is_used' => true,
            //             'used_at' => now(),
            //         ]);
            //     }

            //     // Total harga setelah promo dipotong
            //     $totalAfterPromo = max(0, $totalAmount - $promoDiscountAmount);

            //     // =========================================================================
            //     // 2. POTONG POIN DARI SISA HARGA SETELAH PROMO (Mencegah Tagihan Minus)
            //     // =========================================================================
            //     $orderId = 'SOL-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

            //     // $earnedPoints = 0;
            //     // if ($user->is_membership) {
            //     //     $earnedPoints = floor($totalAmount / 100000);
            //     // }

            //     $earnedPoints = $user->is_membership ? floor($totalAmount / 100000) : 0;

            //     $pointsUsed = 0;
            //     $pointDiscountAmount = 0;
            //     if ($request->use_points > 0 && $user->is_membership) {
            //         $pointsUsed = min($request->use_points, $user->point);
            //         // $pointDiscountAmount = min($pointsUsed * 1000, $totalAmount);
            //         // if ($pointsUsed > 0) {
            //         //     $user->decrement('point', $pointsUsed);
            //         // }

            //         // Poin maksimal yang bisa dipakai = Sisa harga setelah promo
            //         $maxUsableDiscount = min($pointsUsed * 1000, $totalAfterPromo);
            //         $pointDiscountAmount = $maxUsableDiscount;

            //         // Konversi kembali ke poin riil yang terpakai
            //         $actualPointsDeducted = floor($maxUsableDiscount / 1000);
            //         $pointsUsed = $actualPointsDeducted;

            //         if ($pointsUsed > 0) {
            //             $user->decrement('point', $pointsUsed);
            //         }
            //     }

            //     $totalQuantity = $cartItems->sum('quantity') ?: 1;
            //     $baseShippingRate = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);
            //     $totalShippingCost = $baseShippingRate * $totalQuantity;

            //     $transaction = Transaction::create([
            //         'user_id' => $user->id,
            //         'address_id' => $request->address_id,
            //         'shipping_method' => $request->shipping_method,
            //         'shipping_cost' => $totalShippingCost,
            //         'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
            //         'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
            //         'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
            //         'order_id' => $orderId,
            //         'total_amount' => $totalAmount,
            //         'status' => 'pending',
            //         'point' => $earnedPoints,
            //         'points_used' => $pointsUsed,
            //         'promo_code' => $appliedPromoCode,
            //         'promo_discount' => $promoDiscountAmount,
            //     ]);

            // 1. LAKUKAN PROSES DATABASE DENGAN KILAT (TANPA API PIHAK KETIGA)
            $transactionData = DB::transaction(function () use ($user, $cartItems, $request) {

                // =========================================================================
                // [PERBAIKAN KRITIS]: Kunci data User spesifik ini selama proses checkout.
                // Jika ada 2 request masuk bersamaan, request kedua akan disuruh antre
                // menunggu request pertama selesai memotong poin.
                // =========================================================================
                $lockedUser = User::lockForUpdate()->find($user->id);

                $totalAmount = 0;
                foreach ($cartItems as $item) {
                    $currentPrice = $item->product->discount_price ?? $item->product->price;
                    $totalAmount += ($currentPrice * $item->quantity);
                }

                // =========================================================================
                // [LOGIKA BARU] 1. POTONG PROMO CODE TERLEBIH DAHULU (ZERO-FLOOR)
                // =========================================================================
                $promoDiscountAmount = 0;
                $appliedPromoCode = null;

                // if (! empty($request->promo_code)) {
                //     // Pastikan menggunakan $lockedUser->email untuk validasi
                //     $promoClaim = PromoClaim::where('email', $lockedUser->email)
                //         ->where('promo_code', strtoupper($request->promo_code))
                //         ->lockForUpdate()
                //         ->first();

                //     if (! $promoClaim) {
                //         throw new \Exception('Kode Promo tidak valid untuk akun email ini.');
                //     }
                //     if ($promoClaim->is_used) {
                //         throw new \Exception('Kode Promo sudah pernah digunakan.');
                //     }
                //     if ($totalAmount < 50000) {
                //         throw new \Exception('Minimum belanja untuk memakai promo ini adalah Rp 50.000');
                //     }

                //     $promoDiscountAmount = min($promoClaim->discount_value, $totalAmount);
                //     $appliedPromoCode = $promoClaim->promo_code;

                //     $promoClaim->update([
                //         'is_used' => true,
                //         'used_at' => now(),
                //     ]);
                // }

                if (! empty($request->promo_code)) {
                    // Pastikan menggunakan $lockedUser->email untuk validasi
                    $promoClaim = PromoClaim::where('email', $lockedUser->email)
                        ->where('promo_code', strtoupper($request->promo_code))
                        ->lockForUpdate()
                        ->first();

                    if (! $promoClaim) {
                        throw new \Exception('Kode Promo tidak valid untuk akun email ini.');
                    }
                    if ($promoClaim->is_used) {
                        throw new \Exception('Kode Promo sudah pernah digunakan.');
                    }

                    // ====================================================================
                    // [PERBAIKAN KRUSIAL] Validasi Minimum Belanja diubah jadi Rp 499.000
                    // Pesan error diubah ke Bahasa Inggris agar rapi di UI Frontend
                    // ====================================================================
                    if ($totalAmount < 499000) {
                        throw new \Exception('Minimum purchase to use this promo is Rp 499.000');
                    }

                    $promoDiscountAmount = min($promoClaim->discount_value, $totalAmount);
                    $appliedPromoCode = $promoClaim->promo_code;

                    $promoClaim->update([
                        'is_used' => true,
                        'used_at' => now(),
                    ]);
                }

                $totalAfterPromo = max(0, $totalAmount - $promoDiscountAmount);

                // =========================================================================
                // 2. POTONG POIN DARI SISA HARGA SETELAH PROMO (Mencegah Tagihan Minus)
                // =========================================================================
                $orderId = 'SOL-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

                // Gunakan $lockedUser, bukan $user dari luar transaksi
                $earnedPoints = $lockedUser->is_membership ? floor($totalAmount / 100000) : 0;

                $pointsUsed = 0;
                $pointDiscountAmount = 0;

                if ($request->use_points > 0 && $lockedUser->is_membership) {
                    // Karena kita menggunakan $lockedUser->point, angkanya dijamin 100% akurat dan terhindar dari double-spending
                    $pointsUsed = min($request->use_points, $lockedUser->point);

                    $maxUsableDiscount = min($pointsUsed * 1000, $totalAfterPromo);
                    $pointDiscountAmount = $maxUsableDiscount;

                    $actualPointsDeducted = floor($maxUsableDiscount / 1000);
                    $pointsUsed = $actualPointsDeducted;

                    if ($pointsUsed > 0) {
                        // Potong poin dari instance yang sudah dilock
                        $lockedUser->decrement('point', $pointsUsed);
                    }
                }

                $totalQuantity = $cartItems->sum('quantity') ?: 1;
                $baseShippingRate = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);
                $totalShippingCost = $baseShippingRate * $totalQuantity;

                // Saat membuat transaksi, gunakan $lockedUser->id
                $transaction = Transaction::create([
                    'user_id' => $lockedUser->id,
                    'address_id' => $request->address_id,
                    // ... (Sisa variabel di array create() ini tetap sama seperti kode Anda) ...
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
                ]);

                $xenditItems = [];
                foreach ($cartItems as $item) {
                    $product = Product::lockForUpdate()->find($item->product_id);
                    if ($product->stock < $item->quantity) {
                        throw new \Exception("Stock {$product->name} insufficient");
                    }

                    $price = $item->product->discount_price ?? $item->product->price;

                    TransactionDetail::create([
                        'transaction_id' => $transaction->id,
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                        'price' => $price,
                        'color' => $item->color, // <--- BARU: Simpan riwayat warna ke tabel transaksi
                    ]);

                    // ... (Logika Potong FIFO Batch Anda tetap sama di sini) ...
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
                        $activeBatches = ProductStock::where('product_id', $product->id)->where('quantity', '>', 0)->orderBy('created_at', 'asc')->lockForUpdate()->get();
                        foreach ($activeBatches as $batch) {
                            if ($remainingQuantityToDeduct <= 0) {
                                break;
                            }
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

                    // [PERBAIKAN XENDIT] Tambahkan informasi warna di struk pembayaran Xendit
                    $productName = $product->name;
                    if (! empty($item->color)) {
                        $productName .= ' - '.$item->color;
                    }

                    $xenditItems[] = [
                        'name' => $productName,
                        'quantity' => $item->quantity,
                        'price' => (int) $price,
                        'category' => 'PHYSICAL_PRODUCT',
                    ];
                }

                // Cart::where('user_id', $user->id)->whereIn('id', $request->cart_ids)->delete();

                return [
                    'transaction' => $transaction,
                    'xenditItems' => $xenditItems,
                    'totalAmount' => $totalAmount,
                    'totalShippingCost' => $totalShippingCost,
                    'pointDiscountAmount' => $pointDiscountAmount,
                    'pointsUsed' => $pointsUsed,
                    'totalQuantity' => $totalQuantity,
                    'promoCode' => $appliedPromoCode,
                    'promoDiscountAmount' => $promoDiscountAmount,
                ];
            }); // <-- DB TRANSACTION SELESAI & LOCK DILEPAS DI SINI!

            // 2. PANGGIL API PIHAK KETIGA DENGAN AMAN DI LUAR TRANSAKSI
            try {
                // $externalId = 'PAY-'.$transactionData['transaction']->order_id;

                // if ($transactionData['pointDiscountAmount'] > 0) {
                //     $transactionData['xenditItems'][] = [
                //         'name' => 'Loyalty Point Discount ('.$transactionData['pointsUsed'].' Pts)',
                //         'quantity' => 1,
                //         'price' => -(int) $transactionData['pointDiscountAmount'],
                //         'category' => 'DISCOUNT',
                //     ];
                // }

                // if ($transactionData['totalShippingCost'] > 0) {
                //     $baseShippingRate = $transactionData['totalShippingCost'] / $transactionData['totalQuantity'];
                //     $transactionData['xenditItems'][] = [
                //         'name' => 'Shipping Cost ('.$request->courier_company.')',
                //         'quantity' => (int) $transactionData['totalQuantity'],
                //         'price' => (int) $baseShippingRate,
                //         'category' => 'SHIPPING_FEE',
                //     ];
                // }

                // $finalAmount = (int) $transactionData['totalAmount'] + $transactionData['totalShippingCost'] - $transactionData['pointDiscountAmount'];

                // $invoiceRequest = new CreateInvoiceRequest([
                //     'external_id' => $externalId,
                //     'payer_email' => $user->email,
                //     'amount' => $finalAmount,
                //     'description' => 'Payment for Order '.$transactionData['transaction']->order_id,
                //     'items' => $transactionData['xenditItems'],
                //     'success_redirect_url' => config('app.frontend_url').'/payment-success?external_id='.$externalId.'&order_id='.$transactionData['transaction']->order_id,
                //     'failure_redirect_url' => config('app.frontend_url').'/payment-failed',
                // ]);

                $externalId = 'PAY-'.$transactionData['transaction']->order_id;

                // [PERBAIKAN 1]: Masukkan Item Diskon Promo ke Xendit
                if (isset($transactionData['promoDiscountAmount']) && $transactionData['promoDiscountAmount'] > 0) {
                    $transactionData['xenditItems'][] = [
                        'name' => 'Promo Code: '.$transactionData['promoCode'],
                        'quantity' => 1,
                        'price' => -(int) $transactionData['promoDiscountAmount'], // Harus Minus
                        'category' => 'DISCOUNT',
                    ];
                }

                if ($transactionData['pointDiscountAmount'] > 0) {
                    $transactionData['xenditItems'][] = [
                        'name' => 'Loyalty Point Discount ('.$transactionData['pointsUsed'].' Pts)',
                        'quantity' => 1,
                        'price' => -(int) $transactionData['pointDiscountAmount'],
                        'category' => 'DISCOUNT',
                    ];
                }

                if ($transactionData['totalShippingCost'] > 0) {
                    $baseShippingRate = $transactionData['totalShippingCost'] / $transactionData['totalQuantity'];
                    $transactionData['xenditItems'][] = [
                        'name' => 'Shipping Cost ('.$request->courier_company.')',
                        'quantity' => (int) $transactionData['totalQuantity'],
                        'price' => (int) $baseShippingRate,
                        'category' => 'SHIPPING_FEE',
                    ];
                }

                // [PERBAIKAN 2]: Kurangi Promo Discount dari Final Amount Xendit
                $finalAmount = (int) $transactionData['totalAmount']
                             + $transactionData['totalShippingCost']
                             - $transactionData['pointDiscountAmount']
                             - ($transactionData['promoDiscountAmount'] ?? 0); // Kurangi Promo di sini!

                // ================================================================
                // [BARU] LOG AUDIT HARGA SEBELUM TERKIRIM KE XENDIT
                // Cek file log di: storage/logs/laravel.log
                // ================================================================
                Log::info('XENDIT INVOICE CALCULATION', [
                    'order_id' => $transactionData['transaction']->order_id,
                    'subtotal_barang' => $transactionData['totalAmount'],
                    'ongkos_kirim' => $transactionData['totalShippingCost'],
                    'diskon_poin' => $transactionData['pointDiscountAmount'],
                    'diskon_promo' => $transactionData['promoDiscountAmount'] ?? 0,
                    'GRAND_TOTAL_FINAL' => $finalAmount,
                    'xendit_items_count' => count($transactionData['xenditItems']),
                ]);

                $invoiceRequest = new CreateInvoiceRequest([
                    'external_id' => $externalId,
                    'payer_email' => $user->email,
                    'amount' => $finalAmount,
                    'description' => 'Payment for Order '.$transactionData['transaction']->order_id,
                    'items' => $transactionData['xenditItems'],
                    'success_redirect_url' => config('app.frontend_url').'/payment-success?external_id='.$externalId.'&order_id='.$transactionData['transaction']->order_id,
                    'failure_redirect_url' => config('app.frontend_url').'/payment-failed',
                ]);

                $api = new InvoiceApi;
                $invoice = $api->createInvoice($invoiceRequest);

                Payment::create([
                    'transaction_id' => $transactionData['transaction']->id,
                    'external_id' => $externalId,
                    'checkout_url' => $invoice['invoice_url'],
                    'amount' => $transactionData['transaction']->total_amount,
                    'status' => 'pending',
                ]);

                // =========================================================================
                // [PERBAIKAN KRITIS]: HAPUS KERANJANG DI SINI!
                // Eksekusi hanya jika Xendit BERHASIL membuat invoice tanpa melempar Exception.
                // =========================================================================
                Cart::where('user_id', $user->id)->whereIn('id', $request->cart_ids)->delete();

                // Cache::tags(['catalog'])->flush();

                foreach ($cartItems as $item) {
                    Cache::tags(['catalog'])->forget("products.detail.{$item->product_id}");
                }

                return response()->json(['checkout_url' => $invoice['invoice_url']], 201);

            } catch (\Exception $e) {
                // Jika Xendit Gagal, Batalkan Transaksi dan kembalikan stok secara manual
                Log::error('Xendit Invoice Creation Failed: '.$e->getMessage());
                app(TransactionController::class)->cancelOrder($request, $transactionData['transaction']->id);

                return response()->json(['message' => 'Payment gateway error. Please try again.'], 500);
            }
        } catch (\Exception $e) {
            // Ini akan memaksa error muncul di laravel.log jika terjadi kegagalan
            Log::error('CHECKOUT FATAL ERROR: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['message' => 'Internal Server Error: '.$e->getMessage()], 500);
        }
    }

    // public function index(Request $request)
    // {
    //     // Eager load 'payment' untuk mendapatkan checkout_url
    //     $transactions = Transaction::with(['details.product', 'payment', 'address'])
    //         ->where('user_id', $request->user()->id)
    //         ->latest()
    //         ->get();

    //     return response()->json($transactions);
    // }

    public function index(Request $request)
    {
        $transactions = Transaction::with(['details.product', 'payment', 'address'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20); // User cukup lihat 20 transaksi per halaman

        return response()->json($transactions);
    }

    // Melihat semua transaksi (Sisi Admin)
    // public function allTransactions()
    // {
    //     // Menambahkan relasi 'address' agar data penerima dan kodepos bisa dirender di Vue
    //     $transactions = Transaction::with(['user', 'details.product', 'address'])
    //         ->latest()
    //         ->get();

    //     return response()->json($transactions);
    // }

    public function allTransactions()
    {
        // Hanya ambil 50 data per halaman. Sangat ringan untuk RAM server!
        $transactions = Transaction::with(['user', 'details.product', 'address'])
            ->latest()
            ->paginate(50);

        return response()->json($transactions);
    }

    public function cancelOrder(Request $request, $id)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

        if (! in_array($transaction->status, ['awaiting_payment', 'pending', 'processing'])) {
            return response()->json(['message' => 'Cannot cancel this order.'], 400);
        }

        // PRE-CHECK BITESHIP (Berjalan di luar transaksi database agar tidak memberatkan server)
        if ($transaction->status === 'processing' && $transaction->shipping_method === 'biteship' && ! empty($transaction->biteship_order_id)) {
            try {
                $res = Http::withHeaders([
                    'Authorization' => config('services.biteship.api_key'),
                ])->get('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);

                if ($res->successful()) {
                    $data = $res->json();
                    $biteshipStatus = strtolower($data['status'] ?? '');

                    $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'return_in_transit', 'returned', 'disposed'];
                    if (in_array($biteshipStatus, $unCancellableStatuses)) {
                        return response()->json([
                            'message' => 'Cannot cancel: The package is already being processed by the courier.',
                        ], 400);
                    }

                    Http::withHeaders([
                        'Authorization' => config('services.biteship.api_key'),
                    ])->delete('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);
                }
            } catch (\Exception $e) {
                return response()->json(['message' => 'Failed to verify logistics status with Biteship.'], 500);
            }

            // AUTO-REFUND XENDIT
            try {
                $transaction->load('payment');
                if ($transaction->payment && $transaction->payment->external_id) {
                    $invoiceApi = new InvoiceApi;
                    $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

                    if (! empty($invoices) && count($invoices) > 0) {
                        $xenditInvoiceId = $invoices[0]['id'];
                        $refundApi = new RefundApi;

                        $refundRequest = new CreateRefund([
                            'invoice_id' => $xenditInvoiceId,
                            'reason' => 'REQUESTED_BY_CUSTOMER',
                            'amount' => (int) $transaction->total_amount,
                            'metadata' => ['order_id' => $transaction->order_id],
                        ]);

                        $refundApi->createRefund(null, null, $refundRequest);
                    }
                }
            } catch (\Exception $e) {
                // JIKA REFUND GAGAL (TAPI KURIR SUDAH DIBATALKAN), LEMPAR KE REFUND MANUAL TAPI KEMBALIKAN STOKNYA
                DB::transaction(function () use ($transaction) {
                    $transaction->update(['status' => 'refund_manual_required']);
                    foreach ($transaction->details as $detail) {
                        // [PERBAIKAN] Mengembalikan stok pakai FIFO Restore
                        $this->restoreProductStock($detail->product_id, $detail->quantity);
                    }
                });

                return response()->json(['message' => 'Order cancelled, but automatic refund failed. Admin will process it manually.']);
            }
        }

        // [PENTING] Bungkus pembatalan status dan pengembalian stok dalam DB Transaction
        DB::transaction(function () use ($transaction) {
            // Re-fetch dan Lock untuk mencegah error paralel
            $lockedTransaction = Transaction::lockForUpdate()->find($transaction->id);

            if ($lockedTransaction->status !== 'refund_manual_required' && $lockedTransaction->status !== 'cancelled') {
                $lockedTransaction->update([
                    'status' => 'cancelled',
                    'shipping_status' => 'cancelled', // [PERBAIKAN] Sinkronisasi status pengiriman
                ]);

                // [PERBAIKAN] KEMBALIKAN POIN YANG HANGUS
                if ($lockedTransaction->points_used > 0) {
                    $lockedTransaction->user->increment('point', $lockedTransaction->points_used);
                }

                // [BARU] KEMBALIKAN PROMO CODE JIKA TRANSAKSI BATAL
                if ($lockedTransaction->promo_code) {
                    PromoClaim::where('email', $lockedTransaction->user->email)
                        ->where('promo_code', $lockedTransaction->promo_code)
                        ->update(['is_used' => false, 'used_at' => null]);
                }

                if ($lockedTransaction->payment) {
                    $lockedTransaction->payment->update(['status' => 'EXPIRED']);
                }

                // [PERBAIKAN] Mengembalikan stok pakai FIFO Restore
                foreach ($lockedTransaction->details as $detail) {
                    $this->restoreProductStock($detail->product_id, $detail->quantity);
                }
            }
        });

        // Cache::tags(['catalog'])->flush();

        foreach ($transaction->details as $detail) {
            Cache::tags(['catalog'])->forget("products.detail.{$detail->product_id}");
        }

        return response()->json(['message' => 'Order cancelled successfully']);
    }

    // public function confirmComplete(Request $request, $id)
    // {
    //     $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

    //     if ($transaction->status !== 'processing') {
    //         return response()->json(['message' => 'Order cannot be completed yet.'], 400);
    //     }

    //     $transaction->update(['status' => 'completed']);

    //     // [PERBAIKAN] Cek syarat membership setelah admin komplit manual
    //     $this->checkAndAssignMembership($transaction->user);

    //     return response()->json(['message' => 'Order completed!']);
    // }

    public function confirmComplete(Request $request, $id)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

        if ($transaction->status !== 'processing') {
            return response()->json(['message' => 'Order cannot be completed yet.'], 400);
        }

        $transaction->update(['status' => 'completed']);
        $this->checkAndAssignMembership($transaction->user);

        // [PERBAIKAN MUTLAK] Jangan lupakan poin pelanggan yang menyelesaikan pesanan manual!
        $transaction->user->refresh();
        if ($transaction->point > 0 && $transaction->user->is_membership) {
            $transaction->user->increment('point', $transaction->point);
        }

        return response()->json(['message' => 'Order completed!']);
    }

    public function requestRefund(Request $request, $id)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

        // Validasi: Refund hanya bisa diajukan saat pesanan selesai atau gagal kirim
        if (! in_array($transaction->status, ['completed', 'shipping_failed'])) {
            return response()->json(['message' => 'Cannot request refund for this order state.'], 400);
        }

        // [BARU] Validasi input text dan file bukti (gambar atau video)
        $request->validate([
            'reason' => 'required|string|max:1000',
            'proof_file' => 'required|file|mimes:jpeg,png,jpg,mp4,mov|max:10240', // Max 10MB
        ]);

        try {
            // [BARU] Upload file ke AWS S3
            $file = $request->file('proof_file');
            $path = $file->store('refund_proofs', [
                'disk' => 's3',
                'visibility' => 'public',
            ]);
            $proofUrl = Storage::disk('s3')->url($path);

            // Update transaksi
            $transaction->update([
                'status' => 'refund_requested',
                'refund_reason' => $request->reason,
                'refund_proof_url' => $proofUrl,
            ]);

            return response()->json(['message' => 'Refund requested successfully. Waiting for admin approval.']);

        } catch (\Exception $e) {
            Log::error('Failed to upload refund proof: '.$e->getMessage());

            return response()->json(['message' => 'Failed to process refund request. Please try again.'], 500);
        }
    }

    // User klik "Refund Now" setelah disetujui admin
    public function processRefundUser(Request $request, $id)
    {
        // 1. Ambil data transaksi (Tanpa Lock terlebih dahulu)
        $transaction = Transaction::with('payment')
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);

        // =========================================================================
        // [PERBAIKAN] ATOMIC STATE TRANSITION (Pencegah Double Refund)
        // Kita paksa ubah statusnya di database SEBELUM memanggil API Xendit.
        // Jika ada 2 request masuk bersamaan, request kedua akan menghasilkan $locked = 0 (Gagal)
        // =========================================================================
        $locked = Transaction::where('id', $id)
            ->where('status', 'refund_approved')
            ->update(['status' => 'refund_processing']); // Status sementara

        if (! $locked) {
            return response()->json(['message' => 'Refund is already being processed or not valid.'], 400);
        }

        if (! $transaction->payment) {
            // Rollback status karena gagal
            $transaction->update(['status' => 'refund_approved']);

            return response()->json(['message' => 'Payment data not found.'], 404);
        }

        // --- PRE-CHECK DAN EKSEKUSI PEMBATALAN KURIR ---
        if ($transaction->shipping_method === 'biteship' && ! empty($transaction->biteship_order_id)) {
            try {
                $res = Http::withHeaders([
                    'Authorization' => config('services.biteship.api_key'),
                ])->get('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);

                if ($res->successful()) {
                    $data = $res->json();
                    $biteshipStatus = strtolower($data['status'] ?? '');

                    $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'rejected', 'return_in_transit', 'returned'];

                    if (in_array($biteshipStatus, $unCancellableStatuses)) {
                        // Rollback status karena kurir sudah jalan
                        $transaction->update(['status' => 'refund_approved']);

                        return response()->json([
                            'message' => 'Cannot process refund: The package is already in transit or has issues. Please contact logistics.',
                        ], 400);
                    }

                    // JIKA AMAN, BATALKAN KURIR
                    if (! in_array($biteshipStatus, ['cancelled'])) {
                        $cancelRes = Http::withHeaders([
                            'Authorization' => config('services.biteship.api_key'),
                        ])->delete('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);

                        $cancelData = $cancelRes->json();
                        if (isset($cancelData['success']) && $cancelData['success'] === false) {
                            $transaction->update(['status' => 'refund_approved']); // Rollback

                            return response()->json([
                                'message' => 'Failed to cancel courier. Refund aborted to prevent loss.',
                            ], 400);
                        }
                    }
                }
            } catch (\Exception $e) {
                $transaction->update(['status' => 'refund_approved']); // Rollback
                Log::error('Biteship Pre-Check Error: '.$e->getMessage());

                return response()->json(['message' => 'Failed to verify logistics status. Try again later.'], 500);
            }
        }

        // --- EKSEKUSI REFUND KE XENDIT ---
        try {
            $invoiceApi = new InvoiceApi;
            $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

            if (empty($invoices) || count($invoices) === 0) {
                throw new \Exception('Invoice not found in Xendit.');
            }

            $xenditInvoiceId = $invoices[0]['id'];
            $refundApi = new RefundApi;

            $refundRequest = new CreateRefund([
                'invoice_id' => $xenditInvoiceId,
                'reason' => 'REQUESTED_BY_CUSTOMER',
                'amount' => (int) $transaction->total_amount,
                'metadata' => ['order_id' => $transaction->order_id],
            ]);

            $refundApi->createRefund(null, null, $refundRequest);

            // Jika Xendit sukses, update ke status Akhir (Refunded)
            DB::transaction(function () use ($transaction) {
                $transaction->update(['status' => 'refunded']);
                if ($transaction->payment) {
                    $transaction->payment->update(['status' => 'REFUNDED']);
                }

                // // Pengembalian poin yang dipakai ada di Fix Bencana 2 di bawah

                // foreach ($transaction->details as $detail) {
                //     $this->restoreProductStock($detail->product_id, $detail->quantity);
                // }

                // [PERBAIKAN MUTLAK: ANTI DOUBLE RESTOCK]
                // Hanya kembalikan stok jika belum pernah dibatalkan sebelumnya
                // Jika pesanan gagal dari processing langsung refund, kita restore.
                // TAPI jika sebelumnya sudah refund_manual_required/cancelled, stok SUDAH KEMBALI.
                $statusesThatAlreadyRestoredStock = ['refund_manual_required', 'cancelled', 'shipping_failed', 'returned'];

                // Gunakan status dari instance sebelum diupdate (karena di atas sudah diupdate ke 'refunded')
                $originalStatus = $transaction->getOriginal('status');

                if (! in_array($originalStatus, $statusesThatAlreadyRestoredStock)) {
                    foreach ($transaction->details as $detail) {
                        $this->restoreProductStock($detail->product_id, $detail->quantity);
                    }
                }
            });

            // Cache::tags(['catalog'])->flush();

            foreach ($transaction->details as $detail) {
                Cache::tags(['catalog'])->forget("products.detail.{$detail->product_id}");
            }

            return response()->json([
                'message' => 'Refund processed successfully. Funds returned automatically.',
                'type' => 'automatic',
            ]);
        } catch (XenditSdkException $e) {
            $errorMessage = $e->getMessage();

            if (str_contains(strtolower($errorMessage), 'not supported for this channel')) {
                DB::transaction(function () use ($transaction) {
                    $transaction->update(['status' => 'refund_manual_required']);
                    foreach ($transaction->details as $detail) {
                        $this->restoreProductStock($detail->product_id, $detail->quantity);
                    }
                });

                // Cache::tags(['catalog'])->flush();

                foreach ($transaction->details as $detail) {
                    Cache::tags(['catalog'])->forget("products.detail.{$detail->product_id}");
                }

                return response()->json([
                    'message' => 'Automatic refund not supported. Status updated to Manual Check. Courier has been cancelled.',
                    'code' => 'MANUAL_REFUND_NEEDED',
                ], 200);
            }

            $transaction->update(['status' => 'refund_approved']); // Rollback

            return response()->json(['message' => 'Xendit Refund Failed: '.$errorMessage], 422);
        } catch (\Exception $e) {
            $transaction->update(['status' => 'refund_approved']); // Rollback

            return response()->json(['message' => 'Refund Error: '.$e->getMessage()], 500);
        }
    }

    public function approveRefund($id)
    {
        // [PERBAIKAN] Tambahkan with('user') agar kita bisa membaca alamat emailnya
        $transaction = Transaction::with('user')->findOrFail($id);

        if ($transaction->status !== 'refund_requested') {
            return response()->json(['message' => 'Invalid status'], 400);
        }

        $transaction->update(['status' => 'refund_approved']);

        // [BARU] Kirim notifikasi email ke user
        try {
            Mail::to($transaction->user->email)->send(new RefundResultMail($transaction, 'approve'));
        } catch (\Exception $e) {
            // Jika gagal kirim email, jangan hentikan proses approve
            Log::error("Gagal kirim email Approve Refund ke {$transaction->user->email}: ".$e->getMessage());
        }

        return response()->json(['message' => 'Refund request approved. Email sent to customer.']);
    }

    // public function rejectRefund($id)
    // {
    //     $transaction = Transaction::findOrFail($id);
    //     if ($transaction->status !== 'refund_requested') {
    //         return response()->json(['message' => 'Invalid status'], 400);
    //     }

    //     $transaction->update(['status' => 'refund_rejected']);
    //     return response()->json(['message' => 'Refund request rejected.']);
    // }

    public function rejectRefund($id)
    {
        // [PERBAIKAN] Tambahkan with('user') agar kita bisa membaca alamat emailnya
        $transaction = Transaction::with('user')->findOrFail($id);

        if ($transaction->status !== 'refund_requested') {
            return response()->json(['message' => 'Invalid status'], 400);
        }

        $transaction->update(['status' => 'refund_rejected']);

        // [BARU] Kirim notifikasi email ke user
        try {
            Mail::to($transaction->user->email)->send(new RefundResultMail($transaction, 'reject'));
        } catch (\Exception $e) {
            // Jika gagal kirim email, jangan hentikan proses reject
            Log::error("Gagal kirim email Reject Refund ke {$transaction->user->email}: ".$e->getMessage());
        }

        return response()->json(['message' => 'Refund request rejected. Email sent to customer.']);
    }

    // Show single transaction
    public function show($id)
    {
        return response()->json(Transaction::with(['user', 'details.product', 'payment', 'address'])->findOrFail($id));
    }

    public function adminShow($id)
    {
        // Mengambil transaksi dengan relasi user, detail, dan produk di dalam detail
        $transaction = Transaction::with(['user', 'details.product', 'address', 'payment'])
            ->findOrFail($id);

        return response()->json($transaction);
    }

    public function salesReport(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');
        $search = $request->query('search');

        $query = TransactionDetail::query()
            ->select(
                'products.id',
                'products.code',
                'products.name',
                'products.image',
                'categories.name as category_name',
                DB::raw('SUM(transaction_details.quantity) as total_sold'),
                DB::raw('SUM(transaction_details.quantity * transaction_details.price) as total_revenue')
            )
            ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
            ->join('products', 'products.id', '=', 'transaction_details.product_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->whereIn('transactions.status', ['completed', 'refund_rejected']);

        if ($month && $year) {
            $query->whereMonth('transactions.created_at', $month)
                ->whereYear('transactions.created_at', $year);
        } elseif ($year) {
            $query->whereYear('transactions.created_at', $year);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('products.name', 'like', "%{$search}%")
                    ->orWhere('products.code', 'like', "%{$search}%");
            });
        }

        // [PERBAIKAN] Gunakan get() alih-alih paginate() untuk memberikan seluruh data ke Vue
        $report = $query->groupBy('products.id', 'products.code', 'products.name', 'products.image', 'categories.name')
            ->orderByDesc('total_revenue')
            ->get();

        return response()->json([
            'data' => $report, // Format ini kita pertahankan agar Frontend tetap konsisten mengambil res.data.data
        ]);
    }

    public function trackOrder($id)
    {
        $transaction = Transaction::where('user_id', request()->user()->id)->findOrFail($id);

        // [PERBAIKAN] Validasi menggunakan biteship_order_id
        if ($transaction->shipping_method !== 'biteship' || ! $transaction->biteship_order_id) {
            return response()->json(['message' => 'Tracking information is not available yet.'], 400);
        }

        try {
            // [PERBAIKAN] Memanggil Endpoint GET Order Biteship
            $response = Http::withHeaders([
                'Authorization' => config('services.biteship.api_key'),
            ])->get('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);

            $data = $response->json();

            if (isset($data['success']) && $data['success'] === false) {
                return response()->json(['message' => $data['error'] ?? 'Order not found in Logistics'], 400);
            }

            // Kembalikan seluruh objek respon JSON dari Biteship ke Frontend
            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to retrieve tracking data: '.$e->getMessage()], 500);
        }
    }

    public function bulkTrackOrders(Request $request)
    {
        $request->validate([
            'transaction_ids' => 'required|array',
            'transaction_ids.*' => 'integer|exists:transactions,id',
        ]);

        // 1. Ambil data transaksi HANYA dengan 1 kali query ke Database (1 Koneksi DB)
        $transactions = Transaction::where('user_id', $request->user()->id)
            ->whereIn('id', $request->transaction_ids)
            ->whereNotNull('biteship_order_id')
            ->where('shipping_method', 'biteship')
            ->get();

        $trackingData = [];

        // 2. Looping untuk menembak API Biteship satu per satu di sisi Backend
        foreach ($transactions as $transaction) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => config('services.biteship.api_key'),
                ])->get('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);

                if (isset($response['success']) && $response['success'] === true) {
                    $trackingData[$transaction->id] = $response->json();
                } else {
                    $trackingData[$transaction->id] = ['status' => 'pending']; // Fallback jika belum teralokasi
                }
            } catch (\Exception $e) {
                // Jangan gagalkan seluruh request jika 1 order error di sisi Biteship
                $trackingData[$transaction->id] = ['status' => 'error fetching data'];
            }
        }

        // 3. Kembalikan data dalam bentuk Key-Value (ID Transaksi => Data Biteship)
        return response()->json($trackingData);
    }

    // Fungsi khusus Admin: Mengambil semua tracking tanpa filter user_id
    // public function adminBulkTrackOrders(Request $request)
    // {
    //     $request->validate([
    //         'transaction_ids' => 'required|array',
    //         'transaction_ids.*' => 'integer|exists:transactions,id',
    //     ]);

    //     // HAPUS filter ->where('user_id') agar Admin bisa melihat semua pesanan
    //     $transactions = Transaction::whereIn('id', $request->transaction_ids)
    //         ->whereNotNull('biteship_order_id')
    //         ->where('shipping_method', 'biteship')
    //         ->get();

    //     $trackingData = [];

    //     foreach ($transactions as $transaction) {
    //         try {
    //             $response = Http::withHeaders([
    //                 'Authorization' => config('services.biteship.api_key'),
    //             ])->get('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);

    //             if (isset($response['success']) && $response['success'] === true) {
    //                 $trackingData[$transaction->id] = $response->json();
    //             } else {
    //                 $trackingData[$transaction->id] = ['status' => 'pending'];
    //             }
    //         } catch (\Exception $e) {
    //             $trackingData[$transaction->id] = ['status' => 'error fetching data'];
    //         }
    //     }

    //     return response()->json($trackingData);
    // }

    public function adminBulkTrackOrders(Request $request)
    {
        $request->validate([
            'transaction_ids' => 'required|array',
            'transaction_ids.*' => 'integer|exists:transactions,id',
        ]);

        // Batasi maksimal 20 tracking sekaligus agar API Biteship tidak memblokir Anda (Rate Limiting)
        if (count($request->transaction_ids) > 20) {
            return response()->json(['message' => 'Maksimal tracking massal adalah 20 pesanan sekaligus.'], 422);
        }

        $transactions = Transaction::whereIn('id', $request->transaction_ids)
            ->whereNotNull('biteship_order_id')
            ->where('shipping_method', 'biteship')
            ->get();

        if ($transactions->isEmpty()) {
            return response()->json([]);
        }

        // [PERBAIKAN KRITIS] Tembak API Biteship secara PARALEL (Bersamaan)
        $responses = Http::pool(function (Pool $pool) use ($transactions) {
            foreach ($transactions as $transaction) {
                $pool->as($transaction->id)
                    ->withHeaders(['Authorization' => config('services.biteship.api_key')])
                    ->get('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);
            }
        });

        $trackingData = [];

        // Rangkai hasil balasannya
        foreach ($transactions as $transaction) {
            $response = $responses[$transaction->id] ?? null;

            if ($response && $response->ok() && isset($response['success']) && $response['success'] === true) {
                $trackingData[$transaction->id] = $response->json();
            } else {
                $trackingData[$transaction->id] = ['status' => 'pending/error'];
            }
        }

        return response()->json($trackingData);
    }

    // Fungsi khusus Admin untuk mengambil detail tracking 1 order
    public function adminTrackOrder($id)
    {
        $transaction = Transaction::findOrFail($id); // HAPUS filter user_id

        if ($transaction->shipping_method !== 'biteship' || ! $transaction->biteship_order_id) {
            return response()->json(['message' => 'Tracking information is not available yet.'], 400);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => config('services.biteship.api_key'),
            ])->get('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);

            $data = $response->json();

            if (isset($data['success']) && $data['success'] === false) {
                return response()->json(['message' => $data['error'] ?? 'Order not found in Logistics'], 400);
            }

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to retrieve tracking data: '.$e->getMessage()], 500);
        }
    }

    public function printLabel(Request $request, $id)
    {
        $transaction = Transaction::findOrFail($id);

        if (! $transaction->biteship_order_id) {
            return response()->json(['message' => 'Order ID Biteship tidak ditemukan'], 404);
        }

        // Ambil query parameter dari Vue (insurance_shown, dll)
        $queryString = http_build_query($request->all());

        // Target URL Biteship (Perhatikan ini menggunakan api.biteship.com, BUKAN biteship.com)
        $biteshipUrl = "https://api.biteship.com/v1/orders/{$transaction->biteship_order_id}/labels?{$queryString}";

        try {
            // Tembak URL label Biteship dengan API Key kita
            $response = Http::withHeaders([
                'Authorization' => config('services.biteship.api_key'),
            ])->get($biteshipUrl);

            // Jika sukses, Biteship biasanya mengembalikan langsung file PDF (application/pdf)
            if ($response->successful()) {
                return response($response->body(), 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'inline; filename="Resi-'.$transaction->order_id.'.pdf"');
            }

            return response()->json(['message' => 'Gagal mengambil resi dari Biteship: '.$response->body()], 400);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Terjadi kesalahan sistem: '.$e->getMessage()], 500);
        }
    }

    public function biteshipCallback(Request $request)
    {
        // Validasi signature (Opsional tapi disarankan)
        // $signature = $request->header('biteship-signature');
        // $secret = config('services.biteship.webhook_secret'); // Tambahkan di config/services.php dan .env

        // if ($signature !== $secret) {
        //     Log::critical('Fake Biteship Webhook Detected!', $request->all());

        //     return response()->json(['message' => 'Forbidden'], 403);
        // }

        $biteshipOrderId = $request->input('order_id');
        $status = strtolower($request->input('status')); // picking_up, dropped, delivered, cancelled, rejected, dll
        $waybill = $request->input('courier_waybill_id');

        \Log::info('Biteship Webhook Received: ', $request->all());

        // [PERBAIKAN MUTLAK: DB TRANSACTION & LOCKING]
        return DB::transaction(function () use ($biteshipOrderId, $status, $waybill) {

            // $transaction = Transaction::where('biteship_order_id', $biteshipOrderId)->first();
            // Kunci baris ini agar webhook yang datang bersamaan harus antre!
            $transaction = Transaction::where('biteship_order_id', $biteshipOrderId)
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                return response()->json(['message' => 'Transaction not found'], 200);
            }

            // Mencegah proses ulang jika status sudah 'completed'
            if ($transaction->status === 'completed' && $status === 'delivered') {
                return response()->json(['message' => 'Already completed'], 200);
            }

            // [PERBAIKAN UTAMA] Selalu update shipping_status terbaru dari Webhook!
            $updates = ['shipping_status' => $status];

            // 1. Update Resi jika baru turun
            if ($waybill && in_array($transaction->tracking_number, ['Pending', null])) {
                $updates['tracking_number'] = $waybill;
            }

            // 2. Jika paket berhasil dikirim ke pembeli, otomatis selesaikan transaksi
            if ($status === 'delivered' && $transaction->status === 'processing') {
                $updates['status'] = 'completed';

                // Simpan status transaksi agar query SUM di helper bisa menangkap transaksi ini
                $transaction->update($updates);

                // [PERBAIKAN] Cek dan jadikan member jika memenuhi syarat
                $this->checkAndAssignMembership($transaction->user);

                // Refresh data user
                $transaction->user->refresh();

                // Tambah poin user jika dia member dan transaksi punya poin
                if ($transaction->point > 0 && $transaction->user->is_membership) {
                    $transaction->user->increment('point', $transaction->point);
                }

                return response()->json(['message' => 'Webhook processed and membership checked']);
            }

            // 3. Jika logistik membatalkan pengiriman SEPIHAK
            if (in_array($status, ['cancelled', 'rejected']) && $transaction->status === 'processing') {
                $updates['status'] = 'refund_manual_required';
                $updates['tracking_number'] = 'Logistics Cancelled/Rejected';
                \Log::warning("Biteship Logistics Cancelled for Order ID: {$transaction->order_id}. Moved to Manual Refund.");
            }

            if ($status === 'disposed' && $transaction->status === 'processing') {
                $updates['status'] = 'shipping_failed';
                $updates['tracking_number'] = 'Shipping Failed';
                \Log::warning("Biteship Shipping Failed for Order ID: {$transaction->order_id}.");
            }

            if ($status === 'returned' && $transaction->status === 'processing') {
                $updates['status'] = 'returned';
                $updates['tracking_number'] = 'Shipping Returned';
                \Log::warning("Biteship Shipping Returned for Order ID: {$transaction->order_id}.");
            }

            // Eksekusi semua update ke database dalam 1 query
            $transaction->update($updates);

            return response()->json(['message' => 'Webhook processed successfully']);
        });
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
