<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\User;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PromoClaim;
use App\Models\Transaction;
use Illuminate\Support\Str;
use App\Models\ProductStock;
use Illuminate\Http\Request;
use Xendit\Refund\RefundApi;
use App\Mail\RefundResultMail;
use Xendit\XenditSdkException;
use Xendit\Refund\CreateRefund;
use App\Services\PaymentFactory;
use Illuminate\Http\Client\Pool;
use App\Models\TransactionDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
// use Xendit\Configuration;
// use Xendit\Invoice\CreateInvoiceRequest;
// use Xendit\Invoice\InvoiceApi;
use App\Jobs\SendShippingUpdateJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Events\ShippingStatusUpdated;
use App\Services\PromoMerdekaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use App\Actions\Checkout\DeductInventoryAction;
use App\Actions\Checkout\CreateTransactionAction;
use App\Actions\Checkout\CalculateCartTotalsAction;

class TransactionController extends Controller
{
    // public function __construct()
    // {
    //     Configuration::setXenditKey(config('services.xendit.secret_key'));
    // }

    // =================================================================================
    // [BARU] HELPER FUNGSI UNTUK MENGEMBALIKAN STOK (FIFO RESTORE & ANTI RACE CONDITION)
    // =================================================================================

    // =========================================================================
    // HELPER FUNCTIONS (Prinsip DRY - Don't Repeat Yourself)
    // =========================================================================

    // 1. Membersihkan Cache Produk
    private function clearTransactionProductCache(Transaction $transaction)
    {
        foreach ($transaction->details as $detail) {
            Cache::tags(['catalog'])->forget("products.detail.{$detail->product_id}");
        }
    }

    // 2. Cek Naik Level Member
    private function checkAndAssignMembership(User $user)
    {
        if ($user->is_membership)
            return;
        $totalSpent = Transaction::where('user_id', $user->id)->where('status', 'completed')->sum('total_amount');
        if ($totalSpent >= 100000) {
            $user->update(['is_membership' => true]);
        }
    }

    // 3. Cek Turun Level Member (Jika ada pembatalan / Refund)
    private function revokeMembershipIfBelowThreshold(User $user)
    {
        if (!$user->is_membership)
            return;
        $totalSpent = Transaction::where('user_id', $user->id)->where('status', 'completed')->sum('total_amount');
        if ($totalSpent < 100000) {
            $user->update(['is_membership' => false]);
        }
    }

    public function restoreProductStock($productId, $quantityToRestore)
    {
        if ($quantityToRestore <= 0) {
            return;
        }

        // 1. Kunci (Lock) baris produk utama untuk mencegah modifikasi berbarengan
        $product = Product::lockForUpdate()->find($productId);
        if (!$product) {
            return;
        }

        $remainingToRestore = $quantityToRestore;

        // 2. Ambil batch stok yang TIDAK PENUH (quantity < initial_quantity)
        // Urutkan dari yang PALING LAMA (ASC) untuk mengembalikan secara FIFO
        $incompleteBatches = ProductStock::where('product_id', $productId)
            ->whereColumn('quantity', '<', 'initial_quantity')
            ->orderBy('created_at', 'asc')
            ->lockForUpdate()  // Kunci baris batch ini selama transaksi berlangsung
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
                    'batch_code' => 'RET-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4)),
                    'quantity' => $remainingToRestore,
                    'initial_quantity' => $remainingToRestore,
                ]);
            }
        }

        // 4. Kembalikan total stok di tabel master
        $product->increment('stock', $quantityToRestore);
    }

    // --- USER ACTIONS ---
    // public function checkout(Request $request)
    // {
    //     try { // <--- Bungkus dengan Try
    //         // ... (Validasi request tetap sama) ...
    //         $request->validate([
    //             'address_id' => 'required',
    //             'shipping_method' => 'required|in:free,biteship',
    //             'use_points' => 'nullable|integer|min:0',
    //             'cart_ids' => 'required|array',
    //             'cart_ids.*' => 'exists:carts,id',
    //             'shipping_cost' => 'nullable|numeric',
    //             'courier_company' => 'nullable|string',
    //             'courier_type' => 'nullable|string',
    //             'delivery_type' => 'nullable|string',
    //         ]);

    //         $user = $request->user();
    //         $cartItems = Cart::with('product')
    //             ->where('user_id', $user->id)
    //             ->whereIn('id', $request->cart_ids)
    //             ->get();

    //         if ($cartItems->isEmpty()) {
    //             return response()->json(['message' => 'No items selected for checkout'], 400);
    //         }

    //         // 1. LAKUKAN PROSES DATABASE DENGAN KILAT (TANPA API PIHAK KETIGA)
    //         $transactionData = DB::transaction(function () use ($user, $cartItems, $request) {

    //             // =========================================================================
    //             // [PERBAIKAN KRITIS]: Kunci data User spesifik ini selama proses checkout.
    //             // Jika ada 2 request masuk bersamaan, request kedua akan disuruh antre
    //             // menunggu request pertama selesai memotong poin.
    //             // =========================================================================
    //             $lockedUser = User::lockForUpdate()->find($user->id);

    //             $totalAmount = 0;
    //             foreach ($cartItems as $item) {
    //                 $currentPrice = $item->product->discount_price ?? $item->product->price;
    //                 $totalAmount += ($currentPrice * $item->quantity);
    //             }

    //             // // =========================================================================
    //             // // [LOGIKA BARU] 1. POTONG PROMO CODE TERLEBIH DAHULU (ZERO-FLOOR)
    //             // // =========================================================================
    //             // $promoDiscountAmount = 0;
    //             // $appliedPromoCode = null;

    //             // // if (! empty($request->promo_code)) {
    //             // //     // Pastikan menggunakan $lockedUser->email untuk validasi
    //             // //     $promoClaim = PromoClaim::where('email', $lockedUser->email)
    //             // //         ->where('promo_code', strtoupper($request->promo_code))
    //             // //         ->lockForUpdate()
    //             // //         ->first();

    //             // //     if (! $promoClaim) {
    //             // //         throw new \Exception('Kode Promo tidak valid untuk akun email ini.');
    //             // //     }
    //             // //     if ($promoClaim->is_used) {
    //             // //         throw new \Exception('Kode Promo sudah pernah digunakan.');
    //             // //     }
    //             // //     if ($totalAmount < 50000) {
    //             // //         throw new \Exception('Minimum belanja untuk memakai promo ini adalah Rp 50.000');
    //             // //     }

    //             // //     $promoDiscountAmount = min($promoClaim->discount_value, $totalAmount);
    //             // //     $appliedPromoCode = $promoClaim->promo_code;

    //             // //     $promoClaim->update([
    //             // //         'is_used' => true,
    //             // //         'used_at' => now(),
    //             // //     ]);
    //             // // }

    //             // if (! empty($request->promo_code)) {
    //             //     // Pastikan menggunakan $lockedUser->email untuk validasi
    //             //     $promoClaim = PromoClaim::where('email', $lockedUser->email)
    //             //         ->where('promo_code', strtoupper($request->promo_code))
    //             //         ->lockForUpdate()
    //             //         ->first();

    //             //     if (! $promoClaim) {
    //             //         throw new \Exception('Kode Promo tidak valid untuk akun email ini.');
    //             //     }
    //             //     if ($promoClaim->is_used) {
    //             //         throw new \Exception('Kode Promo sudah pernah digunakan.');
    //             //     }

    //             //     // ====================================================================
    //             //     // [PERBAIKAN KRUSIAL] Validasi Minimum Belanja diubah jadi Rp 499.000
    //             //     // Pesan error diubah ke Bahasa Inggris agar rapi di UI Frontend
    //             //     // ====================================================================
    //             //     if ($totalAmount < 499000) {
    //             //         throw new \Exception('Minimum purchase to use this promo is Rp 499.000');
    //             //     }

    //             //     $promoDiscountAmount = min($promoClaim->discount_value, $totalAmount);
    //             //     $appliedPromoCode = $promoClaim->promo_code;

    //             //     $promoClaim->update([
    //             //         'is_used' => true,
    //             //         'used_at' => now(),
    //             //     ]);
    //             // }

    //             // $totalAfterPromo = max(0, $totalAmount - $promoDiscountAmount);

    //             // =========================================================================
    //             // [LOGIKA BARU] 1. POTONG PROMO CODE TERLEBIH DAHULU (ZERO-FLOOR)
    //             // =========================================================================
    //             $promoDiscountAmount = 0;
    //             $appliedPromoCode = null;

    //             if (! empty($request->promo_code)) {
    //                 $promoCode = strtoupper(trim($request->promo_code));

    //                 // --- [OPSI C] LOGIKA KHUSUS KODE UNIVERSAL (SOLHERMEMBER) ---
    //                 if ($promoCode === 'SOLHERMEMBER') {
    //                     // 1. Validasi Status Member
    //                     if (! $lockedUser->is_membership) {
    //                         throw new \Exception('Voucher ini eksklusif hanya untuk pengguna dengan status VIP Member.');
    //                     }

    //                     // 2. Validasi Limit Penggunaan (Satu Kali Seumur Hidup)
    //                     if ($lockedUser->has_used_member_voucher) {
    //                         throw new \Exception('Anda sudah pernah menggunakan voucher member VIP ini sebelumnya.');
    //                     }

    //                     // 3. Validasi Minimum Belanja (Rp 1.000.000)
    //                     if ($totalAmount < 1000000) {
    //                         throw new \Exception('Minimum purchase to use VIP Member Voucher is Rp 1.000.000');
    //                     }

    //                     // Jika lolos semua, berikan diskon 500rb
    //                     $promoDiscountAmount = 500000;
    //                     $appliedPromoCode = 'SOLHERMEMBER';

    //                     // Tandai bahwa user sudah memakai voucher ini agar tidak bisa dipakai lagi besok!
    //                     $lockedUser->update([
    //                         'has_used_member_voucher' => true,
    //                     ]);
    //                 }
    //                 // --- LOGIKA KODE PROMO LAMA (JIKA ADA) ---
    //                 else {
    //                     $promoClaim = PromoClaim::where('email', $lockedUser->email)
    //                         ->where('promo_code', $promoCode)
    //                         ->lockForUpdate()
    //                         ->first();

    //                     if (! $promoClaim) {
    //                         throw new \Exception('Kode Promo tidak valid untuk akun email ini.');
    //                     }
    //                     if ($promoClaim->is_used) {
    //                         throw new \Exception('Kode Promo sudah pernah digunakan.');
    //                     }
    //                     if ($totalAmount < 499000) {
    //                         throw new \Exception('Minimum purchase to use this promo is Rp 499.000');
    //                     }

    //                     // if ($totalAmount < 1899000) {
    //                     //     throw new \Exception('Minimum purchase to use this promo is Rp 1.899.000');
    //                     // }

    //                     $promoDiscountAmount = min($promoClaim->discount_value, $totalAmount);
    //                     $appliedPromoCode = $promoClaim->promo_code;

    //                     $promoClaim->update([
    //                         'is_used' => true,
    //                         'used_at' => now(),
    //                     ]);
    //                 }
    //             }

    //             $totalAfterPromo = max(0, $totalAmount - $promoDiscountAmount);

    //             // =========================================================================
    //             // 2. POTONG POIN DARI SISA HARGA SETELAH PROMO (Mencegah Tagihan Minus)
    //             // =========================================================================
    //             $orderId = 'SOL-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

    //             // Gunakan $lockedUser, bukan $user dari luar transaksi
    //             $earnedPoints = $lockedUser->is_membership ? floor($totalAmount / 100000) : 0;

    //             $pointsUsed = 0;
    //             $pointDiscountAmount = 0;

    //             if ($request->use_points > 0 && $lockedUser->is_membership) {
    //                 // Karena kita menggunakan $lockedUser->point, angkanya dijamin 100% akurat dan terhindar dari double-spending
    //                 $pointsUsed = min($request->use_points, $lockedUser->point);

    //                 $maxUsableDiscount = min($pointsUsed * 1000, $totalAfterPromo);
    //                 $pointDiscountAmount = $maxUsableDiscount;

    //                 $actualPointsDeducted = floor($maxUsableDiscount / 1000);
    //                 $pointsUsed = $actualPointsDeducted;

    //                 if ($pointsUsed > 0) {
    //                     // Potong poin dari instance yang sudah dilock
    //                     $lockedUser->decrement('point', $pointsUsed);
    //                 }
    //             }

    //             $totalQuantity = $cartItems->sum('quantity') ?: 1;
    //             $baseShippingRate = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);
    //             $totalShippingCost = $baseShippingRate * $totalQuantity;

    //             // Saat membuat transaksi, gunakan $lockedUser->id
    //             $transaction = Transaction::create([
    //                 'user_id' => $lockedUser->id,
    //                 'address_id' => $request->address_id,
    //                 // ... (Sisa variabel di array create() ini tetap sama seperti kode Anda) ...
    //                 'shipping_method' => $request->shipping_method,
    //                 'shipping_cost' => $totalShippingCost,
    //                 'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
    //                 'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
    //                 'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
    //                 'order_id' => $orderId,
    //                 'total_amount' => $totalAmount,
    //                 'status' => 'pending',
    //                 'point' => $earnedPoints,
    //                 'points_used' => $pointsUsed,
    //                 'promo_code' => $appliedPromoCode,
    //                 'promo_discount' => $promoDiscountAmount,
    //             ]);

    //             $xenditItems = [];
    //             foreach ($cartItems as $item) {
    //                 $product = Product::lockForUpdate()->find($item->product_id);
    //                 if ($product->stock < $item->quantity) {
    //                     throw new \Exception("Stock {$product->name} insufficient");
    //                 }

    //                 $price = $item->product->discount_price ?? $item->product->price;

    //                 TransactionDetail::create([
    //                     'transaction_id' => $transaction->id,
    //                     'product_id' => $item->product_id,
    //                     'quantity' => $item->quantity,
    //                     'price' => $price,
    //                     'color' => $item->color, // <--- BARU: Simpan riwayat warna ke tabel transaksi
    //                 ]);

    //                 // ... (Logika Potong FIFO Batch Anda tetap sama di sini) ...
    //                 $remainingQuantityToDeduct = $item->quantity;
    //                 $totalBatchQuantity = ProductStock::where('product_id', $product->id)->sum('quantity');
    //                 $legacyStock = $product->stock - $totalBatchQuantity;

    //                 if ($legacyStock > 0) {
    //                     $takeFromLegacy = min($remainingQuantityToDeduct, $legacyStock);
    //                     ProductStock::create([
    //                         'product_id' => $product->id,
    //                         'batch_code' => 'SYS-LEGACY-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4)),
    //                         'quantity' => 0,
    //                         'initial_quantity' => $takeFromLegacy,
    //                     ]);
    //                     $remainingQuantityToDeduct -= $takeFromLegacy;
    //                 }

    //                 if ($remainingQuantityToDeduct > 0) {
    //                     $activeBatches = ProductStock::where('product_id', $product->id)->where('quantity', '>', 0)->orderBy('created_at', 'asc')->lockForUpdate()->get();
    //                     foreach ($activeBatches as $batch) {
    //                         if ($remainingQuantityToDeduct <= 0) {
    //                             break;
    //                         }
    //                         if ($batch->quantity >= $remainingQuantityToDeduct) {
    //                             $batch->decrement('quantity', $remainingQuantityToDeduct);
    //                             $remainingQuantityToDeduct = 0;
    //                         } else {
    //                             $remainingQuantityToDeduct -= $batch->quantity;
    //                             $batch->update(['quantity' => 0]);
    //                         }
    //                     }
    //                 }

    //                 if ($remainingQuantityToDeduct > 0) {
    //                     throw new \Exception("System error: Stock batch mismatch for '{$product->name}'.");
    //                 }
    //                 $product->decrement('stock', $item->quantity);

    //                 // [PERBAIKAN XENDIT] Tambahkan informasi warna di struk pembayaran Xendit
    //                 $productName = $product->name;
    //                 if (! empty($item->color)) {
    //                     $productName .= ' - '.$item->color;
    //                 }

    //                 $xenditItems[] = [
    //                     'name' => $productName,
    //                     'quantity' => $item->quantity,
    //                     'price' => (int) $price,
    //                     'category' => 'PHYSICAL_PRODUCT',
    //                 ];
    //             }

    //             // Cart::where('user_id', $user->id)->whereIn('id', $request->cart_ids)->delete();

    //             return [
    //                 'transaction' => $transaction,
    //                 'xenditItems' => $xenditItems,
    //                 'totalAmount' => $totalAmount,
    //                 'totalShippingCost' => $totalShippingCost,
    //                 'pointDiscountAmount' => $pointDiscountAmount,
    //                 'pointsUsed' => $pointsUsed,
    //                 'totalQuantity' => $totalQuantity,
    //                 'promoCode' => $appliedPromoCode,
    //                 'promoDiscountAmount' => $promoDiscountAmount,
    //             ];
    //         }); // <-- DB TRANSACTION SELESAI & LOCK DILEPAS DI SINI!

    //         // 2. PANGGIL API PIHAK KETIGA DENGAN AMAN DI LUAR TRANSAKSI
    //         try {
    //             // $externalId = 'PAY-'.$transactionData['transaction']->order_id;

    //             // if ($transactionData['pointDiscountAmount'] > 0) {
    //             //     $transactionData['xenditItems'][] = [
    //             //         'name' => 'Loyalty Point Discount ('.$transactionData['pointsUsed'].' Pts)',
    //             //         'quantity' => 1,
    //             //         'price' => -(int) $transactionData['pointDiscountAmount'],
    //             //         'category' => 'DISCOUNT',
    //             //     ];
    //             // }

    //             // if ($transactionData['totalShippingCost'] > 0) {
    //             //     $baseShippingRate = $transactionData['totalShippingCost'] / $transactionData['totalQuantity'];
    //             //     $transactionData['xenditItems'][] = [
    //             //         'name' => 'Shipping Cost ('.$request->courier_company.')',
    //             //         'quantity' => (int) $transactionData['totalQuantity'],
    //             //         'price' => (int) $baseShippingRate,
    //             //         'category' => 'SHIPPING_FEE',
    //             //     ];
    //             // }

    //             // $finalAmount = (int) $transactionData['totalAmount'] + $transactionData['totalShippingCost'] - $transactionData['pointDiscountAmount'];

    //             // $invoiceRequest = new CreateInvoiceRequest([
    //             //     'external_id' => $externalId,
    //             //     'payer_email' => $user->email,
    //             //     'amount' => $finalAmount,
    //             //     'description' => 'Payment for Order '.$transactionData['transaction']->order_id,
    //             //     'items' => $transactionData['xenditItems'],
    //             //     'success_redirect_url' => config('app.frontend_url').'/payment-success?external_id='.$externalId.'&order_id='.$transactionData['transaction']->order_id,
    //             //     'failure_redirect_url' => config('app.frontend_url').'/payment-failed',
    //             // ]);

    //             $externalId = 'PAY-'.$transactionData['transaction']->order_id;

    //             // [PERBAIKAN 1]: Masukkan Item Diskon Promo ke Xendit
    //             if (isset($transactionData['promoDiscountAmount']) && $transactionData['promoDiscountAmount'] > 0) {
    //                 $transactionData['xenditItems'][] = [
    //                     'name' => 'Promo Code: '.$transactionData['promoCode'],
    //                     'quantity' => 1,
    //                     'price' => -(int) $transactionData['promoDiscountAmount'], // Harus Minus
    //                     'category' => 'DISCOUNT',
    //                 ];
    //             }

    //             if ($transactionData['pointDiscountAmount'] > 0) {
    //                 $transactionData['xenditItems'][] = [
    //                     'name' => 'Loyalty Point Discount ('.$transactionData['pointsUsed'].' Pts)',
    //                     'quantity' => 1,
    //                     'price' => -(int) $transactionData['pointDiscountAmount'],
    //                     'category' => 'DISCOUNT',
    //                 ];
    //             }

    //             if ($transactionData['totalShippingCost'] > 0) {
    //                 $baseShippingRate = $transactionData['totalShippingCost'] / $transactionData['totalQuantity'];
    //                 $transactionData['xenditItems'][] = [
    //                     'name' => 'Shipping Cost ('.$request->courier_company.')',
    //                     'quantity' => (int) $transactionData['totalQuantity'],
    //                     'price' => (int) $baseShippingRate,
    //                     'category' => 'SHIPPING_FEE',
    //                 ];
    //             }

    //             // [PERBAIKAN 2]: Kurangi Promo Discount dari Final Amount Xendit
    //             $finalAmount = (int) $transactionData['totalAmount']
    //                          + $transactionData['totalShippingCost']
    //                          - $transactionData['pointDiscountAmount']
    //                          - ($transactionData['promoDiscountAmount'] ?? 0); // Kurangi Promo di sini!

    //             // ================================================================
    //             // [BARU] LOG AUDIT HARGA SEBELUM TERKIRIM KE XENDIT
    //             // Cek file log di: storage/logs/laravel.log
    //             // ================================================================
    //             Log::info('XENDIT INVOICE CALCULATION', [
    //                 'order_id' => $transactionData['transaction']->order_id,
    //                 'subtotal_barang' => $transactionData['totalAmount'],
    //                 'ongkos_kirim' => $transactionData['totalShippingCost'],
    //                 'diskon_poin' => $transactionData['pointDiscountAmount'],
    //                 'diskon_promo' => $transactionData['promoDiscountAmount'] ?? 0,
    //                 'GRAND_TOTAL_FINAL' => $finalAmount,
    //                 'xendit_items_count' => count($transactionData['xenditItems']),
    //             ]);

    //             $invoiceRequest = new CreateInvoiceRequest([
    //                 'external_id' => $externalId,
    //                 'payer_email' => $user->email,
    //                 'amount' => $finalAmount,
    //                 'description' => 'Payment for Order '.$transactionData['transaction']->order_id,
    //                 'items' => $transactionData['xenditItems'],
    //                 'success_redirect_url' => config('app.frontend_url').'/payment-success?external_id='.$externalId.'&order_id='.$transactionData['transaction']->order_id,
    //                 'failure_redirect_url' => config('app.frontend_url').'/payment-failed',
    //             ]);

    //             $api = new InvoiceApi;
    //             $invoice = $api->createInvoice($invoiceRequest);

    //             Payment::create([
    //                 'transaction_id' => $transactionData['transaction']->id,
    //                 'external_id' => $externalId,
    //                 'checkout_url' => $invoice['invoice_url'],
    //                 'amount' => $transactionData['transaction']->total_amount,
    //                 'status' => 'pending',
    //             ]);

    //             // =========================================================================
    //             // [PERBAIKAN KRITIS]: HAPUS KERANJANG DI SINI!
    //             // Eksekusi hanya jika Xendit BERHASIL membuat invoice tanpa melempar Exception.
    //             // =========================================================================
    //             Cart::where('user_id', $user->id)->whereIn('id', $request->cart_ids)->delete();

    //             // Cache::tags(['catalog'])->flush();

    //             foreach ($cartItems as $item) {
    //                 Cache::tags(['catalog'])->forget("products.detail.{$item->product_id}");
    //             }

    //             return response()->json(['checkout_url' => $invoice['invoice_url']], 201);

    //         } catch (\Exception $e) {
    //             // Jika Xendit Gagal, Batalkan Transaksi dan kembalikan stok secara manual
    //             Log::error('Xendit Invoice Creation Failed: '.$e->getMessage());
    //             app(TransactionController::class)->cancelOrder($request, $transactionData['transaction']->id);

    //             return response()->json(['message' => 'Payment gateway error. Please try again.'], 500);
    //         }
    //         // } catch (\Exception $e) {
    //         //     // Ini akan memaksa error muncul di laravel.log jika terjadi kegagalan
    //         //     Log::error('CHECKOUT FATAL ERROR: '.$e->getMessage(), [

    //     } catch (\Throwable $e) { // <--- UBAH \Exception MENJADI \Throwable
    //         // Ini akan memaksa error muncul di laravel.log jika terjadi kegagalan
    //         Log::error('CHECKOUT FATAL ERROR: '.$e->getMessage(), [
    //             'trace' => $e->getTraceAsString(),
    //         ]);

    //         return response()->json(['message' => 'Internal Server Error: '.$e->getMessage()], 500);
    //     }
    // }

    // public function checkout(Request $request)
    // {
    //     try {
    //         $request->validate([
    //             'address_id' => 'required',
    //             'shipping_method' => 'required|in:free,biteship',
    //             'use_points' => 'nullable|integer|min:0',
    //             'cart_ids' => 'required|array',
    //             'cart_ids.*' => 'exists:carts,id',
    //             'shipping_cost' => 'nullable|numeric',
    //             'courier_company' => 'nullable|string',
    //             'courier_type' => 'nullable|string',
    //             'delivery_type' => 'nullable|string',
    //             // [BARU] Wajibkan frontend mengirim mata uang
    //             'currency' => 'required|string',
    //             'referral_code' => 'nullable|string',
    //         ]);

    //         $user = $request->user();
    //         $cartItems = Cart::with('product')
    //             ->where('user_id', $user->id)
    //             ->whereIn('id', $request->cart_ids)
    //             ->get();

    //         if ($cartItems->isEmpty()) {
    //             return response()->json(['message' => 'No items selected for checkout'], 400);
    //         }

    //         // 1. LAKUKAN PROSES DATABASE DENGAN KILAT (TANPA API PIHAK KETIGA)
    //         $transactionData = DB::transaction(function () use ($user, $cartItems, $request) {

    //             $lockedUser = User::lockForUpdate()->find($user->id);

    //             $totalAmount = 0;
    //             foreach ($cartItems as $item) {
    //                 // $currentPrice = $item->product->discount_price ?? $item->product->price;
    //                 $currentPrice = $item->product->price; // Set default ke harga normal

    //                 // Cek apakah ada diskon DAN apakah waktu sekarang berada di dalam masa aktif diskon
    //                 // (Ganti 'discount_start' dan 'discount_end' dengan nama kolom di database Anda)
    //                 if (
    //                     !empty($item->product->discount_price) &&
    //                     $item->product->discount_start <= now() &&
    //                     $item->product->discount_end >= now()
    //                 ) {
    //                     $currentPrice = $item->product->discount_price;
    //                 }

    //                 $totalAmount += ($currentPrice * $item->quantity);
    //             }

    //             $promoDiscountAmount = 0;
    //             $appliedPromoCode = null;

    //             if (! empty($request->promo_code)) {
    //                 $promoCode = strtoupper(trim($request->promo_code));

    //                 if ($promoCode === 'SOLHERMEMBER') {
    //                     if (! $lockedUser->is_membership) {
    //                         throw new \Exception('Voucher ini eksklusif hanya untuk pengguna dengan status VIP Member.');
    //                     }
    //                     if ($lockedUser->has_used_member_voucher) {
    //                         throw new \Exception('Anda sudah pernah menggunakan voucher member VIP ini sebelumnya.');
    //                     }
    //                     if ($totalAmount < 1000000) {
    //                         throw new \Exception('Minimum purchase to use VIP Member Voucher is Rp 1.000.000');
    //                     }

    //                     $promoDiscountAmount = 500000;
    //                     $appliedPromoCode = 'SOLHERMEMBER';

    //                     $lockedUser->update([
    //                         'has_used_member_voucher' => true,
    //                     ]);
    //                 } else {
    //                     $promoClaim = PromoClaim::where('email', $lockedUser->email)
    //                         ->where('promo_code', $promoCode)
    //                         ->lockForUpdate()
    //                         ->first();

    //                     if (! $promoClaim) {
    //                         throw new \Exception('Kode Promo tidak valid untuk akun email ini.');
    //                     }
    //                     if ($promoClaim->is_used) {
    //                         throw new \Exception('Kode Promo sudah pernah digunakan.');
    //                     }
    //                     if ($totalAmount < 499000) {
    //                         throw new \Exception('Minimum purchase to use this promo is Rp 499.000');
    //                     }

    //                     $promoDiscountAmount = min($promoClaim->discount_value, $totalAmount);
    //                     $appliedPromoCode = $promoClaim->promo_code;

    //                     $promoClaim->update([
    //                         'is_used' => true,
    //                         'used_at' => now(),
    //                     ]);
    //                 }
    //             }

    //             $totalAfterPromo = max(0, $totalAmount - $promoDiscountAmount);

    //             $orderId = 'SOL-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
    //             $earnedPoints = $lockedUser->is_membership ? floor($totalAmount / 100000) : 0;
    //             $pointsUsed = 0;
    //             $pointDiscountAmount = 0;

    //             if ($request->use_points > 0 && $lockedUser->is_membership) {
    //                 $pointsUsed = min($request->use_points, $lockedUser->point);
    //                 $maxUsableDiscount = min($pointsUsed * 1000, $totalAfterPromo);
    //                 $pointDiscountAmount = $maxUsableDiscount;
    //                 $actualPointsDeducted = floor($maxUsableDiscount / 1000);
    //                 $pointsUsed = $actualPointsDeducted;

    //                 if ($pointsUsed > 0) {
    //                     $lockedUser->decrement('point', $pointsUsed);
    //                 }
    //             }

    //             $totalQuantity = $cartItems->sum('quantity') ?: 1;
    //             $baseShippingRate = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);
    //             $totalShippingCost = $baseShippingRate * $totalQuantity;

    //             // $affiliateId = Cookie::get('solher_affiliate_id');
    //             // =========================================================================
    //             // [BARU] LOGIKA PELACAK KOMISI AFILIATOR
    //             // =========================================================================

    //             $affiliateId = null;
    //             $commissionEarned = 0;
    //             $commissionStatus = null;

    //             if (!empty($request->referral_code)) {
    //                 // Cari siapa pemilik kode referal ini (Hanya yang statusnya is_affiliate = 1)
    //                 $affiliateUser = User::where('referral_code', $request->referral_code)
    //                                      ->where('is_affiliate', true)
    //                                      ->first();

    //                 if ($affiliateUser) {
    //                     // Jangan biarkan user memakai kode afiliasinya sendiri untuk belanja
    //                     if ($affiliateUser->id !== $lockedUser->id) {
    //                         $affiliateId = $affiliateUser->id;
    //                         $commissionRate = $affiliateUser->commission_rate ?? 5.00;
    //                         // Komisi dihitung dari total harga barang (sebelum dipotong poin/promo, atau sesudah, tergantung kebijakan Ibu Melisa. Standarnya dari total kotor barang).
    //                         $commissionEarned = $totalAmount * ($commissionRate / 100);
    //                         $commissionStatus = 'pending';
    //                     }
    //                 }
    //             }

    //             $transaction = Transaction::create([
    //                 'user_id' => $lockedUser->id,
    //                 'address_id' => $request->address_id,
    //                 'shipping_method' => $request->shipping_method,
    //                 'shipping_cost' => $totalShippingCost,
    //                 'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
    //                 'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
    //                 'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
    //                 'order_id' => $orderId,
    //                 'total_amount' => $totalAmount,
    //                 'affiliate_id' => $affiliateId,
    //                 'commission_earned' => $commissionEarned,
    //                 'commission_status' => $commissionStatus,
    //                 'status' => 'pending',
    //                 'point' => $earnedPoints,
    //                 'points_used' => $pointsUsed,
    //                 'promo_code' => $appliedPromoCode,
    //                 'promo_discount' => $promoDiscountAmount,
    //                 // [BARU] Simpan currency dari frontend ke database
    //                 'currency_code' => $request->currency,
    //             ]);

    //             // Kita ganti nama variabel dari $xenditItems menjadi $gatewayItems agar netral
    //             $gatewayItems = [];
    //             foreach ($cartItems as $item) {
    //                 $product = Product::lockForUpdate()->find($item->product_id);
    //                 if ($product->stock < $item->quantity) {
    //                     throw new \Exception("Stock {$product->name} insufficient");
    //                 }

    //                 // $price = $item->product->discount_price ?? $item->product->price;
    //                 // 👇 [PERBAIKAN LOGIKA HARGA DISKON KEDUA] 👇
    //                 $price = $product->price;
    //                 if (
    //                     !empty($product->discount_price) &&
    //                     $product->discount_start <= now() &&
    //                     $product->discount_end >= now()
    //                 ) {
    //                     $price = $product->discount_price;
    //                 }
    //                 // 👆 BATAS PERBAIKAN 👆

    //                 TransactionDetail::create([
    //                     'transaction_id' => $transaction->id,
    //                     'product_id' => $item->product_id,
    //                     'quantity' => $item->quantity,
    //                     'price' => $price,
    //                     'color' => $item->color,
    //                 ]);

    //                 $remainingQuantityToDeduct = $item->quantity;
    //                 $totalBatchQuantity = ProductStock::where('product_id', $product->id)->sum('quantity');
    //                 $legacyStock = $product->stock - $totalBatchQuantity;

    //                 if ($legacyStock > 0) {
    //                     $takeFromLegacy = min($remainingQuantityToDeduct, $legacyStock);
    //                     ProductStock::create([
    //                         'product_id' => $product->id,
    //                         'batch_code' => 'SYS-LEGACY-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4)),
    //                         'quantity' => 0,
    //                         'initial_quantity' => $takeFromLegacy,
    //                     ]);
    //                     $remainingQuantityToDeduct -= $takeFromLegacy;
    //                 }

    //                 if ($remainingQuantityToDeduct > 0) {
    //                     $activeBatches = ProductStock::where('product_id', $product->id)->where('quantity', '>', 0)->orderBy('created_at', 'asc')->lockForUpdate()->get();
    //                     foreach ($activeBatches as $batch) {
    //                         if ($remainingQuantityToDeduct <= 0) {
    //                             break;
    //                         }
    //                         if ($batch->quantity >= $remainingQuantityToDeduct) {
    //                             $batch->decrement('quantity', $remainingQuantityToDeduct);
    //                             $remainingQuantityToDeduct = 0;
    //                         } else {
    //                             $remainingQuantityToDeduct -= $batch->quantity;
    //                             $batch->update(['quantity' => 0]);
    //                         }
    //                     }
    //                 }

    //                 if ($remainingQuantityToDeduct > 0) {
    //                     throw new \Exception("System error: Stock batch mismatch for '{$product->name}'.");
    //                 }
    //                 $product->decrement('stock', $item->quantity);

    //                 $productName = $product->name;
    //                 if (! empty($item->color)) {
    //                     $productName .= ' - '.$item->color;
    //                 }

    //                 $gatewayItems[] = [
    //                     'name' => $productName,
    //                     'quantity' => $item->quantity,
    //                     'price' => (int) $price,
    //                     'category' => 'PHYSICAL_PRODUCT',
    //                 ];
    //             }

    //             return [
    //                 'transaction' => $transaction,
    //                 'gatewayItems' => $gatewayItems,
    //                 'totalAmount' => $totalAmount,
    //                 'totalShippingCost' => $totalShippingCost,
    //                 'pointDiscountAmount' => $pointDiscountAmount,
    //                 'pointsUsed' => $pointsUsed,
    //                 'totalQuantity' => $totalQuantity,
    //                 'promoCode' => $appliedPromoCode,
    //                 'promoDiscountAmount' => $promoDiscountAmount,
    //                 // Tarik mata uang dari request
    //                 'currency' => $request->currency,
    //             ];
    //         });

    //         try {
    //             $externalId = 'PAY-'.$transactionData['transaction']->order_id;

    //             if (isset($transactionData['promoDiscountAmount']) && $transactionData['promoDiscountAmount'] > 0) {
    //                 $transactionData['gatewayItems'][] = [
    //                     'name' => 'Promo Code: '.$transactionData['promoCode'],
    //                     'quantity' => 1,
    //                     'price' => -(int) $transactionData['promoDiscountAmount'],
    //                     'category' => 'DISCOUNT',
    //                 ];
    //             }

    //             if ($transactionData['pointDiscountAmount'] > 0) {
    //                 $transactionData['gatewayItems'][] = [
    //                     'name' => 'Loyalty Point Discount ('.$transactionData['pointsUsed'].' Pts)',
    //                     'quantity' => 1,
    //                     'price' => -(int) $transactionData['pointDiscountAmount'],
    //                     'category' => 'DISCOUNT',
    //                 ];
    //             }

    //             if ($transactionData['totalShippingCost'] > 0) {
    //                 $baseShippingRate = $transactionData['totalShippingCost'] / $transactionData['totalQuantity'];
    //                 $transactionData['gatewayItems'][] = [
    //                     'name' => 'Shipping Cost ('.$request->courier_company.')',
    //                     'quantity' => (int) $transactionData['totalQuantity'],
    //                     'price' => (int) $baseShippingRate,
    //                     'category' => 'SHIPPING_FEE',
    //                 ];
    //             }

    //             $finalAmount = (int) $transactionData['totalAmount']
    //                          + $transactionData['totalShippingCost']
    //                          - $transactionData['pointDiscountAmount']
    //                          - ($transactionData['promoDiscountAmount'] ?? 0);

    //             // =========================================================================
    //             // [BARU] PENCEGATAN & KONVERSI KURS MATA UANG (FIX BUG MILIARDER)
    //             // =========================================================================
    //             $currencyCode = strtoupper($transactionData['currency']);

    //             if ($currencyCode !== 'IDR') {
    //                 // Ambil data kurs dari cache yang sudah di-generate oleh Command Anda
    //                 $rates = Cache::get('exchange_rates', []);
    //                 $exchangeRate = $rates[$currencyCode] ?? 1;

    //                 // 1. Konversi Grand Total (Dibulatkan 2 angka di belakang koma)
    //                 $finalAmount = round($finalAmount * $exchangeRate, 2);

    //                 // 2. Konversi harga per item (Sangat penting agar rincian tagihan Stripe akurat)
    //                 foreach ($transactionData['gatewayItems'] as &$item) {
    //                     $item['price'] = round($item['price'] * $exchangeRate, 2);
    //                 }
    //                 unset($item); // Bersihkan referensi memori looping
    //             }

    //             Log::info('PAYMENT GATEWAY CALCULATION', [
    //                 'order_id' => $transactionData['transaction']->order_id,
    //                 'currency' => $transactionData['currency'],
    //                 'GRAND_TOTAL_FINAL' => $finalAmount,
    //             ]);

    //             // =========================================================================
    //             // [LOGIKA BARU] PANGGIL PAYMENT FACTORY DI SINI
    //             // =========================================================================
    //             // $paymentGateway = PaymentFactory::make($transactionData['currency']);

    //             // =========================================================================
    //             // [LOGIKA BARU] PANGGIL PAYMENT FACTORY & REDIRECT DINAMIS
    //             // =========================================================================
    //             $currency = $transactionData['currency'] ?? 'IDR';
    //             $paymentGateway = PaymentFactory::make($currency);

    //             // 1. Tentukan URL sukses standar (Untuk Xendit -> Langsung ke Vue.js)
    //             $frontendSuccessUrl = config('app.frontend_url')
    //                 .'/payment-success?external_id='.$externalId
    //                 .'&order_id='.$transactionData['transaction']->order_id;

    //             // 2. Tentukan URL sukses khusus PayPal (Untuk PayPal -> Masuk Jembatan Capture dulu)
    //             $paypalCaptureUrl = url('/api/payments/paypal-capture?external_id='.$externalId.'&order_id='.$transactionData['transaction']->order_id);

    //             // 3. Logika Kondisional Penentu Arah
    //             $dynamicSuccessUrl = ($currency === 'IDR') ? $frontendSuccessUrl : $paypalCaptureUrl;

    //             $checkoutUrl = $paymentGateway->createInvoice([
    //                 'order_id' => $transactionData['transaction']->order_id,
    //                 'external_id' => $externalId,
    //                 'payer_email' => $user->email,
    //                 'amount' => $finalAmount,
    //                 'currency' => $currency,
    //                 'items' => $transactionData['gatewayItems'],
    //                 'success_redirect_url' => $dynamicSuccessUrl,
    //                 'failure_redirect_url' => config('app.frontend_url').'/payment-failed',
    //             ]);

    //             Payment::create([
    //                 'transaction_id' => $transactionData['transaction']->id,
    //                 'external_id' => $externalId,
    //                 'checkout_url' => $checkoutUrl,
    //                 'amount' => $transactionData['transaction']->total_amount,
    //                 'status' => 'pending',
    //             ]);

    //             Cart::where('user_id', $user->id)->whereIn('id', $request->cart_ids)->delete();

    //             foreach ($cartItems as $item) {
    //                 Cache::tags(['catalog'])->forget("products.detail.{$item->product_id}");
    //             }

    //             // Return URL dinamis dari Factory (Bisa Xendit atau Stripe)
    //             return response()->json(['checkout_url' => $checkoutUrl], 201);

    //         } catch (\Exception $e) {
    //             report($e);
    //             Log::error('Payment Gateway Invoice Creation Failed: '.$e->getMessage());
    //             app(TransactionController::class)->cancelOrder($request, $transactionData['transaction']->id);

    //             return response()->json(['message' => 'Payment gateway error. Please try again. Error: '.$e->getMessage()], 500);
    //         }

    //     } catch (\Throwable $e) {
    //         report($e);
    //         Log::error('CHECKOUT FATAL ERROR: '.$e->getMessage(), [
    //             'trace' => $e->getTraceAsString(),
    //         ]);

    //         return response()->json(['message' => 'Internal Server Error: '.$e->getMessage()], 500);
    //     }
    // }

    // --- USER ACTIONS ---
    // public function checkout(Request $request)
    // {
    //     try {
    //         $request->validate([
    //             'address_id' => 'required',
    //             'shipping_method' => 'required|in:free,biteship',
    //             'use_points' => 'nullable|integer|min:0',
    //             'cart_ids' => 'required|array',
    //             'cart_ids.*' => 'exists:carts,id',
    //             'shipping_cost' => 'nullable|numeric',
    //             'courier_company' => 'nullable|string',
    //             'courier_type' => 'nullable|string',
    //             'delivery_type' => 'nullable|string',
    //             'currency' => 'required|string',
    //             'referral_code' => 'nullable|string',
    //         ]);

    //         $user = $request->user();

    //         $cartItems = Cart::with('product.category')
    //             ->where('user_id', $user->id)
    //             ->whereIn('id', $request->cart_ids)
    //             ->get();

    //         if ($cartItems->isEmpty()) {
    //             return response()->json(['message' => 'No items selected for checkout'], 400);
    //         }

    //         $transactionData = DB::transaction(function () use ($user, $cartItems, $request) {

    //             $lockedUser = User::lockForUpdate()->find($user->id);
    //             $currency = $request->currency;
    //             $now = now();

    //             $totalAmount = 0;
    //             $gatewayItems = [];

    //             $groupedByCategory = $cartItems->groupBy(function ($item) {
    //                 return $item->product->category_id;
    //             });

    //             foreach ($groupedByCategory as $categoryId => $items) {
    //                 $category = $items->first()->product->category;

    //                 // Mengurai JSON Bundle Price
    //                 $rawBundlePrice = $category->bundle_price;
    //                 $bundlePromo = is_string($rawBundlePrice) ? json_decode($rawBundlePrice, true) : ($rawBundlePrice ?? []);
    //                 if (is_numeric($bundlePromo)) {
    //                     $bundlePromo = ['IDR' => $bundlePromo];
    //                 }

    //                 $bundleQty = $category->bundle_qty;
    //                 $isPromoActive = $bundleQty && $bundlePromo &&
    //                     (! $category->bundle_start_date || $now >= $category->bundle_start_date) &&
    //                     (! $category->bundle_end_date || $now <= $category->bundle_end_date);

    //                 $totalQtyInCategory = $items->sum('quantity');

    //                 if ($isPromoActive && $totalQtyInCategory >= $bundleQty) {
    //                     $activeBundlePrice = $bundlePromo[$currency] ?? ($bundlePromo['IDR'] ?? 0);
    //                     $bundleCount = floor($totalQtyInCategory / $bundleQty);
    //                     $remainderQty = $totalQtyInCategory % $bundleQty;

    //                     $totalAmount += ($bundleCount * $activeBundlePrice);

    //                     $gatewayItems[] = [
    //                         'name' => "Bundle Promo: {$category->name} ($bundleCount Pakets)",
    //                         'quantity' => $bundleCount,
    //                         'price' => (int) $activeBundlePrice,
    //                         'category' => 'BUNDLE_PRODUCT',
    //                     ];

    //                     $sortedItems = $items->sortBy(function ($item) use ($currency, $now) {
    //                         $prices = is_string($item->product->prices) ? json_decode($item->product->prices, true) : ($item->product->prices ?? []);
    //                         $discountPrices = is_string($item->product->discount_prices) ? json_decode($item->product->discount_prices, true) : ($item->product->discount_prices ?? []);
    //                         $basePrice = $prices[$currency] ?? $item->product->price;
    //                         $discountPrice = $discountPrices[$currency] ?? $item->product->discount_price;

    //                         return (! empty($discountPrice) && (! $item->product->discount_start || $now >= $item->product->discount_start) && (! $item->product->discount_end || $now <= $item->product->discount_end)) ? $discountPrice : $basePrice;
    //                     });

    //                     $remainderAssigned = 0;
    //                     foreach ($sortedItems as $item) {
    //                         if ($remainderAssigned < $remainderQty) {
    //                             $takeQty = min($item->quantity, $remainderQty - $remainderAssigned);

    //                             $prices = is_string($item->product->prices) ? json_decode($item->product->prices, true) : ($item->product->prices ?? []);
    //                             $discountPrices = is_string($item->product->discount_prices) ? json_decode($item->product->discount_prices, true) : ($item->product->discount_prices ?? []);
    //                             $basePrice = $prices[$currency] ?? $item->product->price;
    //                             $discountPrice = $discountPrices[$currency] ?? $item->product->discount_price;
    //                             $normalPrice = (! empty($discountPrice) && (! $item->product->discount_start || $now >= $item->product->discount_start) && (! $item->product->discount_end || $now <= $item->product->discount_end)) ? $discountPrice : $basePrice;

    //                             $totalAmount += ($takeQty * $normalPrice);
    //                             $remainderAssigned += $takeQty;

    //                             $productName = $item->product->name.(! empty($item->color) ? ' - '.$item->color : '');
    //                             $gatewayItems[] = [
    //                                 'name' => $productName.' (Normal Price)',
    //                                 'quantity' => $takeQty,
    //                                 'price' => (int) $normalPrice,
    //                                 'category' => 'PHYSICAL_PRODUCT',
    //                             ];
    //                         }
    //                     }

    //                 } else {
    //                     foreach ($items as $item) {
    //                         $prices = is_string($item->product->prices) ? json_decode($item->product->prices, true) : ($item->product->prices ?? []);
    //                         $discountPrices = is_string($item->product->discount_prices) ? json_decode($item->product->discount_prices, true) : ($item->product->discount_prices ?? []);
    //                         $basePrice = $prices[$currency] ?? $item->product->price;
    //                         $discountPrice = $discountPrices[$currency] ?? $item->product->discount_price;
    //                         $normalPrice = (! empty($discountPrice) && (! $item->product->discount_start || $now >= $item->product->discount_start) && (! $item->product->discount_end || $now <= $item->product->discount_end)) ? $discountPrice : $basePrice;

    //                         $totalAmount += ($item->quantity * $normalPrice);

    //                         $productName = $item->product->name.(! empty($item->color) ? ' - '.$item->color : '');
    //                         $gatewayItems[] = [
    //                             'name' => $productName,
    //                             'quantity' => $item->quantity,
    //                             'price' => (int) $normalPrice,
    //                             'category' => 'PHYSICAL_PRODUCT',
    //                         ];
    //                     }
    //                 }
    //             }

    //             $promoDiscountAmount = 0;
    //             $appliedPromoCode = null;

    //             if (! empty($request->promo_code)) {
    //                 $promoCode = strtoupper(trim($request->promo_code));

    //                 if ($promoCode === 'SOLHERMEMBER') {
    //                     if (! $lockedUser->is_membership) {
    //                         throw new \Exception('Voucher ini eksklusif hanya untuk pengguna dengan status VIP Member.');
    //                     }
    //                     if ($lockedUser->has_used_member_voucher) {
    //                         throw new \Exception('Anda sudah pernah menggunakan voucher member VIP ini sebelumnya.');
    //                     }

    //                     $promoDiscountAmount = ($currency === 'IDR') ? 500000 : 35; // Misal $35 jika USD
    //                     $appliedPromoCode = 'SOLHERMEMBER';

    //                     $lockedUser->update(['has_used_member_voucher' => true]);
    //                 } else {
    //                     $promoClaim = PromoClaim::where('email', $lockedUser->email)->where('promo_code', $promoCode)->lockForUpdate()->first();

    //                     if (! $promoClaim) {
    //                         throw new \Exception('Kode Promo tidak valid untuk akun email ini.');
    //                     }
    //                     if ($promoClaim->is_used) {
    //                         throw new \Exception('Kode Promo sudah pernah digunakan.');
    //                     }

    //                     $minPurchase = ($currency === 'IDR') ? 499000 : 35; // Sesuaikan angka 35 dengan rate Anda
    //                     if ($totalAmount < $minPurchase) {
    //                         $currencyText = ($currency === 'IDR') ? 'Rp 499.000' : '$'.$minPurchase;
    //                         throw new \Exception("Minimum purchase to use this promo is {$currencyText}");
    //                     }

    //                     $promoDiscountAmount = min($promoClaim->discount_value, $totalAmount);
    //                     $appliedPromoCode = $promoClaim->promo_code;

    //                     $promoClaim->update(['is_used' => true, 'used_at' => now()]);
    //                 }
    //             }

    //             $totalAfterPromo = max(0, $totalAmount - $promoDiscountAmount);

    //             $orderId = 'SOL-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
    //             $earnedPoints = $lockedUser->is_membership ? floor($totalAmount / 100000) : 0;
    //             $pointsUsed = 0;
    //             $pointDiscountAmount = 0;

    //             if ($request->use_points > 0 && $lockedUser->is_membership) {
    //                 $pointsUsed = min($request->use_points, $lockedUser->point);
    //                 $maxUsableDiscount = min($pointsUsed * 1000, $totalAfterPromo);
    //                 $pointDiscountAmount = $maxUsableDiscount;
    //                 $actualPointsDeducted = floor($maxUsableDiscount / 1000);
    //                 $pointsUsed = $actualPointsDeducted;

    //                 if ($pointsUsed > 0) {
    //                     $lockedUser->decrement('point', $pointsUsed);
    //                 }
    //             }

    //             // $totalQuantity = $cartItems->sum('quantity') ?: 1;
    //             // $baseShippingRate = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);
    //             // $totalShippingCost = $baseShippingRate * $totalQuantity;

    //             $totalQuantity = $cartItems->sum('quantity') ?: 1;

    //             $totalShippingCost = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);

    //             $affiliateId = null;
    //             $commissionEarned = 0;
    //             $commissionStatus = null;

    //             if (! empty($request->referral_code)) {
    //                 $affiliateUser = User::where('referral_code', $request->referral_code)->where('is_affiliate', true)->first();
    //                 if ($affiliateUser && $affiliateUser->id !== $lockedUser->id) {
    //                     $affiliateId = $affiliateUser->id;
    //                     $commissionRate = $affiliateUser->commission_rate ?? 5.00;
    //                     $commissionEarned = $totalAmount * ($commissionRate / 100);
    //                     $commissionStatus = 'pending';
    //                 }
    //             }

    //             $transaction = Transaction::create([
    //                 'user_id' => $lockedUser->id,
    //                 'address_id' => $request->address_id,
    //                 'shipping_method' => $request->shipping_method,
    //                 'shipping_cost' => $totalShippingCost,
    //                 'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
    //                 'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
    //                 'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
    //                 'order_id' => $orderId,
    //                 'total_amount' => $totalAmount,
    //                 'affiliate_id' => $affiliateId,
    //                 'commission_earned' => $commissionEarned,
    //                 'commission_status' => $commissionStatus,
    //                 'status' => 'pending',
    //                 'point' => $earnedPoints,
    //                 'points_used' => $pointsUsed,
    //                 'promo_code' => $appliedPromoCode,
    //                 'promo_discount' => $promoDiscountAmount,
    //                 'currency_code' => $currency,
    //             ]);

    //             foreach ($cartItems as $item) {
    //                 $product = Product::lockForUpdate()->find($item->product_id);
    //                 if ($product->stock < $item->quantity) {
    //                     throw new \Exception("Stock {$product->name} insufficient");
    //                 }

    //                 $prices = is_string($product->prices) ? json_decode($product->prices, true) : ($product->prices ?? []);
    //                 $basePrice = $prices[$currency] ?? $product->price;

    //                 TransactionDetail::create([
    //                     'transaction_id' => $transaction->id,
    //                     'product_id' => $item->product_id,
    //                     'quantity' => $item->quantity,
    //                     'price' => $basePrice,
    //                     'color' => $item->color,
    //                 ]);

    //                 $remainingQuantityToDeduct = $item->quantity;
    //                 $totalBatchQuantity = ProductStock::where('product_id', $product->id)->sum('quantity');
    //                 $legacyStock = $product->stock - $totalBatchQuantity;

    //                 if ($legacyStock > 0) {
    //                     $takeFromLegacy = min($remainingQuantityToDeduct, $legacyStock);
    //                     ProductStock::create([
    //                         'product_id' => $product->id,
    //                         'batch_code' => 'SYS-LEGACY-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4)),
    //                         'quantity' => 0,
    //                         'initial_quantity' => $takeFromLegacy,
    //                     ]);
    //                     $remainingQuantityToDeduct -= $takeFromLegacy;
    //                 }

    //                 if ($remainingQuantityToDeduct > 0) {
    //                     $activeBatches = ProductStock::where('product_id', $product->id)->where('quantity', '>', 0)->orderBy('created_at', 'asc')->lockForUpdate()->get();
    //                     foreach ($activeBatches as $batch) {
    //                         if ($remainingQuantityToDeduct <= 0) {
    //                             break;
    //                         }
    //                         if ($batch->quantity >= $remainingQuantityToDeduct) {
    //                             $batch->decrement('quantity', $remainingQuantityToDeduct);
    //                             $remainingQuantityToDeduct = 0;
    //                         } else {
    //                             $remainingQuantityToDeduct -= $batch->quantity;
    //                             $batch->update(['quantity' => 0]);
    //                         }
    //                     }
    //                 }

    //                 if ($remainingQuantityToDeduct > 0) {
    //                     throw new \Exception("System error: Stock batch mismatch for '{$product->name}'.");
    //                 }
    //                 $product->decrement('stock', $item->quantity);
    //             }

    //             return [
    //                 'transaction' => $transaction,
    //                 'gatewayItems' => $gatewayItems,
    //                 'totalAmount' => $totalAmount,
    //                 'totalShippingCost' => $totalShippingCost,
    //                 'pointDiscountAmount' => $pointDiscountAmount,
    //                 'pointsUsed' => $pointsUsed,
    //                 'totalQuantity' => $totalQuantity,
    //                 'promoCode' => $appliedPromoCode,
    //                 'promoDiscountAmount' => $promoDiscountAmount,
    //                 'currency' => $currency,
    //             ];
    //         });

    //         try {
    //             $externalId = 'PAY-'.$transactionData['transaction']->order_id;

    //             if (isset($transactionData['promoDiscountAmount']) && $transactionData['promoDiscountAmount'] > 0) {
    //                 $transactionData['gatewayItems'][] = [
    //                     'name' => 'Promo Code: '.$transactionData['promoCode'],
    //                     'quantity' => 1,
    //                     'price' => -(int) $transactionData['promoDiscountAmount'],
    //                     'category' => 'DISCOUNT',
    //                 ];
    //             }

    //             if ($transactionData['pointDiscountAmount'] > 0) {
    //                 $transactionData['gatewayItems'][] = [
    //                     'name' => 'Loyalty Point Discount ('.$transactionData['pointsUsed'].' Pts)',
    //                     'quantity' => 1,
    //                     'price' => -(int) $transactionData['pointDiscountAmount'],
    //                     'category' => 'DISCOUNT',
    //                 ];
    //             }

    //             if ($transactionData['totalShippingCost'] > 0) {
    //                 $baseShippingRate = $transactionData['totalShippingCost'] / $transactionData['totalQuantity'];
    //                 $transactionData['gatewayItems'][] = [
    //                     'name' => 'Shipping Cost ('.$request->courier_company.')',
    //                     'quantity' => (int) $transactionData['totalQuantity'],
    //                     'price' => (int) $baseShippingRate,
    //                     'category' => 'SHIPPING_FEE',
    //                 ];
    //             }

    //             $finalAmount = (int) $transactionData['totalAmount']
    //                          + $transactionData['totalShippingCost']
    //                          - $transactionData['pointDiscountAmount']
    //                          - ($transactionData['promoDiscountAmount'] ?? 0);

    //             $currencyCode = strtoupper($transactionData['currency']);

    //             if ($currencyCode !== 'IDR') {
    //                 // Ambil data kurs dari cache
    //                 $rates = Cache::get('exchange_rates', []);
    //                 $exchangeRate = $rates[$currencyCode] ?? 1;

    //                 // 1. Konversi Grand Total
    //                 $finalAmount = round($finalAmount * $exchangeRate, 2);

    //                 // 2. Konversi harga per item untuk gateway
    //                 foreach ($transactionData['gatewayItems'] as &$item) {
    //                     $item['price'] = round($item['price'] * $exchangeRate, 2);
    //                 }
    //                 unset($item);
    //             }

    //             Log::info('PAYMENT GATEWAY CALCULATION', [
    //                 'order_id' => $transactionData['transaction']->order_id,
    //                 'currency' => $transactionData['currency'],
    //                 'GRAND_TOTAL_FINAL' => $finalAmount,
    //             ]);

    //             $currency = $transactionData['currency'] ?? 'IDR';
    //             $paymentGateway = PaymentFactory::make($currency);

    //             $frontendSuccessUrl = config('app.frontend_url')
    //                 .'/payment-success?external_id='.$externalId
    //                 .'&order_id='.$transactionData['transaction']->order_id;

    //             $paypalCaptureUrl = url('/api/payments/paypal-capture?external_id='.$externalId.'&order_id='.$transactionData['transaction']->order_id);

    //             $dynamicSuccessUrl = ($currency === 'IDR') ? $frontendSuccessUrl : $paypalCaptureUrl;

    //             $checkoutUrl = $paymentGateway->createInvoice([
    //                 'order_id' => $transactionData['transaction']->order_id,
    //                 'external_id' => $externalId,
    //                 'payer_email' => $user->email,
    //                 'amount' => $finalAmount,
    //                 'currency' => $currency,
    //                 'items' => $transactionData['gatewayItems'],
    //                 'success_redirect_url' => $dynamicSuccessUrl,
    //                 'failure_redirect_url' => config('app.frontend_url').'/payment-failed',
    //             ]);

    //             Payment::create([
    //                 'transaction_id' => $transactionData['transaction']->id,
    //                 'external_id' => $externalId,
    //                 'checkout_url' => $checkoutUrl,
    //                 'amount' => $transactionData['transaction']->total_amount,
    //                 'status' => 'pending',
    //             ]);

    //             Cart::where('user_id', $user->id)->whereIn('id', $request->cart_ids)->delete();

    //             foreach ($cartItems as $item) {
    //                 Cache::tags(['catalog'])->forget("products.detail.{$item->product_id}");
    //             }

    //             return response()->json(['checkout_url' => $checkoutUrl], 201);

    //         } catch (\Exception $e) {
    //             report($e);
    //             Log::error('Payment Gateway Invoice Creation Failed: '.$e->getMessage());
    //             app(TransactionController::class)->cancelOrder($request, $transactionData['transaction']->id);

    //             return response()->json(['message' => 'Payment gateway error. Please try again. Error: '.$e->getMessage()], 500);
    //         }

    //     } catch (\Throwable $e) {
    //         report($e);
    //         Log::error('CHECKOUT FATAL ERROR: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

    //         return response()->json(['message' => 'Internal Server Error: '.$e->getMessage()], 500);
    //     }
    // }

    // --- USER ACTIONS ---
    // public function checkout(Request $request)
    // {
    //     try {
    //         $request->validate([
    //             'address_id' => 'required',
    //             'shipping_method' => 'required|in:free,biteship',
    //             'use_points' => 'nullable|integer|min:0',
    //             'cart_ids' => 'required|array',
    //             'cart_ids.*' => 'exists:carts,id',
    //             'shipping_cost' => 'nullable|numeric',
    //             'courier_company' => 'nullable|string',
    //             'courier_type' => 'nullable|string',
    //             'delivery_type' => 'nullable|string',
    //             'currency' => 'required|string',
    //             'referral_code' => 'nullable|string',
    //         ]);

    //         $user = $request->user();

    //         $cartItems = Cart::with('product.category')
    //             ->where('user_id', $user->id)
    //             ->whereIn('id', $request->cart_ids)
    //             ->get();

    //         if ($cartItems->isEmpty()) {
    //             return response()->json(['message' => 'No items selected for checkout'], 400);
    //         }

    //         $transactionData = DB::transaction(function () use ($user, $cartItems, $request) {

    //             $lockedUser = User::lockForUpdate()->find($user->id);
    //             $currency = $request->currency;
    //             $now = now();

    //             $totalAmount = 0;
    //             $gatewayItems = [];
    //             $finalItemPrices = []; // 👈 [BARU] Wadah penyimpan harga final agar akurat dengan diskon

    //             $groupedByCategory = $cartItems->groupBy(function ($item) {
    //                 return $item->product->category_id;
    //             });

    //             foreach ($groupedByCategory as $categoryId => $items) {
    //                 $category = $items->first()->product->category;

    //                 // Mengurai JSON Bundle Price
    //                 $rawBundlePrice = $category->bundle_price;
    //                 $bundlePromo = is_string($rawBundlePrice) ? json_decode($rawBundlePrice, true) : ($rawBundlePrice ?? []);
    //                 if (is_numeric($bundlePromo)) {
    //                     $bundlePromo = ['IDR' => $bundlePromo];
    //                 }

    //                 $bundleQty = $category->bundle_qty;
    //                 $isPromoActive = $bundleQty && $bundlePromo &&
    //                     (! $category->bundle_start_date || $now >= $category->bundle_start_date) &&
    //                     (! $category->bundle_end_date || $now <= $category->bundle_end_date);

    //                 $totalQtyInCategory = $items->sum('quantity');

    //                 if ($isPromoActive && $totalQtyInCategory >= $bundleQty) {
    //                     $activeBundlePrice = $bundlePromo[$currency] ?? ($bundlePromo['IDR'] ?? 0);
    //                     $bundleCount = floor($totalQtyInCategory / $bundleQty);
    //                     $remainderQty = $totalQtyInCategory % $bundleQty;

    //                     $totalAmount += ($bundleCount * $activeBundlePrice);

    //                     $gatewayItems[] = [
    //                         'name' => "Bundle Promo: {$category->name} ($bundleCount Pakets)",
    //                         'quantity' => $bundleCount,
    //                         'price' => (int) $activeBundlePrice,
    //                         'category' => 'BUNDLE_PRODUCT',
    //                     ];

    //                     $sortedItems = $items->sortBy(function ($item) use ($currency, $now) {
    //                         $prices = is_string($item->product->prices) ? json_decode($item->product->prices, true) : ($item->product->prices ?? []);
    //                         $discountPrices = is_string($item->product->discount_prices) ? json_decode($item->product->discount_prices, true) : ($item->product->discount_prices ?? []);
    //                         $basePrice = $prices[$currency] ?? $item->product->price;
    //                         $discountPrice = $discountPrices[$currency] ?? $item->product->discount_price;

    //                         // 👇 [PERBAIKAN TYPO] Menggunakan _date di bagian akhir
    //                         return (! empty($discountPrice) && (! $item->product->discount_start_date || $now >= $item->product->discount_start_date) && (! $item->product->discount_end_date || $now <= $item->product->discount_end_date)) ? $discountPrice : $basePrice;
    //                     });

    //                     $remainderAssigned = 0;
    //                     foreach ($sortedItems as $item) {
    //                         if ($remainderAssigned < $remainderQty) {
    //                             $takeQty = min($item->quantity, $remainderQty - $remainderAssigned);

    //                             $prices = is_string($item->product->prices) ? json_decode($item->product->prices, true) : ($item->product->prices ?? []);
    //                             $discountPrices = is_string($item->product->discount_prices) ? json_decode($item->product->discount_prices, true) : ($item->product->discount_prices ?? []);
    //                             $basePrice = $prices[$currency] ?? $item->product->price;
    //                             $discountPrice = $discountPrices[$currency] ?? $item->product->discount_price;

    //                             // 👇 [PERBAIKAN TYPO] Menggunakan _date di bagian akhir
    //                             $normalPrice = (! empty($discountPrice) && (! $item->product->discount_start_date || $now >= $item->product->discount_start_date) && (! $item->product->discount_end_date || $now <= $item->product->discount_end_date)) ? $discountPrice : $basePrice;

    //                             $totalAmount += ($takeQty * $normalPrice);
    //                             $finalItemPrices[$item->id] = $normalPrice; // 👈 SIMPAN HARGA FINAL DISKON
    //                             $remainderAssigned += $takeQty;

    //                             $productName = $item->product->name.(! empty($item->color) ? ' - '.$item->color : '');
    //                             $gatewayItems[] = [
    //                                 'name' => $productName.' (Normal Price)',
    //                                 'quantity' => $takeQty,
    //                                 'price' => (int) $normalPrice,
    //                                 'category' => 'PHYSICAL_PRODUCT',
    //                             ];
    //                         } else {
    //                             $prices = is_string($item->product->prices) ? json_decode($item->product->prices, true) : ($item->product->prices ?? []);
    //                             $discountPrices = is_string($item->product->discount_prices) ? json_decode($item->product->discount_prices, true) : ($item->product->discount_prices ?? []);
    //                             $basePrice = $prices[$currency] ?? $item->product->price;
    //                             $discountPrice = $discountPrices[$currency] ?? $item->product->discount_price;
    //                             $normalPrice = (! empty($discountPrice) && (! $item->product->discount_start_date || $now >= $item->product->discount_start_date) && (! $item->product->discount_end_date || $now <= $item->product->discount_end_date)) ? $discountPrice : $basePrice;

    //                             $finalItemPrices[$item->id] = $normalPrice; // 👈 Simpan agar struk riwayat akurat
    //                         }
    //                     }

    //                 } else {
    //                     foreach ($items as $item) {
    //                         $prices = is_string($item->product->prices) ? json_decode($item->product->prices, true) : ($item->product->prices ?? []);
    //                         $discountPrices = is_string($item->product->discount_prices) ? json_decode($item->product->discount_prices, true) : ($item->product->discount_prices ?? []);
    //                         $basePrice = $prices[$currency] ?? $item->product->price;
    //                         $discountPrice = $discountPrices[$currency] ?? $item->product->discount_price;

    //                         // 👇 [PERBAIKAN TYPO] Menggunakan _date
    //                         $normalPrice = (! empty($discountPrice) && (! $item->product->discount_start_date || $now >= $item->product->discount_start_date) && (! $item->product->discount_end_date || $now <= $item->product->discount_end_date)) ? $discountPrice : $basePrice;

    //                         $totalAmount += ($item->quantity * $normalPrice);
    //                         $finalItemPrices[$item->id] = $normalPrice; // 👈 SIMPAN HARGA FINAL DISKON

    //                         $productName = $item->product->name.(! empty($item->color) ? ' - '.$item->color : '');
    //                         $gatewayItems[] = [
    //                             'name' => $productName,
    //                             'quantity' => $item->quantity,
    //                             'price' => (int) $normalPrice,
    //                             'category' => 'PHYSICAL_PRODUCT',
    //                         ];
    //                     }
    //                 }
    //             }

    //             $promoDiscountAmount = 0;
    //             $appliedPromoCode = null;

    //             if (! empty($request->promo_code)) {
    //                 $promoCode = strtoupper(trim($request->promo_code));

    //                 if ($promoCode === 'SOLHERMEMBER') {
    //                     if (! $lockedUser->is_membership) {
    //                         throw new \Exception('Voucher ini eksklusif hanya untuk pengguna dengan status VIP Member.');
    //                     }
    //                     if ($lockedUser->has_used_member_voucher) {
    //                         throw new \Exception('Anda sudah pernah menggunakan voucher member VIP ini sebelumnya.');
    //                     }

    //                     $promoDiscountAmount = ($currency === 'IDR') ? 500000 : 35; // Misal $35 jika USD
    //                     $appliedPromoCode = 'SOLHERMEMBER';

    //                     $lockedUser->update(['has_used_member_voucher' => true]);
    //                 } else {
    //                     $promoClaim = PromoClaim::where('email', $lockedUser->email)->where('promo_code', $promoCode)->lockForUpdate()->first();

    //                     if (! $promoClaim) {
    //                         throw new \Exception('Kode Promo tidak valid untuk akun email ini.');
    //                     }
    //                     if ($promoClaim->is_used) {
    //                         throw new \Exception('Kode Promo sudah pernah digunakan.');
    //                     }

    //                     $minPurchase = ($currency === 'IDR') ? 499000 : 35;
    //                     if ($totalAmount < $minPurchase) {
    //                         $currencyText = ($currency === 'IDR') ? 'Rp 499.000' : '$'.$minPurchase;
    //                         throw new \Exception("Minimum purchase to use this promo is {$currencyText}");
    //                     }

    //                     $promoDiscountAmount = min($promoClaim->discount_value, $totalAmount);
    //                     $appliedPromoCode = $promoClaim->promo_code;

    //                     $promoClaim->update(['is_used' => true, 'used_at' => now()]);
    //                 }
    //             }

    //             $totalAfterPromo = max(0, $totalAmount - $promoDiscountAmount);

    //             $orderId = 'SOL-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
    //             $earnedPoints = $lockedUser->is_membership ? floor($totalAmount / 100000) : 0;
    //             $pointsUsed = 0;
    //             $pointDiscountAmount = 0;

    //             if ($request->use_points > 0 && $lockedUser->is_membership) {
    //                 $pointsUsed = min($request->use_points, $lockedUser->point);
    //                 $maxUsableDiscount = min($pointsUsed * 1000, $totalAfterPromo);
    //                 $pointDiscountAmount = $maxUsableDiscount;
    //                 $actualPointsDeducted = floor($maxUsableDiscount / 1000);
    //                 $pointsUsed = $actualPointsDeducted;

    //                 if ($pointsUsed > 0) {
    //                     $lockedUser->decrement('point', $pointsUsed);
    //                 }
    //             }

    //             $totalShippingCost = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);

    //             $affiliateId = null;
    //             $commissionEarned = 0;
    //             $commissionStatus = null;

    //             if (! empty($request->referral_code)) {
    //                 $affiliateUser = User::where('referral_code', $request->referral_code)->where('is_affiliate', true)->first();
    //                 if ($affiliateUser && $affiliateUser->id !== $lockedUser->id) {
    //                     $affiliateId = $affiliateUser->id;
    //                     $commissionRate = $affiliateUser->commission_rate ?? 5.00;
    //                     $commissionEarned = $totalAmount * ($commissionRate / 100);
    //                     $commissionStatus = 'pending';
    //                 }
    //             }

    //             $transaction = Transaction::create([
    //                 'user_id' => $lockedUser->id,
    //                 'address_id' => $request->address_id,
    //                 'shipping_method' => $request->shipping_method,
    //                 'shipping_cost' => $totalShippingCost,
    //                 'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
    //                 'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
    //                 'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
    //                 'order_id' => $orderId,
    //                 'total_amount' => $totalAmount,
    //                 'affiliate_id' => $affiliateId,
    //                 'commission_earned' => $commissionEarned,
    //                 'commission_status' => $commissionStatus,
    //                 'status' => 'pending',
    //                 'point' => $earnedPoints,
    //                 'points_used' => $pointsUsed,
    //                 'promo_code' => $appliedPromoCode,
    //                 'promo_discount' => $promoDiscountAmount,
    //                 'currency_code' => $currency,
    //             ]);

    //             foreach ($cartItems as $item) {
    //                 $product = Product::lockForUpdate()->find($item->product_id);
    //                 if ($product->stock < $item->quantity) {
    //                     throw new \Exception("Stock {$product->name} insufficient");
    //                 }

    //                 // 👇 [PERBAIKAN KRITIS]: PANGGIL DATA DARI ARRAY PENYIMPAN DISKON 👇
    //                 $savedPrice = $finalItemPrices[$item->id] ?? $product->price;

    //                 TransactionDetail::create([
    //                     'transaction_id' => $transaction->id,
    //                     'product_id' => $item->product_id,
    //                     'quantity' => $item->quantity,
    //                     'price' => $savedPrice, // 👈 SUDAH MENGGUNAKAN HARGA DISKON
    //                     'color' => $item->color,
    //                 ]);

    //                 $remainingQuantityToDeduct = $item->quantity;
    //                 $totalBatchQuantity = ProductStock::where('product_id', $product->id)->sum('quantity');
    //                 $legacyStock = $product->stock - $totalBatchQuantity;

    //                 if ($legacyStock > 0) {
    //                     $takeFromLegacy = min($remainingQuantityToDeduct, $legacyStock);
    //                     ProductStock::create([
    //                         'product_id' => $product->id,
    //                         'batch_code' => 'SYS-LEGACY-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4)),
    //                         'quantity' => 0,
    //                         'initial_quantity' => $takeFromLegacy,
    //                     ]);
    //                     $remainingQuantityToDeduct -= $takeFromLegacy;
    //                 }

    //                 if ($remainingQuantityToDeduct > 0) {
    //                     $activeBatches = ProductStock::where('product_id', $product->id)->where('quantity', '>', 0)->orderBy('created_at', 'asc')->lockForUpdate()->get();
    //                     foreach ($activeBatches as $batch) {
    //                         if ($remainingQuantityToDeduct <= 0) {
    //                             break;
    //                         }
    //                         if ($batch->quantity >= $remainingQuantityToDeduct) {
    //                             $batch->decrement('quantity', $remainingQuantityToDeduct);
    //                             $remainingQuantityToDeduct = 0;
    //                         } else {
    //                             $remainingQuantityToDeduct -= $batch->quantity;
    //                             $batch->update(['quantity' => 0]);
    //                         }
    //                     }
    //                 }

    //                 if ($remainingQuantityToDeduct > 0) {
    //                     throw new \Exception("System error: Stock batch mismatch for '{$product->name}'.");
    //                 }
    //                 $product->decrement('stock', $item->quantity);
    //             }

    //             // Kirim event CAPI ke Facebook
    //             // $this->sendFacebookConversionAPI($transaction);

    //             return [
    //                 'transaction' => $transaction,
    //                 'gatewayItems' => $gatewayItems,
    //                 'totalAmount' => $totalAmount,
    //                 'totalShippingCost' => $totalShippingCost,
    //                 'pointDiscountAmount' => $pointDiscountAmount,
    //                 'pointsUsed' => $pointsUsed,
    //                 'totalQuantity' => $cartItems->sum('quantity') ?: 1,
    //                 'promoCode' => $appliedPromoCode,
    //                 'promoDiscountAmount' => $promoDiscountAmount,
    //                 'currency' => $currency,
    //             ];
    //         });

    //         // Lanjut ke Payment Gateway
    //         $paymentController = app(PaymentController::class);
    //         $request->merge([
    //             'transaction_id' => $transactionData['transaction']->id,
    //             'currency' => $transactionData['currency']
    //         ]);

    //         return $paymentController->createInvoice($request);

    //     } catch (\Throwable $e) {
    //         report($e);
    //         Log::error('CHECKOUT FATAL ERROR: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
    //         return response()->json(['message' => 'Internal Server Error: '.$e->getMessage()], 500);
    //     }
    // }

    // Ganti fungsi public function checkout(Request $request) secara utuh dengan ini:

    // public function checkout(Request $request, PromoMerdekaService $promoService)
    // {
    //     try {
    //         $request->validate([
    //             'address_id' => 'required',
    //             'shipping_method' => 'required|in:free,biteship',
    //             'use_points' => 'nullable|integer|min:0',
    //             'cart_ids' => 'required|array',
    //             'cart_ids.*' => 'exists:carts,id',
    //             'shipping_cost' => 'nullable|numeric',
    //             'courier_company' => 'nullable|string',
    //             'courier_type' => 'nullable|string',
    //             'delivery_type' => 'nullable|string',
    //             'currency' => 'required|string',
    //             'referral_code' => 'nullable|string',
    //         ]);

    //         $user = $request->user();

    //         $cartItems = Cart::with('product.category')
    //             ->where('user_id', $user->id)
    //             ->whereIn('id', $request->cart_ids)
    //             ->get();

    //         if ($cartItems->isEmpty()) {
    //             return response()->json(['message' => 'No items selected for checkout'], 400);
    //         }

    //         // $transactionData = DB::transaction(function () use ($user, $cartItems, $request) {
    //         $transactionData = DB::transaction(function () use ($user, $cartItems, $request, $promoService) {

    //             $lockedUser = User::lockForUpdate()->find($user->id);
    //             $currency = $request->currency;
    //             $now = now();

    //             $totalAmount = 0;
    //             $gatewayItems = [];
    //             $finalItemPrices = [];

    //             $groupedByCategory = $cartItems->groupBy(function ($item) {
    //                 return $item->product->category_id;
    //             });

    //             foreach ($groupedByCategory as $categoryId => $items) {
    //                 $category = $items->first()->product->category;

    //                 // Mengurai JSON Bundle Price
    //                 $rawBundlePrice = $category->bundle_price;
    //                 $bundlePromo = is_string($rawBundlePrice) ? json_decode($rawBundlePrice, true) : ($rawBundlePrice ?? []);
    //                 if (is_numeric($bundlePromo)) {
    //                     $bundlePromo = ['IDR' => $bundlePromo];
    //                 }

    //                 $bundleQty = $category->bundle_qty;
    //                 $isPromoActive = $bundleQty && $bundlePromo &&
    //                     (! $category->bundle_start_date || $now >= $category->bundle_start_date) &&
    //                     (! $category->bundle_end_date || $now <= $category->bundle_end_date);

    //                 $totalQtyInCategory = $items->sum('quantity');

    //                 if ($isPromoActive && $totalQtyInCategory >= $bundleQty) {
    //                     $activeBundlePrice = $bundlePromo[$currency] ?? ($bundlePromo['IDR'] ?? 0);
    //                     $bundleCount = floor($totalQtyInCategory / $bundleQty);
    //                     $remainderQty = $totalQtyInCategory % $bundleQty;

    //                     $totalAmount += ($bundleCount * $activeBundlePrice);

    //                     $gatewayItems[] = [
    //                         'name' => "Bundle Promo: {$category->name} ($bundleCount Pakets)",
    //                         'quantity' => $bundleCount,
    //                         'price' => (int) $activeBundlePrice,
    //                         'category' => 'BUNDLE_PRODUCT',
    //                     ];

    //                     $sortedItems = $items->sortBy(function ($item) use ($currency, $now) {
    //                         $prices = is_string($item->product->prices) ? json_decode($item->product->prices, true) : ($item->product->prices ?? []);
    //                         $discountPrices = is_string($item->product->discount_prices) ? json_decode($item->product->discount_prices, true) : ($item->product->discount_prices ?? []);
    //                         $basePrice = $prices[$currency] ?? $item->product->price;
    //                         $discountPrice = $discountPrices[$currency] ?? $item->product->discount_price;

    //                         return (! empty($discountPrice) && (! $item->product->discount_start_date || $now >= $item->product->discount_start_date) && (! $item->product->discount_end_date || $now <= $item->product->discount_end_date)) ? $discountPrice : $basePrice;
    //                     });

    //                     $remainderAssigned = 0;
    //                     foreach ($sortedItems as $item) {
    //                         if ($remainderAssigned < $remainderQty) {
    //                             $takeQty = min($item->quantity, $remainderQty - $remainderAssigned);

    //                             $prices = is_string($item->product->prices) ? json_decode($item->product->prices, true) : ($item->product->prices ?? []);
    //                             $discountPrices = is_string($item->product->discount_prices) ? json_decode($item->product->discount_prices, true) : ($item->product->discount_prices ?? []);
    //                             $basePrice = $prices[$currency] ?? $item->product->price;
    //                             $discountPrice = $discountPrices[$currency] ?? $item->product->discount_price;

    //                             $normalPrice = (! empty($discountPrice) && (! $item->product->discount_start_date || $now >= $item->product->discount_start_date) && (! $item->product->discount_end_date || $now <= $item->product->discount_end_date)) ? $discountPrice : $basePrice;

    //                             $totalAmount += ($takeQty * $normalPrice);
    //                             $finalItemPrices[$item->id] = $normalPrice;
    //                             $remainderAssigned += $takeQty;

    //                             $productName = $item->product->name.(! empty($item->color) ? ' - '.$item->color : '');
    //                             $gatewayItems[] = [
    //                                 'name' => $productName.' (Normal Price)',
    //                                 'quantity' => $takeQty,
    //                                 'price' => (int) $normalPrice,
    //                                 'category' => 'PHYSICAL_PRODUCT',
    //                             ];
    //                         } else {
    //                             $prices = is_string($item->product->prices) ? json_decode($item->product->prices, true) : ($item->product->prices ?? []);
    //                             $discountPrices = is_string($item->product->discount_prices) ? json_decode($item->product->discount_prices, true) : ($item->product->discount_prices ?? []);
    //                             $basePrice = $prices[$currency] ?? $item->product->price;
    //                             $discountPrice = $discountPrices[$currency] ?? $item->product->discount_price;
    //                             $normalPrice = (! empty($discountPrice) && (! $item->product->discount_start_date || $now >= $item->product->discount_start_date) && (! $item->product->discount_end_date || $now <= $item->product->discount_end_date)) ? $discountPrice : $basePrice;

    //                             $finalItemPrices[$item->id] = $normalPrice;
    //                         }
    //                     }

    //                 } else {
    //                     foreach ($items as $item) {
    //                         $prices = is_string($item->product->prices) ? json_decode($item->product->prices, true) : ($item->product->prices ?? []);
    //                         $discountPrices = is_string($item->product->discount_prices) ? json_decode($item->product->discount_prices, true) : ($item->product->discount_prices ?? []);
    //                         $basePrice = $prices[$currency] ?? $item->product->price;
    //                         $discountPrice = $discountPrices[$currency] ?? $item->product->discount_price;

    //                         $normalPrice = (! empty($discountPrice) && (! $item->product->discount_start_date || $now >= $item->product->discount_start_date) && (! $item->product->discount_end_date || $now <= $item->product->discount_end_date)) ? $discountPrice : $basePrice;

    //                         $totalAmount += ($item->quantity * $normalPrice);
    //                         $finalItemPrices[$item->id] = $normalPrice;

    //                         $productName = $item->product->name.(! empty($item->color) ? ' - '.$item->color : '');
    //                         $gatewayItems[] = [
    //                             'name' => $productName,
    //                             'quantity' => $item->quantity,
    //                             'price' => (int) $normalPrice,
    //                             'category' => 'PHYSICAL_PRODUCT',
    //                         ];
    //                     }
    //                 }
    //             }

    //             $promoDiscountAmount = 0;
    //             $appliedPromoCode = null;

    //             if (!empty($request->promo_code)) {
    //                 $promoCode = strtoupper(trim($request->promo_code));

    //                 // 👇 [BARU] DOUBLE-CHECK VOUCHER TAS (SUBSIDI 3.4 JT) 👇
    //                 // if ($promoCode === 'SOLHOST34') {
    //                 //     $totalQuantityInCart = $cartItems->sum('quantity');

    //                 //     if ($totalQuantityInCart > 1) {
    //                 //         throw new \Exception('Voucher Subsidi Tas hanya berlaku jika keranjang Anda berisi tepat 1 tas saja.');
    //                 //     }

    //                 //     $item = $cartItems->first();
    //                 //     $catCode = strtoupper(trim($item->product->category->code ?? ''));

    //                 //     if (!in_array($catCode, ['C001', 'C002', 'C003', 'C004'])) {
    //                 //         throw new \Exception('Voucher ini khusus untuk produk kategori Tas.');
    //                 //     }

    //                 //     $claimCheck = PromoClaim::where('email', $lockedUser->email)->where('promo_code', 'SOLHOST34')->where('is_used', true)->first();
    //                 //     if ($claimCheck) throw new \Exception('Anda sudah pernah menggunakan voucher tas ini.');

    //                 //     // Jika tembus, berikan diskon 3.4 Juta!
    //                 //     $promoDiscountAmount = 3400000;
    //                 //     $appliedPromoCode = 'SOLHOST34';

    //                 //     // Tandai digunakan agar tidak diexploitasi ulang
    //                 //     PromoClaim::updateOrCreate(
    //                 //         ['email' => $lockedUser->email, 'promo_code' => 'SOLHOST34'],
    //                 //         ['is_used' => true, 'used_at' => now(), 'discount_value' => 3400000, 'expires_at' => now()->addDays(365)]
    //                 //     );
    //                 // }

    //                 // ==========================================================
    //                 // 👇 [BARU] INTEGRASI PROMO 17 AGUSTUS (SOLHER17) 👇
    //                 // ==========================================================
    //                 // if ($promoCode === 'SOLHER17') {
    //                 //     // Sistem Anda hanya mengizinkan 1 voucher per transaksi, jadi array voucher lain kita kirim kosong []
    //                 //     $promoResult = $promoService->calculatePromo($cartItems, []);

    //                 //     if (!$promoResult['is_valid']) {
    //                 //         // Lempar error jika tidak memenuhi syarat (beda tanggal, kurang dari 699k, dll)
    //                 //         throw new \Exception($promoResult['message']);
    //                 //     }

    //                 //     // Jika tembus validasi, berikan nilai diskon ke variabel keranjang
    //                 //     $promoDiscountAmount = $promoResult['discount_amount'];
    //                 //     $appliedPromoCode = $promoResult['code'];
    //                 // }
    //                 // ==========================================================
    //                 // 👇 VOUCHER SUBSIDI TAS EKSIS 👇
    //                 // ==========================================================

    //                 // ==========================================================
    //                 // 👇 [BARU] INTEGRASI PROMO 17 AGUSTUS (SOLHER17) 👇
    //                 // ==========================================================
    //                 if ($promoCode === 'SOLHER17') {
    //                     // CEK KE DATABASE: Apakah email ini beneran input email di pop-up?
    //                     $claimCheck = \App\Models\PromoClaim::where('email', $lockedUser->email)
    //                         ->where('promo_code', 'SOLHER17')
    //                         ->lockForUpdate()
    //                         ->first();

    //                     // Tolak jika ngakalin masukin kode manual tanpa subscribe popup
    //                     if (!$claimCheck) {
    //                         throw new \Exception('Akses ditolak: Anda belum mengklaim promo ini. Silakan daftar via pop-up di Beranda terlebih dahulu.');
    //                     }

    //                     // Tolak jika dipakai dua kali
    //                     if ($claimCheck->is_used) {
    //                         throw new \Exception('Voucher SOLHER17 Anda sudah hangus/terpakai.');
    //                     }

    //                     $promoResult = $promoService->calculatePromo($cartItems, []);

    //                     if (!$promoResult['is_valid']) {
    //                         throw new \Exception($promoResult['message']);
    //                     }

    //                     $promoDiscountAmount = $promoResult['discount_amount'];
    //                     $appliedPromoCode = $promoResult['code'];

    //                     // JIKA BERHASIL: Tandai voucher di database telah dipakai!
    //                     $claimCheck->update(['is_used' => true, 'used_at' => now()]);
    //                 }
    //                 // ==========================================================

    //                 elseif ($promoCode === 'SOLHOST34') {
    //                     $totalQuantityInCart = $cartItems->sum('quantity');

    //                     if ($totalQuantityInCart > 1) {
    //                         throw new \Exception('Voucher Subsidi Tas hanya berlaku jika keranjang Anda berisi tepat 1 barang saja.');
    //                     }

    //                     $item = $cartItems->first();
    //                     $catCode = strtoupper(trim($item->product->category->code ?? ''));

    //                     if (!in_array($catCode, ['C001', 'C002', 'C003', 'C004'])) {
    //                         throw new \Exception('Voucher ini khusus untuk produk kategori Tas.');
    //                     }

    //                     // 👇 [BARU] CEGAH GABUNG DENGAN POIN LOYALITAS 👇
    //                     if ($request->use_points > 0) {
    //                         throw new \Exception('Voucher Subsidi Tas tidak dapat digabungkan dengan penukaran Poin Loyalitas.');
    //                     }

    //                     // 👇 [BARU] CEGAH GABUNG DENGAN PRODUK YANG SEDANG DISKON (SALE) 👇
    //                     $product = $item->product;
    //                     if (
    //                         !empty($product->discount_price) &&
    //                         (!$product->discount_start_date || $now >= $product->discount_start_date) &&
    //                         (!$product->discount_end_date || $now <= $product->discount_end_date)
    //                     ) {
    //                         throw new \Exception('Voucher ini tidak dapat digunakan pada tas yang sedang dalam masa harga diskon (Sale).');
    //                     }

    //                     $claimCheck = PromoClaim::where('email', $lockedUser->email)->where('promo_code', 'SOLHOST34')->where('is_used', true)->first();
    //                     if ($claimCheck) throw new \Exception('Anda sudah pernah menggunakan voucher tas ini.');

    //                     // Jika tembus, berikan diskon & tandai voucher
    //                     $promoDiscountAmount = 3400000;
    //                     $appliedPromoCode = 'SOLHOST34';

    //                     PromoClaim::updateOrCreate(
    //                         ['email' => $lockedUser->email, 'promo_code' => 'SOLHOST34'],
    //                         ['is_used' => true, 'used_at' => now(), 'discount_value' => 3400000, 'expires_at' => now()->addDays(365)]
    //                     );
    //                 }
    //                 // 👆 ======================================================= 👆
    //                 elseif ($promoCode === 'SOLHERMEMBER') {
    //                     if (! $lockedUser->is_membership) {
    //                         throw new \Exception('Voucher ini eksklusif hanya untuk pengguna dengan status VIP Member.');
    //                     }
    //                     if ($lockedUser->has_used_member_voucher) {
    //                         throw new \Exception('Anda sudah pernah menggunakan voucher member VIP ini sebelumnya.');
    //                     }

    //                     $promoDiscountAmount = ($currency === 'IDR') ? 500000 : 35; // Misal $35 jika USD
    //                     $appliedPromoCode = 'SOLHERMEMBER';

    //                     $lockedUser->update(['has_used_member_voucher' => true]);
    //                 } else {
    //                     $promoClaim = PromoClaim::where('email', $lockedUser->email)->where('promo_code', $promoCode)->lockForUpdate()->first();

    //                     if (! $promoClaim) {
    //                         throw new \Exception('Kode Promo tidak valid untuk akun email ini.');
    //                     }
    //                     if ($promoClaim->is_used) {
    //                         throw new \Exception('Kode Promo sudah pernah digunakan.');
    //                     }

    //                     $minPurchase = ($currency === 'IDR') ? 499000 : 35;
    //                     if ($totalAmount < $minPurchase) {
    //                         $currencyText = ($currency === 'IDR') ? 'Rp 499.000' : '$'.$minPurchase;
    //                         throw new \Exception("Minimum purchase to use this promo is {$currencyText}");
    //                     }

    //                     $promoDiscountAmount = min($promoClaim->discount_value, $totalAmount);
    //                     $appliedPromoCode = $promoClaim->promo_code;

    //                     $promoClaim->update(['is_used' => true, 'used_at' => now()]);
    //                 }
    //             }

    //             $totalAfterPromo = max(0, $totalAmount - $promoDiscountAmount);

    //             $orderId = 'SOL-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
    //             $earnedPoints = $lockedUser->is_membership ? floor($totalAmount / 100000) : 0;
    //             $pointsUsed = 0;
    //             $pointDiscountAmount = 0;

    //             if ($request->use_points > 0 && $lockedUser->is_membership) {
    //                 $pointsUsed = min($request->use_points, $lockedUser->point);
    //                 $maxUsableDiscount = min($pointsUsed * 1000, $totalAfterPromo);
    //                 $pointDiscountAmount = $maxUsableDiscount;
    //                 $actualPointsDeducted = floor($maxUsableDiscount / 1000);
    //                 $pointsUsed = $actualPointsDeducted;

    //                 if ($pointsUsed > 0) {
    //                     $lockedUser->decrement('point', $pointsUsed);
    //                 }
    //             }

    //             $totalShippingCost = $request->shipping_method === 'free' ? 0 : ($request->shipping_cost ?? 0);

    //             $affiliateId = null;
    //             $commissionEarned = 0;
    //             $commissionStatus = null;

    //             if (! empty($request->referral_code)) {
    //                 $affiliateUser = User::where('referral_code', $request->referral_code)->where('is_affiliate', true)->first();
    //                 if ($affiliateUser && $affiliateUser->id !== $lockedUser->id) {
    //                     $affiliateId = $affiliateUser->id;
    //                     $commissionRate = $affiliateUser->commission_rate ?? 5.00;
    //                     $commissionEarned = $totalAmount * ($commissionRate / 100);
    //                     $commissionStatus = 'pending';
    //                 }
    //             }

    //             $transaction = Transaction::create([
    //                 'user_id' => $lockedUser->id,
    //                 'address_id' => $request->address_id,
    //                 'shipping_method' => $request->shipping_method,
    //                 'shipping_cost' => $totalShippingCost,
    //                 'courier_company' => $request->shipping_method === 'free' ? 'Internal' : $request->courier_company,
    //                 'courier_type' => $request->shipping_method === 'free' ? 'Next Day' : $request->courier_type,
    //                 'delivery_type' => $request->shipping_method === 'free' ? 'later' : ($request->delivery_type ?? 'later'),
    //                 'order_id' => $orderId,
    //                 'total_amount' => $totalAmount,
    //                 'affiliate_id' => $affiliateId,
    //                 'commission_earned' => $commissionEarned,
    //                 'commission_status' => $commissionStatus,
    //                 'status' => 'pending',
    //                 'point' => $earnedPoints,
    //                 'points_used' => $pointsUsed,
    //                 'promo_code' => $appliedPromoCode,
    //                 'promo_discount' => $promoDiscountAmount,
    //                 'currency_code' => $currency,
    //             ]);

    //             foreach ($cartItems as $item) {
    //                 $product = Product::lockForUpdate()->find($item->product_id);
    //                 if ($product->stock < $item->quantity) {
    //                     throw new \Exception("Stock {$product->name} insufficient");
    //                 }

    //                 $savedPrice = $finalItemPrices[$item->id] ?? $product->price;

    //                 TransactionDetail::create([
    //                     'transaction_id' => $transaction->id,
    //                     'product_id' => $item->product_id,
    //                     'quantity' => $item->quantity,
    //                     'price' => $savedPrice,
    //                     'color' => $item->color,
    //                 ]);

    //                 $remainingQuantityToDeduct = $item->quantity;
    //                 $totalBatchQuantity = ProductStock::where('product_id', $product->id)->sum('quantity');
    //                 $legacyStock = $product->stock - $totalBatchQuantity;

    //                 if ($legacyStock > 0) {
    //                     $takeFromLegacy = min($remainingQuantityToDeduct, $legacyStock);
    //                     ProductStock::create([
    //                         'product_id' => $product->id,
    //                         'batch_code' => 'SYS-LEGACY-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4)),
    //                         'quantity' => 0,
    //                         'initial_quantity' => $takeFromLegacy,
    //                     ]);
    //                     $remainingQuantityToDeduct -= $takeFromLegacy;
    //                 }

    //                 if ($remainingQuantityToDeduct > 0) {
    //                     $activeBatches = ProductStock::where('product_id', $product->id)->where('quantity', '>', 0)->orderBy('created_at', 'asc')->lockForUpdate()->get();
    //                     foreach ($activeBatches as $batch) {
    //                         if ($remainingQuantityToDeduct <= 0) {
    //                             break;
    //                         }
    //                         if ($batch->quantity >= $remainingQuantityToDeduct) {
    //                             $batch->decrement('quantity', $remainingQuantityToDeduct);
    //                             $remainingQuantityToDeduct = 0;
    //                         } else {
    //                             $remainingQuantityToDeduct -= $batch->quantity;
    //                             $batch->update(['quantity' => 0]);
    //                         }
    //                     }
    //                 }

    //                 if ($remainingQuantityToDeduct > 0) {
    //                     throw new \Exception("System error: Stock batch mismatch for '{$product->name}'.");
    //                 }
    //                 $product->decrement('stock', $item->quantity);
    //             }

    //             return [
    //                 'transaction' => $transaction,
    //                 'gatewayItems' => $gatewayItems,
    //                 'totalAmount' => $totalAmount,
    //                 'totalShippingCost' => $totalShippingCost,
    //                 'pointDiscountAmount' => $pointDiscountAmount,
    //                 'pointsUsed' => $pointsUsed,
    //                 'totalQuantity' => $cartItems->sum('quantity') ?: 1,
    //                 'promoCode' => $appliedPromoCode,
    //                 'promoDiscountAmount' => $promoDiscountAmount,
    //                 'currency' => $currency,
    //             ];
    //         });

    //         // Lanjut ke Payment Gateway
    //         $paymentController = app(PaymentController::class);
    //         $request->merge([
    //             'transaction_id' => $transactionData['transaction']->id,
    //             'currency' => $transactionData['currency']
    //         ]);

    //         return $paymentController->createInvoice($request);

    //     } catch (\Throwable $e) {
    //         report($e);
    //         Log::error('CHECKOUT FATAL ERROR: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
    //         return response()->json(['message' => 'Internal Server Error: '.$e->getMessage()], 500);
    //     }
    // }

    public function checkout(
        Request $request,
        PromoMerdekaService $promoService,
        CalculateCartTotalsAction $calculateTotals,
        CreateTransactionAction $createTransaction,
        DeductInventoryAction $deductInventory
    ) {
        try {
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
                'currency' => 'required|string',
                'referral_code' => 'nullable|string',
            ]);

            $user = $request->user();

            $cartItems = Cart::with('product.category')
                ->where('user_id', $user->id)
                ->whereIn('id', $request->cart_ids)
                ->get();

            if ($cartItems->isEmpty()) {
                return response()->json(['message' => 'No items selected for checkout'], 400);
            }

            $transactionData = DB::transaction(function () use ($user, $cartItems, $request, $promoService, $calculateTotals, $createTransaction, $deductInventory) {
                // 1. Kunci Baris User (Mencegah Race Condition Saldo Poin)
                $lockedUser = User::lockForUpdate()->find($user->id);

                // 2. ACTION: Kalkulasi Harga, Promo, dan Poin
                $totals = $calculateTotals->execute($lockedUser, $cartItems, $request, $promoService);

                // 3. ACTION: Buat Transaksi Baru
                $transaction = $createTransaction->execute($lockedUser, $request, $totals);

                // 4. ACTION: Potong Stok Inventory FIFO
                $deductInventory->execute($transaction, $cartItems, $totals['finalItemPrices']);

                return [
                    'transaction' => $transaction,
                    'currency' => $request->currency,
                ];
            });

            // 👇 [BARU] TEMBAKKAN EVENT SAAT PESANAN BARU BERHASIL DIBUAT 👇
            event(new \App\Events\DashboardUpdated());

            // Lanjut ke Payment Gateway
            $paymentController = app(PaymentController::class);
            $request->merge([
                'transaction_id' => $transactionData['transaction']->id,
                'currency' => $transactionData['currency']
            ]);

            return $paymentController->createInvoice($request);
        } catch (\Throwable $e) {
            report($e);
            Log::error('CHECKOUT FATAL ERROR: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['message' => 'Internal Server Error: ' . $e->getMessage()], 500);
        }
    }

    public function index(Request $request)
    {
        // Eager load 'payment' untuk mendapatkan checkout_url
        $transactions = Transaction::with(['details.product', 'payment', 'address'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json($transactions);
    }

    // public function index(Request $request)
    // {
    //     $transactions = Transaction::with(['details.product', 'payment', 'address'])
    //         ->where('user_id', $request->user()->id)
    //         ->latest()
    //         ->paginate(20); // User cukup lihat 20 transaksi per halaman

    //     return response()->json($transactions);
    // }

    // Melihat semua transaksi (Sisi Admin)
    public function allTransactions()
    {
        // Menambahkan relasi 'address' agar data penerima dan kodepos bisa dirender di Vue
        $transactions = Transaction::with(['user', 'details.product', 'address'])
            ->latest()
            ->get();

        return response()->json($transactions);
    }

    // public function allTransactions()
    // {
    //     // Hanya ambil 50 data per halaman. Sangat ringan untuk RAM server!
    //     $transactions = Transaction::with(['user', 'details.product', 'address'])
    //         ->latest()
    //         ->paginate(50);

    //     return response()->json($transactions);
    // }

    // public function cancelOrder(Request $request, $id)
    // {
    //     $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

    //     if (!in_array($transaction->status, ['awaiting_payment', 'pending', 'processing'])) {
    //         return response()->json(['message' => 'Cannot cancel this order.'], 400);
    //     }

    //     // PRE-CHECK BITESHIP (Berjalan di luar transaksi database agar tidak memberatkan server)
    //     if ($transaction->status === 'processing' && $transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
    //         try {
    //             $res = Http::withHeaders([
    //                 'Authorization' => config('services.biteship.api_key'),
    //             ])->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

    //             if ($res->successful()) {
    //                 $data = $res->json();
    //                 $biteshipStatus = strtolower($data['status'] ?? '');

    //                 $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'return_in_transit', 'returned', 'disposed'];
    //                 if (in_array($biteshipStatus, $unCancellableStatuses)) {
    //                     return response()->json([
    //                         'message' => 'Cannot cancel: The package is already being processed by the courier.',
    //                     ], 400);
    //                 }

    //                 Http::withHeaders([
    //                     'Authorization' => config('services.biteship.api_key'),
    //                 ])->delete('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);
    //             }
    //         } catch (\Exception $e) {
    //             report($e);

    //             return response()->json(['message' => 'Failed to verify logistics status with Biteship.'], 500);
    //         }

    //         // AUTO-REFUND XENDIT
    //         try {
    //             $transaction->load('payment');
    //             if ($transaction->payment && $transaction->payment->external_id) {
    //                 $invoiceApi = new InvoiceApi;
    //                 $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

    //                 if (!empty($invoices) && count($invoices) > 0) {
    //                     $xenditInvoiceId = $invoices[0]['id'];
    //                     $refundApi = new RefundApi;

    //                     $refundRequest = new CreateRefund([
    //                         'invoice_id' => $xenditInvoiceId,
    //                         'reason' => 'REQUESTED_BY_CUSTOMER',
    //                         'amount' => (int) $transaction->total_amount,
    //                         'metadata' => ['order_id' => $transaction->order_id],
    //                     ]);

    //                     $refundApi->createRefund(null, null, $refundRequest);
    //                 }
    //             }
    //         } catch (\Exception $e) {
    //             report($e);
    //             // JIKA REFUND GAGAL (TAPI KURIR SUDAH DIBATALKAN), LEMPAR KE REFUND MANUAL TAPI KEMBALIKAN STOKNYA
    //             DB::transaction(function () use ($transaction) {
    //                 $transaction->update(['status' => 'refund_manual_required']);
    //                 foreach ($transaction->details as $detail) {
    //                     // [PERBAIKAN] Mengembalikan stok pakai FIFO Restore
    //                     $this->restoreProductStock($detail->product_id, $detail->quantity);
    //                 }
    //             });

    //             // 👇 [BARU] TEMBAKKAN EVENT 👇
    //             event(new \App\Events\DashboardUpdated());

    //             return response()->json(['message' => 'Order cancelled, but automatic refund failed. Admin will process it manually.']);
    //         }
    //     }

    //     // [PENTING] Bungkus pembatalan status dan pengembalian stok dalam DB Transaction
    //     DB::transaction(function () use ($transaction) {
    //         // Re-fetch dan Lock untuk mencegah error paralel
    //         $lockedTransaction = Transaction::lockForUpdate()->find($transaction->id);

    //         if ($lockedTransaction->status !== 'refund_manual_required' && $lockedTransaction->status !== 'cancelled') {
    //             $lockedTransaction->update([
    //                 'status' => 'cancelled',
    //                 'shipping_status' => 'cancelled',  // [PERBAIKAN] Sinkronisasi status pengiriman
    //             ]);

    //             // [PERBAIKAN] KEMBALIKAN POIN YANG HANGUS
    //             if ($lockedTransaction->points_used > 0) {
    //                 $lockedTransaction->user->increment('point', $lockedTransaction->points_used);
    //             }

    //             // [BARU] KEMBALIKAN PROMO CODE JIKA TRANSAKSI BATAL
    //             // if ($lockedTransaction->promo_code) {
    //             //     PromoClaim::where('email', $lockedTransaction->user->email)
    //             //         ->where('promo_code', $lockedTransaction->promo_code)
    //             //         ->update(['is_used' => false, 'used_at' => null]);
    //             // }

    //             if ($lockedTransaction->promo_code) {
    //                 if ($lockedTransaction->promo_code === 'SOLHERMEMBER') {
    //                     // Kembalikan hak pakai voucher member
    //                     $lockedTransaction->user->update(['has_used_member_voucher' => false]);
    //                 } else {
    //                     // Kembalikan kode promo biasa
    //                     PromoClaim::where('email', $lockedTransaction->user->email)
    //                         ->where('promo_code', $lockedTransaction->promo_code)
    //                         ->update(['is_used' => false, 'used_at' => null]);
    //                 }
    //             }

    //             if ($lockedTransaction->payment) {
    //                 $lockedTransaction->payment->update(['status' => 'EXPIRED']);
    //             }

    //             // [PERBAIKAN] Mengembalikan stok pakai FIFO Restore
    //             foreach ($lockedTransaction->details as $detail) {
    //                 $this->restoreProductStock($detail->product_id, $detail->quantity);
    //             }
    //         }
    //     });

    //     // Cache::tags(['catalog'])->flush();

    //     foreach ($transaction->details as $detail) {
    //         Cache::tags(['catalog'])->forget("products.detail.{$detail->product_id}");
    //     }

    //     // 👇 [BARU] TEMBAKKAN EVENT 👇
    //     event(new \App\Events\DashboardUpdated());

    //     return response()->json(['message' => 'Order cancelled successfully']);
    // }

    public function cancelOrder(Request $request, $id, CancelTransactionAction $cancelTransaction, BiteshipService $biteship)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

        if (!in_array($transaction->status, ['awaiting_payment', 'pending', 'processing'])) {
            return response()->json(['message' => 'Cannot cancel this order.'], 400);
        }

        try {
            $result = $cancelTransaction->execute($transaction, $biteship);

            $this->clearTransactionProductCache($transaction);
            $this->revokeMembershipIfBelowThreshold($transaction->user);

            event(new \App\Events\DashboardUpdated());

            return response()->json(['message' => $result['message']]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function confirmComplete(Request $request, $id)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

        if ($transaction->status !== 'processing') {
            return response()->json(['message' => 'Order cannot be completed yet.'], 400);
        }

        $transaction->update(['status' => 'completed']);

        // 👇 TEMPEL KODE PENCAIRAN AFILIASI DI SINI 👇
        if ($transaction->affiliate_id && $transaction->commission_status === 'pending') {
            // 1. Ubah status komisi menjadi cair (settled)
            $transaction->update(['commission_status' => 'settled']);

            // 2. Tambahkan uangnya ke dompet afiliator
            $affiliate = User::find($transaction->affiliate_id);
            if ($affiliate) {
                $affiliate->increment('commission_balance', $transaction->commission_earned);
            }
        }

        $this->checkAndAssignMembership($transaction->user);

        // [PERBAIKAN MUTLAK] Jangan lupakan poin pelanggan yang menyelesaikan pesanan manual!
        $transaction->user->refresh();
        if ($transaction->point > 0 && $transaction->user->is_membership) {
            $transaction->user->increment('point', $transaction->point);
        }

        // 👇 [BARU] TEMBAKKAN EVENT 👇
        event(new \App\Events\DashboardUpdated());

        return response()->json(['message' => 'Order completed!']);
    }

    // public function requestRefund(Request $request, $id)
    // {
    //     $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

    //     // Validasi: Refund hanya bisa diajukan saat pesanan selesai atau gagal kirim
    //     if (!in_array($transaction->status, ['completed', 'shipping_failed'])) {
    //         return response()->json(['message' => 'Cannot request refund for this order state.'], 400);
    //     }

    //     // [BARU] Validasi input text dan file bukti (gambar atau video)
    //     $request->validate([
    //         'reason' => 'required|string|max:1000',
    //         'proof_file' => 'required|file|mimes:jpeg,png,jpg,mp4,mov|max:10240',  // Max 10MB
    //     ]);

    //     try {
    //         // [BARU] Upload file ke AWS S3
    //         $file = $request->file('proof_file');
    //         $path = $file->store('refund_proofs', [
    //             'disk' => 's3',
    //             'visibility' => 'public',
    //         ]);
    //         $proofUrl = Storage::disk('s3')->url($path);

    //         // Update transaksi
    //         $transaction->update([
    //             'status' => 'refund_requested',
    //             'refund_reason' => $request->reason,
    //             'refund_proof_url' => $proofUrl,
    //         ]);

    //         // 👇 [BARU] TEMBAKKAN EVENT 👇
    //         event(new \App\Events\DashboardUpdated());

    //         return response()->json(['message' => 'Refund requested successfully. Waiting for admin approval.']);
    //     } catch (\Exception $e) {
    //         report($e);
    //         Log::error('Failed to upload refund proof: ' . $e->getMessage());

    //         return response()->json(['message' => 'Failed to process refund request. Please try again.'], 500);
    //     }
    // }

    public function requestRefund(Request $request, $id, FileUploadService $fileUpload)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

        if (!in_array($transaction->status, ['completed', 'shipping_failed'])) {
            return response()->json(['message' => 'Cannot request refund for this order state.'], 400);
        }

        $request->validate([
            'reason' => 'required|string|max:1000',
            'proof_file' => 'required|file|mimes:jpeg,png,jpg,mp4,mov|max:10240',
        ]);

        try {
            $proofUrl = $fileUpload->uploadToS3($request->file('proof_file'), 'refund_proofs');

            $transaction->update([
                'status' => 'refund_requested',
                'refund_reason' => $request->reason,
                'refund_proof_url' => $proofUrl,
            ]);

            event(new \App\Events\DashboardUpdated());
            return response()->json(['message' => 'Refund requested successfully. Waiting for admin approval.']);

        } catch (\Exception $e) {
            Log::error('Upload refund proof gagal: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to process refund request. Please try again.'], 500);
        }
    }

    // User klik "Refund Now" setelah disetujui admin
    // public function processRefundUser(Request $request, $id)
    // {
    //     // 1. Ambil data transaksi (Tanpa Lock terlebih dahulu)
    //     $transaction = Transaction::with('payment')
    //         ->where('user_id', $request->user()->id)
    //         ->findOrFail($id);

    //     // =========================================================================
    //     // [PERBAIKAN] ATOMIC STATE TRANSITION (Pencegah Double Refund)
    //     // Kita paksa ubah statusnya di database SEBELUM memanggil API Xendit.
    //     // Jika ada 2 request masuk bersamaan, request kedua akan menghasilkan $locked = 0 (Gagal)
    //     // =========================================================================
    //     $locked = Transaction::where('id', $id)
    //         ->where('status', 'refund_approved')
    //         ->update(['status' => 'refund_processing']);  // Status sementara

    //     if (!$locked) {
    //         return response()->json(['message' => 'Refund is already being processed or not valid.'], 400);
    //     }

    //     if (!$transaction->payment) {
    //         // Rollback status karena gagal
    //         $transaction->update(['status' => 'refund_approved']);

    //         return response()->json(['message' => 'Payment data not found.'], 404);
    //     }

    //     // --- PRE-CHECK DAN EKSEKUSI PEMBATALAN KURIR ---
    //     if ($transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
    //         try {
    //             $res = Http::withHeaders([
    //                 'Authorization' => config('services.biteship.api_key'),
    //             ])->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

    //             if ($res->successful()) {
    //                 $data = $res->json();
    //                 $biteshipStatus = strtolower($data['status'] ?? '');

    //                 $unCancellableStatuses = ['picked', 'dropping_off', 'delivered', 'rejected', 'return_in_transit', 'returned'];

    //                 if (in_array($biteshipStatus, $unCancellableStatuses)) {
    //                     // Rollback status karena kurir sudah jalan
    //                     $transaction->update(['status' => 'refund_approved']);

    //                     return response()->json([
    //                         'message' => 'Cannot process refund: The package is already in transit or has issues. Please contact logistics.',
    //                     ], 400);
    //                 }

    //                 // JIKA AMAN, BATALKAN KURIR
    //                 if (!in_array($biteshipStatus, ['cancelled'])) {
    //                     $cancelRes = Http::withHeaders([
    //                         'Authorization' => config('services.biteship.api_key'),
    //                     ])->delete('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

    //                     $cancelData = $cancelRes->json();
    //                     if (isset($cancelData['success']) && $cancelData['success'] === false) {
    //                         $transaction->update(['status' => 'refund_approved']);  // Rollback

    //                         return response()->json([
    //                             'message' => 'Failed to cancel courier. Refund aborted to prevent loss.',
    //                         ], 400);
    //                     }
    //                 }
    //             }
    //         } catch (\Exception $e) {
    //             report($e);
    //             $transaction->update(['status' => 'refund_approved']);  // Rollback
    //             Log::error('Biteship Pre-Check Error: ' . $e->getMessage());

    //             return response()->json(['message' => 'Failed to verify logistics status. Try again later.'], 500);
    //         }
    //     }

    //     // --- EKSEKUSI REFUND KE XENDIT ---
    //     try {
    //         $invoiceApi = new InvoiceApi;
    //         $invoices = $invoiceApi->getInvoices(null, $transaction->payment->external_id);

    //         if (empty($invoices) || count($invoices) === 0) {
    //             throw new \Exception('Invoice not found in Xendit.');
    //         }

    //         $xenditInvoiceId = $invoices[0]['id'];
    //         $refundApi = new RefundApi;

    //         $refundRequest = new CreateRefund([
    //             'invoice_id' => $xenditInvoiceId,
    //             'reason' => 'REQUESTED_BY_CUSTOMER',
    //             'amount' => (int) $transaction->total_amount,
    //             'metadata' => ['order_id' => $transaction->order_id],
    //         ]);

    //         $refundApi->createRefund(null, null, $refundRequest);

    //         // Jika Xendit sukses, update ke status Akhir (Refunded)
    //         DB::transaction(function () use ($transaction) {
    //             $transaction->update(['status' => 'refunded']);
    //             if ($transaction->payment) {
    //                 $transaction->payment->update(['status' => 'REFUNDED']);
    //             }

    //             // // Pengembalian poin yang dipakai ada di Fix Bencana 2 di bawah

    //             // foreach ($transaction->details as $detail) {
    //             //     $this->restoreProductStock($detail->product_id, $detail->quantity);
    //             // }

    //             // [PERBAIKAN MUTLAK: ANTI DOUBLE RESTOCK]
    //             // Hanya kembalikan stok jika belum pernah dibatalkan sebelumnya
    //             // Jika pesanan gagal dari processing langsung refund, kita restore.
    //             // TAPI jika sebelumnya sudah refund_manual_required/cancelled, stok SUDAH KEMBALI.
    //             $statusesThatAlreadyRestoredStock = ['refund_manual_required', 'cancelled', 'shipping_failed', 'returned'];

    //             // Gunakan status dari instance sebelum diupdate (karena di atas sudah diupdate ke 'refunded')
    //             $originalStatus = $transaction->getOriginal('status');

    //             if (!in_array($originalStatus, $statusesThatAlreadyRestoredStock)) {
    //                 foreach ($transaction->details as $detail) {
    //                     $this->restoreProductStock($detail->product_id, $detail->quantity);
    //                 }
    //             }
    //         });

    //         // Cache::tags(['catalog'])->flush();

    //         foreach ($transaction->details as $detail) {
    //             Cache::tags(['catalog'])->forget("products.detail.{$detail->product_id}");
    //         }

    //         // 👇 [BARU] TEMBAKKAN EVENT 👇
    //         event(new \App\Events\DashboardUpdated());

    //         return response()->json([
    //             'message' => 'Refund processed successfully. Funds returned automatically.',
    //             'type' => 'automatic',
    //         ]);
    //     } catch (XenditSdkException $e) {
    //         report($e);
    //         $errorMessage = $e->getMessage();

    //         if (str_contains(strtolower($errorMessage), 'not supported for this channel')) {
    //             DB::transaction(function () use ($transaction) {
    //                 $transaction->update(['status' => 'refund_manual_required']);
    //                 foreach ($transaction->details as $detail) {
    //                     $this->restoreProductStock($detail->product_id, $detail->quantity);
    //                 }
    //             });

    //             // Cache::tags(['catalog'])->flush();

    //             foreach ($transaction->details as $detail) {
    //                 Cache::tags(['catalog'])->forget("products.detail.{$detail->product_id}");
    //             }

    //             // 👇 [BARU] TEMBAKKAN EVENT 👇
    //             event(new \App\Events\DashboardUpdated());

    //             return response()->json([
    //                 'message' => 'Automatic refund not supported. Status updated to Manual Check. Courier has been cancelled.',
    //                 'code' => 'MANUAL_REFUND_NEEDED',
    //             ], 200);
    //         }

    //         $transaction->update(['status' => 'refund_approved']);  // Rollback

    //         return response()->json(['message' => 'Xendit Refund Failed: ' . $errorMessage], 422);
    //     } catch (\Exception $e) {
    //         report($e);
    //         $transaction->update(['status' => 'refund_approved']);  // Rollback

    //         return response()->json(['message' => 'Refund Error: ' . $e->getMessage()], 500);
    //     }
    // }

    public function processRefundUser(Request $request, $id, ProcessRefundAction $processRefund, BiteshipService $biteship)
    {
        try {
            $result = $processRefund->execute($id, $biteship);

            $transaction = Transaction::with(['details', 'user'])->find($id);
            $this->clearTransactionProductCache($transaction);
            $this->revokeMembershipIfBelowThreshold($transaction->user);

            event(new \App\Events\DashboardUpdated());

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
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
            report($e);
            // Jika gagal kirim email, jangan hentikan proses approve
            Log::error("Gagal kirim email Approve Refund ke {$transaction->user->email}: " . $e->getMessage());
        }

        // 👇 [BARU] TEMBAKKAN EVENT 👇
        event(new \App\Events\DashboardUpdated());

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
            report($e);
            // Jika gagal kirim email, jangan hentikan proses reject
            Log::error("Gagal kirim email Reject Refund ke {$transaction->user->email}: " . $e->getMessage());
        }

        event(new \App\Events\DashboardUpdated());

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

    // public function salesReport(Request $request)
    // {
    //     $month = $request->query('month');
    //     $year = $request->query('year');
    //     $search = $request->query('search');

    //     $query = TransactionDetail::query()
    //         ->select(
    //             'products.id',
    //             'products.code',
    //             'products.name',
    //             'products.image',
    //             'categories.name as category_name',
    //             DB::raw('SUM(transaction_details.quantity) as total_sold'),
    //             DB::raw('SUM(transaction_details.quantity * transaction_details.price) as total_revenue')
    //         )
    //         ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
    //         ->join('products', 'products.id', '=', 'transaction_details.product_id')
    //         ->join('categories', 'categories.id', '=', 'products.category_id')
    //         ->whereIn('transactions.status', ['completed', 'refund_rejected']);

    //     if ($month && $year) {
    //         $query->whereMonth('transactions.created_at', $month)
    //             ->whereYear('transactions.created_at', $year);
    //     } elseif ($year) {
    //         $query->whereYear('transactions.created_at', $year);
    //     }

    //     if ($search) {
    //         $query->where(function ($q) use ($search) {
    //             $q->where('products.name', 'like', "%{$search}%")
    //                 ->orWhere('products.code', 'like', "%{$search}%");
    //         });
    //     }

    //     // [PERBAIKAN] Gunakan get() alih-alih paginate() untuk memberikan seluruh data ke Vue
    //     $report = $query->groupBy('products.id', 'products.code', 'products.name', 'products.image', 'categories.name')
    //         ->orderByDesc('total_revenue')
    //         ->get();

    //     return response()->json([
    //         'data' => $report, // Format ini kita pertahankan agar Frontend tetap konsisten mengambil res.data.data
    //     ]);
    // }

    public function salesReport(Request $request)
    {
        $month = $request->query('month');
        $year = $request->query('year');
        $search = $request->query('search');

        // Kita kini melakukan query ke tabel Agregat (Data Warehouse), BUKAN ke tabel transaksional mentah.
        // Sangat ringan karena tidak perlu JOIN tabel.
        $query = \App\Models\MonthlySalesAggregate::query()
            ->select(
                'product_id as id',
                'product_code as code',
                'product_name as name',
                'product_image as image',
                'category_name',
                DB::raw('SUM(total_sold) as total_sold'),
                DB::raw('SUM(total_revenue) as total_revenue')
            );

        if ($month && $year) {
            $query->where('month', $month)->where('year', $year);
        } elseif ($year) {
            $query->where('year', $year);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q
                    ->where('product_name', 'like', "%{$search}%")
                    ->orWhere('product_code', 'like', "%{$search}%");
            });
        }

        // Kita tetap melakukan grouping akhir karena jika admin tidak memilih bulan (hanya tahun),
        // kita perlu menjumlahkan bulan 1 sampai 12 untuk produk yang sama
        $report = $query
            ->groupBy('product_id', 'product_code', 'product_name', 'product_image', 'category_name')
            ->orderByDesc('total_revenue')
            ->get();

        return response()->json([
            'data' => $report,
        ]);
    }

    // public function trackOrder($id)
    // {
    //     $transaction = Transaction::where('user_id', request()->user()->id)->findOrFail($id);

    //     // [PERBAIKAN] Validasi menggunakan biteship_order_id
    //     if ($transaction->shipping_method !== 'biteship' || ! $transaction->biteship_order_id) {
    //         return response()->json(['message' => 'Tracking information is not available yet.'], 400);
    //     }

    //     try {
    //         // [PERBAIKAN] Memanggil Endpoint GET Order Biteship
    //         $response = Http::withHeaders([
    //             'Authorization' => config('services.biteship.api_key'),
    //         ])->get('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);

    //         $data = $response->json();

    //         if (isset($data['success']) && $data['success'] === false) {
    //             return response()->json(['message' => $data['error'] ?? 'Order not found in Logistics'], 400);
    //         }

    //         // Kembalikan seluruh objek respon JSON dari Biteship ke Frontend
    //         return response()->json($data);
    //     } catch (\Exception $e) {
    //         report($e);

    //         return response()->json(['message' => 'Failed to retrieve tracking data: '.$e->getMessage()], 500);
    //     }
    // }

    public function trackOrder($id, BiteshipService $biteship)
    {
        $transaction = Transaction::where('user_id', request()->user()->id)->findOrFail($id);
        if ($transaction->shipping_method !== 'biteship' || !$transaction->biteship_order_id)
            return response()->json(['message' => 'Tracking unavailable.'], 400);

        try {
            $data = $biteship->getOrderTracking($transaction->biteship_order_id);
            if (isset($data['success']) && $data['success'] === false)
                return response()->json(['message' => $data['error'] ?? 'Order not found'], 400);
            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to track: ' . $e->getMessage()], 500);
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
                ])->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

                if (isset($response['success']) && $response['success'] === true) {
                    $trackingData[$transaction->id] = $response->json();
                } else {
                    $trackingData[$transaction->id] = ['status' => 'pending'];  // Fallback jika belum teralokasi
                }
            } catch (\Exception $e) {
                report($e);
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

    // public function adminBulkTrackOrders(Request $request)
    // {
    //     $request->validate([
    //         'transaction_ids' => 'required|array',
    //         'transaction_ids.*' => 'integer|exists:transactions,id',
    //     ]);

    //     // Batasi maksimal 20 tracking sekaligus agar API Biteship tidak memblokir Anda (Rate Limiting)
    //     if (count($request->transaction_ids) > 20) {
    //         return response()->json(['message' => 'Maksimal tracking massal adalah 20 pesanan sekaligus.'], 422);
    //     }

    //     $transactions = Transaction::whereIn('id', $request->transaction_ids)
    //         ->whereNotNull('biteship_order_id')
    //         ->where('shipping_method', 'biteship')
    //         ->get();

    //     if ($transactions->isEmpty()) {
    //         return response()->json([]);
    //     }

    //     // [PERBAIKAN KRITIS] Tembak API Biteship secara PARALEL (Bersamaan)
    //     $responses = Http::pool(function (Pool $pool) use ($transactions) {
    //         foreach ($transactions as $transaction) {
    //             $pool->as($transaction->id)
    //                 ->withHeaders(['Authorization' => config('services.biteship.api_key')])
    //                 ->get('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);
    //         }
    //     });

    //     $trackingData = [];

    //     // Rangkai hasil balasannya
    //     foreach ($transactions as $transaction) {
    //         $response = $responses[$transaction->id] ?? null;

    //         if ($response && $response->ok() && isset($response['success']) && $response['success'] === true) {
    //             $trackingData[$transaction->id] = $response->json();
    //         } else {
    //             $trackingData[$transaction->id] = ['status' => 'pending/error'];
    //         }
    //     }

    //     return response()->json($trackingData);
    // }

    public function adminBulkTrackOrders(Request $request, BiteshipService $biteship)
    {
        $request->validate(['transaction_ids' => 'required|array', 'transaction_ids.*' => 'integer|exists:transactions,id']);
        if (count($request->transaction_ids) > 20)
            return response()->json(['message' => 'Max 20 tracking at once.'], 422);

        $transactions = Transaction::whereIn('id', $request->transaction_ids)->whereNotNull('biteship_order_id')->where('shipping_method', 'biteship')->get();
        if ($transactions->isEmpty())
            return response()->json([]);

        // Tembak secara paralel melalui Service
        $biteshipIds = $transactions->pluck('biteship_order_id', 'id')->toArray();
        $trackingData = $biteship->getBulkTrackingParallel(array_values($biteshipIds));

        // Mapping ID Transaksi Lokal ke hasil tracking Biteship
        $finalData = [];
        foreach ($biteshipIds as $transactionId => $bId) {
            $finalData[$transactionId] = $trackingData[$bId] ?? ['status' => 'pending/error'];
        }
        return response()->json($finalData);
    }

    // Fungsi khusus Admin untuk mengambil detail tracking 1 order
    public function adminTrackOrder($id)
    {
        $transaction = Transaction::findOrFail($id);  // HAPUS filter user_id

        if ($transaction->shipping_method !== 'biteship' || !$transaction->biteship_order_id) {
            return response()->json(['message' => 'Tracking information is not available yet.'], 400);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => config('services.biteship.api_key'),
            ])->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

            $data = $response->json();

            if (isset($data['success']) && $data['success'] === false) {
                return response()->json(['message' => $data['error'] ?? 'Order not found in Logistics'], 400);
            }

            return response()->json($data);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Failed to retrieve tracking data: ' . $e->getMessage()], 500);
        }
    }

    // public function printLabel(Request $request, $id)
    // {
    //     $transaction = Transaction::findOrFail($id);

    //     if (! $transaction->biteship_order_id) {
    //         return response()->json(['message' => 'Order ID Biteship tidak ditemukan'], 404);
    //     }

    //     // Ambil query parameter dari Vue (insurance_shown, dll)
    //     $queryString = http_build_query($request->all());

    //     // Target URL Biteship (Perhatikan ini menggunakan api.biteship.com, BUKAN biteship.com)
    //     $biteshipUrl = "https://api.biteship.com/v1/orders/{$transaction->biteship_order_id}/labels?{$queryString}";

    //     try {
    //         // Tembak URL label Biteship dengan API Key kita
    //         $response = Http::withHeaders([
    //             'Authorization' => config('services.biteship.api_key'),
    //         ])->get($biteshipUrl);

    //         // Jika sukses, Biteship biasanya mengembalikan langsung file PDF (application/pdf)
    //         if ($response->successful()) {
    //             return response($response->body(), 200)
    //                 ->header('Content-Type', 'application/pdf')
    //                 ->header('Content-Disposition', 'inline; filename="Resi-'.$transaction->order_id.'.pdf"');
    //         }

    //         return response()->json(['message' => 'Gagal mengambil resi dari Biteship: '.$response->body()], 400);
    //     } catch (\Exception $e) {
    //         report($e);

    //         return response()->json(['message' => 'Terjadi kesalahan sistem: '.$e->getMessage()], 500);
    //     }
    // }

    public function printLabel(Request $request, $id, BiteshipService $biteship)
    {
        $transaction = Transaction::findOrFail($id);
        if (!$transaction->biteship_order_id)
            return response()->json(['message' => 'No Biteship ID'], 404);

        try {
            $response = $biteship->getLabelPdfResponse($transaction->biteship_order_id, http_build_query($request->all()));
            if ($response->successful()) {
                return response($response->body(), 200)
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'inline; filename="Resi-' . $transaction->order_id . '.pdf"');
            }
            return response()->json(['message' => 'Gagal mengambil resi'], 400);
        } catch (\Exception $e) {
            return response()->json(['message' => 'System error: ' . $e->getMessage()], 500);
        }
    }

    // public function biteshipCallback(Request $request)
    // {
    //     // Validasi signature (Opsional tapi disarankan)
    //     // $signature = $request->header('biteship-signature');
    //     // $secret = config('services.biteship.webhook_secret'); // Tambahkan di config/services.php dan .env

    //     // if ($signature !== $secret) {
    //     //     Log::critical('Fake Biteship Webhook Detected!', $request->all());

    //     //     return response()->json(['message' => 'Forbidden'], 403);
    //     // }

    //     $biteshipOrderId = $request->input('order_id');
    //     $status = strtolower($request->input('status')); // picking_up, dropped, delivered, cancelled, rejected, dll
    //     $waybill = $request->input('courier_waybill_id');

    //     \Log::info('Biteship Webhook Received: ', $request->all());

    //     // [PERBAIKAN MUTLAK: DB TRANSACTION & LOCKING]
    //     return DB::transaction(function () use ($biteshipOrderId, $status, $waybill) {

    //         // $transaction = Transaction::where('biteship_order_id', $biteshipOrderId)->first();
    //         // Kunci baris ini agar webhook yang datang bersamaan harus antre!
    //         $transaction = Transaction::where('biteship_order_id', $biteshipOrderId)
    //             ->lockForUpdate()
    //             ->first();

    //         if (! $transaction) {
    //             return response()->json(['message' => 'Transaction not found'], 200);
    //         }

    //         // Mencegah proses ulang jika status sudah 'completed'
    //         if ($transaction->status === 'completed' && $status === 'delivered') {
    //             return response()->json(['message' => 'Already completed'], 200);
    //         }

    //         // [PERBAIKAN UTAMA] Selalu update shipping_status terbaru dari Webhook!
    //         $updates = ['shipping_status' => $status];

    //         // 1. Update Resi jika baru turun
    //         if ($waybill && in_array($transaction->tracking_number, ['Pending', null])) {
    //             $updates['tracking_number'] = $waybill;
    //         }

    //         // 2. Jika paket berhasil dikirim ke pembeli, otomatis selesaikan transaksi
    //         if ($status === 'delivered' && $transaction->status === 'processing') {
    //             $updates['status'] = 'completed';

    //             // ==========================================================
    //             // 👇 [BARU] CAIRKAN KOMISI KARENA BARANG SUDAH SAMPAI 👇
    //             // ==========================================================
    //             if ($transaction->affiliate_id && $transaction->commission_status === 'pending') {
    //                 $updates['commission_status'] = 'settled'; // Status komisi jadi Selesai

    //                 $affiliateUser = User::find($transaction->affiliate_id);
    //                 if ($affiliateUser) {
    //                     // Tambahkan uang ke dompet afiliator sesuai perhitungan saat checkout
    //                     $affiliateUser->increment('commission_balance', $transaction->commission_earned);
    //                 }
    //             }
    //             // ==========================================================

    //             // Simpan status transaksi agar query SUM di helper bisa menangkap transaksi ini
    //             $transaction->update($updates);

    //             // [PERBAIKAN] Cek dan jadikan member jika memenuhi syarat
    //             $this->checkAndAssignMembership($transaction->user);

    //             // Refresh data user
    //             $transaction->user->refresh();

    //             // Tambah poin user jika dia member dan transaksi punya poin
    //             if ($transaction->point > 0 && $transaction->user->is_membership) {
    //                 $transaction->user->increment('point', $transaction->point);
    //             }

    //             return response()->json(['message' => 'Webhook processed and membership checked']);
    //         }

    //         // 3. Jika logistik membatalkan pengiriman SEPIHAK
    //         if (in_array($status, ['cancelled', 'rejected']) && $transaction->status === 'processing') {
    //             $updates['status'] = 'refund_manual_required';
    //             $updates['tracking_number'] = 'Logistics Cancelled/Rejected';
    //             \Log::warning("Biteship Logistics Cancelled for Order ID: {$transaction->order_id}. Moved to Manual Refund.");
    //         }

    //         if ($status === 'disposed' && $transaction->status === 'processing') {
    //             $updates['status'] = 'shipping_failed';
    //             $updates['tracking_number'] = 'Shipping Failed';
    //             \Log::warning("Biteship Shipping Failed for Order ID: {$transaction->order_id}.");
    //         }

    //         if ($status === 'returned' && $transaction->status === 'processing') {
    //             $updates['status'] = 'returned';
    //             $updates['tracking_number'] = 'Shipping Returned';
    //             \Log::warning("Biteship Shipping Returned for Order ID: {$transaction->order_id}.");
    //         }

    //         // Eksekusi semua update ke database dalam 1 query
    //         $transaction->update($updates);

    //         // 👇 [BARU] TRIGGER PENGIRIMAN EMAIL OTOMATIS 👇
    //         // Kita lempar ke Job Antrean agar webhook langsung merespons "success" ke Biteship
    //         // tanpa menunggu proses pengiriman email selesai.
    //         SendShippingUpdateJob::dispatch($transaction->id, $status);
    //         // 👆 ========================================= 👆

    //         // 👇 [BARU] TRIGGER WEBSOCKETS REVERB/PUSHER 👇
    //         // Muat ulang data transaksi terbaru agar Frontend mendapat data segar
    //         $transaction->refresh();
    //         broadcast(new ShippingStatusUpdated($transaction, "Status pengiriman Anda telah diperbarui menjadi: " . strtoupper($status)));
    //         // 👆 ========================================= 👆

    //         return response()->json(['message' => 'Webhook processed successfully']);
    //     });
    // }

    public function biteshipCallback(Request $request)
    {
        $payload = $request->all();
        \Log::info('Biteship Webhook Received (Queued): ', ['order_id' => $payload['order_id'] ?? null]);

        // Langsung lempar ke antrean background
        \App\Jobs\ProcessBiteshipWebhookJob::dispatch($payload);

        // Kembalikan 200 OK dalam hitungan milidetik
        return response()->json(['message' => 'Webhook received and queued'], 200);
    }

    // --- [BARU] HELPER FUNGSI UNTUK CEK MEMBERSHIP ---
    // private function checkAndAssignMembership($user)
    // {
    //     // Jika user sudah member, tidak perlu cek lagi
    //     if ($user->is_membership) {
    //         return;
    //     }

    //     // Hitung total belanja dari semua transaksi yang BERHASIL (completed)
    //     $totalSpent = Transaction::where('user_id', $user->id)
    //         ->where('status', 'completed')
    //         ->sum('total_amount'); // Hanya hitung harga barang, ongkir tidak termasuk

    //     // Jika total belanja >= 100.000, jadikan member
    //     if ($totalSpent >= 100000) {
    //         $user->update(['is_membership' => true]);
    //     }
    // }

    // =====================================================================
    // 👇 FUNGSI BARU UNTUK ADMIN MENGHAPUS TRANSAKSI PERMANEN 👇
    // =====================================================================
    // public function forceDeleteTransaction(Request $request, $id)
    // {
    //     // Temukan transaksi
    //     $transaction = Transaction::with(['details', 'payment'])->find($id);

    //     if (!$transaction) {
    //         return response()->json(['message' => 'Transaksi tidak ditemukan.'], 404);
    //     }

    //     // Mulai transaksi database agar penghapusan konsisten
    //     DB::transaction(function () use ($transaction) {
    //         // 1. KEMBALIKAN STOK BARANG (Jika statusnya bukan batal/refund)
    //         // Karena jika statusnya sudah batal/refund, stoknya sudah kembali.
    //         $statusesThatAlreadyRestoredStock = ['refund_manual_required', 'cancelled', 'shipping_failed', 'returned', 'refunded'];

    //         if (!in_array($transaction->status, $statusesThatAlreadyRestoredStock)) {
    //             foreach ($transaction->details as $detail) {
    //                 $this->restoreProductStock($detail->product_id, $detail->quantity);
    //             }
    //         }

    //         // 2. KEMBALIKAN POIN (Opsional: Jika Anda ingin poin sandbox kembali)
    //         if ($transaction->points_used > 0 && !in_array($transaction->status, $statusesThatAlreadyRestoredStock)) {
    //             $transaction->user->increment('point', $transaction->points_used);
    //         }

    //         // 3. HAPUS DATA PEMBAYARAN TERKAIT
    //         if ($transaction->payment) {
    //             $transaction->payment->delete();
    //         }

    //         // 4. HAPUS DETAIL TRANSAKSI
    //         // (Atau jika Anda sudah memakai skema 'onDelete cascade' di migrasi, ini opsional.
    //         // Namun untuk amannya kita hapus manual)
    //         foreach ($transaction->details as $detail) {
    //             // Jangan lupa hapus cache per-produk yang terpengaruh
    //             Cache::tags(['catalog'])->forget("products.detail.{$detail->product_id}");
    //             $detail->delete();
    //         }

    //         // 5. HAPUS TRANSAKSI UTAMA
    //         $transaction->delete();
    //     });

    //     // Flush seluruh cache untuk keamanan
    //     Cache::flush();

    //     // 👇 [BARU] TEMBAKKAN EVENT 👇
    //     event(new \App\Events\DashboardUpdated());

    //     return response()->json(['message' => 'Transaksi berhasil dihapus secara permanen beserta stok yang dikembalikan.']);
    // }

    public function forceDeleteTransaction(Request $request, $id, BiteshipService $biteship, RestoreInventoryAction $restoreInventory)
    {
        $transaction = Transaction::with(['details', 'payment', 'user'])->find($id);

        if (!$transaction) {
            return response()->json(['message' => 'Transaksi tidak ditemukan.'], 404);
        }

        if ($transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
            try { $biteship->cancelOrder($transaction->biteship_order_id); } catch (\Exception $e) {}
        }

        DB::transaction(function () use ($transaction, $restoreInventory) {
            $statusesThatAlreadyRestoredStock = ['refund_manual_required', 'cancelled', 'shipping_failed', 'returned', 'refunded'];

            if (!in_array($transaction->status, $statusesThatAlreadyRestoredStock)) {
                foreach ($transaction->details as $detail) {
                    $restoreInventory->execute($detail->product_id, $detail->quantity);
                }
            }

            if ($transaction->points_used > 0 && !in_array($transaction->status, $statusesThatAlreadyRestoredStock)) {
                $transaction->user->increment('point', $transaction->points_used);
            }

            if ($transaction->payment) {
                $transaction->payment->delete();
            }

            $this->clearTransactionProductCache($transaction);

            foreach ($transaction->details as $detail) {
                $detail->delete();
            }

            $transaction->delete();
        });

        $this->revokeMembershipIfBelowThreshold($transaction->user);

        event(new \App\Events\DashboardUpdated());
        return response()->json(['message' => 'Transaksi berhasil dihapus secara permanen beserta stok yang dikembalikan.']);
    }
}
