<?php

// namespace App\Http\Controllers;

// use App\Models\Cart;
// use App\Models\User;
// use App\Models\Payment;
// use App\Models\Product;
// use App\Models\PromoClaim;
// use App\Models\Transaction;
// use Illuminate\Support\Str;
// use App\Models\ProductStock;
// use Illuminate\Http\Request;
// use Xendit\Refund\RefundApi;
// use App\Mail\RefundResultMail;
// use Xendit\XenditSdkException;
// use Xendit\Refund\CreateRefund;
// use App\Services\PaymentFactory;
// use Illuminate\Http\Client\Pool;
// use App\Models\TransactionDetail;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Log;
// // use Xendit\Configuration;
// // use Xendit\Invoice\CreateInvoiceRequest;
// // use Xendit\Invoice\InvoiceApi;
// use App\Jobs\SendShippingUpdateJob;
// use Illuminate\Support\Facades\Http;
// use Illuminate\Support\Facades\Mail;
// use App\Events\ShippingStatusUpdated;
// use App\Services\PromoMerdekaService;
// use Illuminate\Support\Facades\Cache;
// use Illuminate\Support\Facades\Storage;
// use App\Actions\Checkout\DeductInventoryAction;
// use App\Actions\Checkout\CreateTransactionAction;
// use App\Actions\Checkout\CalculateCartTotalsAction;

// class TransactionController extends Controller
// {
//     // public function __construct()
//     // {
//     //     Configuration::setXenditKey(config('services.xendit.secret_key'));
//     // }

//     // =================================================================================
//     // [BARU] HELPER FUNGSI UNTUK MENGEMBALIKAN STOK (FIFO RESTORE & ANTI RACE CONDITION)
//     // =================================================================================

//     // =========================================================================
//     // HELPER FUNCTIONS (Prinsip DRY - Don't Repeat Yourself)
//     // =========================================================================

//     // 1. Membersihkan Cache Produk
//     private function clearTransactionProductCache(Transaction $transaction)
//     {
//         foreach ($transaction->details as $detail) {
//             Cache::tags(['catalog'])->forget("products.detail.{$detail->product_id}");
//         }
//     }

//     // 2. Cek Naik Level Member
//     private function checkAndAssignMembership(User $user)
//     {
//         if ($user->is_membership)
//             return;
//         $totalSpent = Transaction::where('user_id', $user->id)->where('status', 'completed')->sum('total_amount');
//         if ($totalSpent >= 100000) {
//             $user->update(['is_membership' => true]);
//         }
//     }

//     // 3. Cek Turun Level Member (Jika ada pembatalan / Refund)
//     private function revokeMembershipIfBelowThreshold(User $user)
//     {
//         if (!$user->is_membership)
//             return;
//         $totalSpent = Transaction::where('user_id', $user->id)->where('status', 'completed')->sum('total_amount');
//         if ($totalSpent < 100000) {
//             $user->update(['is_membership' => false]);
//         }
//     }

//     public function restoreProductStock($productId, $quantityToRestore)
//     {
//         if ($quantityToRestore <= 0) {
//             return;
//         }

//         // 1. Kunci (Lock) baris produk utama untuk mencegah modifikasi berbarengan
//         $product = Product::lockForUpdate()->find($productId);
//         if (!$product) {
//             return;
//         }

//         $remainingToRestore = $quantityToRestore;

//         // 2. Ambil batch stok yang TIDAK PENUH (quantity < initial_quantity)
//         // Urutkan dari yang PALING LAMA (ASC) untuk mengembalikan secara FIFO
//         $incompleteBatches = ProductStock::where('product_id', $productId)
//             ->whereColumn('quantity', '<', 'initial_quantity')
//             ->orderBy('created_at', 'asc')
//             ->lockForUpdate()  // Kunci baris batch ini selama transaksi berlangsung
//             ->get();

//         foreach ($incompleteBatches as $batch) {
//             if ($remainingToRestore <= 0) {
//                 break;
//             }

//             $spaceAvailable = $batch->initial_quantity - $batch->quantity;

//             if ($spaceAvailable >= $remainingToRestore) {
//                 // Jika lubang di batch ini cukup untuk menampung semua barang kembalian
//                 $batch->increment('quantity', $remainingToRestore);
//                 $remainingToRestore = 0;
//             } else {
//                 // Jika tidak cukup, penuhi batch ini, sisanya cari di batch berikutnya
//                 $batch->increment('quantity', $spaceAvailable);
//                 $remainingToRestore -= $spaceAvailable;
//             }
//         }

//         // 3. Fallback/Penyelamat: Jika ternyata masih ada sisa (misal: batch lama terhapus manual oleh admin)
//         if ($remainingToRestore > 0) {
//             $latestBatch = ProductStock::where('product_id', $productId)
//                 ->orderBy('created_at', 'desc')
//                 ->lockForUpdate()
//                 ->first();

//             if ($latestBatch) {
//                 // Masukkan ke batch terbaru dan naikkan kapasitas awalnya agar tidak error
//                 $latestBatch->increment('quantity', $remainingToRestore);
//                 $latestBatch->increment('initial_quantity', $remainingToRestore);
//             } else {
//                 // Jika benar-benar tidak ada batch sama sekali, buat batch pengembalian khusus
//                 ProductStock::create([
//                     'product_id' => $productId,
//                     'batch_code' => 'RET-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4)),
//                     'quantity' => $remainingToRestore,
//                     'initial_quantity' => $remainingToRestore,
//                 ]);
//             }
//         }

//         // 4. Kembalikan total stok di tabel master
//         $product->increment('stock', $quantityToRestore);
//     }

//     // --- USER ACTIONS ---
//     public function checkout(
//         Request $request,
//         PromoMerdekaService $promoService,
//         CalculateCartTotalsAction $calculateTotals,
//         CreateTransactionAction $createTransaction,
//         DeductInventoryAction $deductInventory
//     ) {
//         try {
//             $request->validate([
//                 'address_id' => 'required',
//                 'shipping_method' => 'required|in:free,biteship',
//                 'use_points' => 'nullable|integer|min:0',
//                 'cart_ids' => 'required|array',
//                 'cart_ids.*' => 'exists:carts,id',
//                 'shipping_cost' => 'nullable|numeric',
//                 'courier_company' => 'nullable|string',
//                 'courier_type' => 'nullable|string',
//                 'delivery_type' => 'nullable|string',
//                 'currency' => 'required|string',
//                 'referral_code' => 'nullable|string',
//             ]);

//             $user = $request->user();

//             $cartItems = Cart::with('product.category')
//                 ->where('user_id', $user->id)
//                 ->whereIn('id', $request->cart_ids)
//                 ->get();

//             if ($cartItems->isEmpty()) {
//                 return response()->json(['message' => 'No items selected for checkout'], 400);
//             }

//             $transactionData = DB::transaction(function () use ($user, $cartItems, $request, $promoService, $calculateTotals, $createTransaction, $deductInventory) {
//                 // 1. Kunci Baris User (Mencegah Race Condition Saldo Poin)
//                 $lockedUser = User::lockForUpdate()->find($user->id);

//                 // 2. ACTION: Kalkulasi Harga, Promo, dan Poin
//                 $totals = $calculateTotals->execute($lockedUser, $cartItems, $request, $promoService);

//                 // 3. ACTION: Buat Transaksi Baru
//                 $transaction = $createTransaction->execute($lockedUser, $request, $totals);

//                 // 4. ACTION: Potong Stok Inventory FIFO
//                 $deductInventory->execute($transaction, $cartItems, $totals['finalItemPrices']);

//                 return [
//                     'transaction' => $transaction,
//                     'currency' => $request->currency,
//                 ];
//             });

//             // 👇 [BARU] TEMBAKKAN EVENT SAAT PESANAN BARU BERHASIL DIBUAT 👇
//             event(new \App\Events\DashboardUpdated());

//             // Lanjut ke Payment Gateway
//             $paymentController = app(PaymentController::class);
//             $request->merge([
//                 'transaction_id' => $transactionData['transaction']->id,
//                 'currency' => $transactionData['currency']
//             ]);

//             return $paymentController->createInvoice($request);
//         } catch (\Throwable $e) {
//             report($e);
//             Log::error('CHECKOUT FATAL ERROR: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
//             return response()->json(['message' => 'Internal Server Error: ' . $e->getMessage()], 500);
//         }
//     }

//     public function index(Request $request)
//     {
//         // Eager load 'payment' untuk mendapatkan checkout_url
//         $transactions = Transaction::with(['details.product', 'payment', 'address'])
//             ->where('user_id', $request->user()->id)
//             ->latest()
//             ->get();

//         return response()->json($transactions);
//     }

//     // public function index(Request $request)
//     // {
//     //     $transactions = Transaction::with(['details.product', 'payment', 'address'])
//     //         ->where('user_id', $request->user()->id)
//     //         ->latest()
//     //         ->paginate(20); // User cukup lihat 20 transaksi per halaman

//     //     return response()->json($transactions);
//     // }

//     // Melihat semua transaksi (Sisi Admin)
//     public function allTransactions()
//     {
//         // Menambahkan relasi 'address' agar data penerima dan kodepos bisa dirender di Vue
//         $transactions = Transaction::with(['user', 'details.product', 'address'])
//             ->latest()
//             ->get();

//         return response()->json($transactions);
//     }

//     public function cancelOrder(Request $request, $id, CancelTransactionAction $cancelTransaction, BiteshipService $biteship)
//     {
//         $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

//         if (!in_array($transaction->status, ['awaiting_payment', 'pending', 'processing'])) {
//             return response()->json(['message' => 'Cannot cancel this order.'], 400);
//         }

//         try {
//             $result = $cancelTransaction->execute($transaction, $biteship);

//             $this->clearTransactionProductCache($transaction);
//             $this->revokeMembershipIfBelowThreshold($transaction->user);

//             event(new \App\Events\DashboardUpdated());

//             return response()->json(['message' => $result['message']]);
//         } catch (\Exception $e) {
//             return response()->json(['message' => $e->getMessage()], 400);
//         }
//     }

//     public function confirmComplete(Request $request, $id)
//     {
//         $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

//         if ($transaction->status !== 'processing') {
//             return response()->json(['message' => 'Order cannot be completed yet.'], 400);
//         }

//         $transaction->update(['status' => 'completed']);

//         // 👇 TEMPEL KODE PENCAIRAN AFILIASI DI SINI 👇
//         if ($transaction->affiliate_id && $transaction->commission_status === 'pending') {
//             // 1. Ubah status komisi menjadi cair (settled)
//             $transaction->update(['commission_status' => 'settled']);

//             // 2. Tambahkan uangnya ke dompet afiliator
//             $affiliate = User::find($transaction->affiliate_id);
//             if ($affiliate) {
//                 $affiliate->increment('commission_balance', $transaction->commission_earned);
//             }
//         }

//         $this->checkAndAssignMembership($transaction->user);

//         // [PERBAIKAN MUTLAK] Jangan lupakan poin pelanggan yang menyelesaikan pesanan manual!
//         $transaction->user->refresh();
//         if ($transaction->point > 0 && $transaction->user->is_membership) {
//             $transaction->user->increment('point', $transaction->point);
//         }

//         // 👇 [BARU] TEMBAKKAN EVENT 👇
//         event(new \App\Events\DashboardUpdated());

//         return response()->json(['message' => 'Order completed!']);
//     }

//     public function requestRefund(Request $request, $id, FileUploadService $fileUpload)
//     {
//         $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

//         if (!in_array($transaction->status, ['completed', 'shipping_failed'])) {
//             return response()->json(['message' => 'Cannot request refund for this order state.'], 400);
//         }

//         $request->validate([
//             'reason' => 'required|string|max:1000',
//             'proof_file' => 'required|file|mimes:jpeg,png,jpg,mp4,mov|max:10240',
//         ]);

//         try {
//             $proofUrl = $fileUpload->uploadToS3($request->file('proof_file'), 'refund_proofs');

//             $transaction->update([
//                 'status' => 'refund_requested',
//                 'refund_reason' => $request->reason,
//                 'refund_proof_url' => $proofUrl,
//             ]);

//             event(new \App\Events\DashboardUpdated());
//             return response()->json(['message' => 'Refund requested successfully. Waiting for admin approval.']);

//         } catch (\Exception $e) {
//             Log::error('Upload refund proof gagal: ' . $e->getMessage());
//             return response()->json(['message' => 'Failed to process refund request. Please try again.'], 500);
//         }
//     }

//     // User klik "Refund Now" setelah disetujui admin
//     public function processRefundUser(Request $request, $id, ProcessRefundAction $processRefund, BiteshipService $biteship)
//     {
//         try {
//             $result = $processRefund->execute($id, $biteship);

//             $transaction = Transaction::with(['details', 'user'])->find($id);
//             $this->clearTransactionProductCache($transaction);
//             $this->revokeMembershipIfBelowThreshold($transaction->user);

//             event(new \App\Events\DashboardUpdated());

//             return response()->json($result);
//         } catch (\Exception $e) {
//             return response()->json(['message' => $e->getMessage()], 400);
//         }
//     }

//     public function approveRefund($id)
//     {
//         // [PERBAIKAN] Tambahkan with('user') agar kita bisa membaca alamat emailnya
//         $transaction = Transaction::with('user')->findOrFail($id);

//         if ($transaction->status !== 'refund_requested') {
//             return response()->json(['message' => 'Invalid status'], 400);
//         }

//         $transaction->update(['status' => 'refund_approved']);

//         // [BARU] Kirim notifikasi email ke user
//         try {
//             Mail::to($transaction->user->email)->send(new RefundResultMail($transaction, 'approve'));
//         } catch (\Exception $e) {
//             report($e);
//             // Jika gagal kirim email, jangan hentikan proses approve
//             Log::error("Gagal kirim email Approve Refund ke {$transaction->user->email}: " . $e->getMessage());
//         }

//         // 👇 [BARU] TEMBAKKAN EVENT 👇
//         event(new \App\Events\DashboardUpdated());

//         return response()->json(['message' => 'Refund request approved. Email sent to customer.']);
//     }

//     // public function rejectRefund($id)
//     // {
//     //     $transaction = Transaction::findOrFail($id);
//     //     if ($transaction->status !== 'refund_requested') {
//     //         return response()->json(['message' => 'Invalid status'], 400);
//     //     }

//     //     $transaction->update(['status' => 'refund_rejected']);
//     //     return response()->json(['message' => 'Refund request rejected.']);
//     // }

//     public function rejectRefund($id)
//     {
//         // [PERBAIKAN] Tambahkan with('user') agar kita bisa membaca alamat emailnya
//         $transaction = Transaction::with('user')->findOrFail($id);

//         if ($transaction->status !== 'refund_requested') {
//             return response()->json(['message' => 'Invalid status'], 400);
//         }

//         $transaction->update(['status' => 'refund_rejected']);

//         // [BARU] Kirim notifikasi email ke user
//         try {
//             Mail::to($transaction->user->email)->send(new RefundResultMail($transaction, 'reject'));
//         } catch (\Exception $e) {
//             report($e);
//             // Jika gagal kirim email, jangan hentikan proses reject
//             Log::error("Gagal kirim email Reject Refund ke {$transaction->user->email}: " . $e->getMessage());
//         }

//         event(new \App\Events\DashboardUpdated());

//         return response()->json(['message' => 'Refund request rejected. Email sent to customer.']);
//     }

//     // Show single transaction
//     public function show($id)
//     {
//         return response()->json(Transaction::with(['user', 'details.product', 'payment', 'address'])->findOrFail($id));
//     }

//     public function adminShow($id)
//     {
//         // Mengambil transaksi dengan relasi user, detail, dan produk di dalam detail
//         $transaction = Transaction::with(['user', 'details.product', 'address', 'payment'])
//             ->findOrFail($id);

//         return response()->json($transaction);
//     }

//     // public function salesReport(Request $request)
//     // {
//     //     $month = $request->query('month');
//     //     $year = $request->query('year');
//     //     $search = $request->query('search');

//     //     $query = TransactionDetail::query()
//     //         ->select(
//     //             'products.id',
//     //             'products.code',
//     //             'products.name',
//     //             'products.image',
//     //             'categories.name as category_name',
//     //             DB::raw('SUM(transaction_details.quantity) as total_sold'),
//     //             DB::raw('SUM(transaction_details.quantity * transaction_details.price) as total_revenue')
//     //         )
//     //         ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
//     //         ->join('products', 'products.id', '=', 'transaction_details.product_id')
//     //         ->join('categories', 'categories.id', '=', 'products.category_id')
//     //         ->whereIn('transactions.status', ['completed', 'refund_rejected']);

//     //     if ($month && $year) {
//     //         $query->whereMonth('transactions.created_at', $month)
//     //             ->whereYear('transactions.created_at', $year);
//     //     } elseif ($year) {
//     //         $query->whereYear('transactions.created_at', $year);
//     //     }

//     //     if ($search) {
//     //         $query->where(function ($q) use ($search) {
//     //             $q->where('products.name', 'like', "%{$search}%")
//     //                 ->orWhere('products.code', 'like', "%{$search}%");
//     //         });
//     //     }

//     //     // [PERBAIKAN] Gunakan get() alih-alih paginate() untuk memberikan seluruh data ke Vue
//     //     $report = $query->groupBy('products.id', 'products.code', 'products.name', 'products.image', 'categories.name')
//     //         ->orderByDesc('total_revenue')
//     //         ->get();

//     //     return response()->json([
//     //         'data' => $report, // Format ini kita pertahankan agar Frontend tetap konsisten mengambil res.data.data
//     //     ]);
//     // }

//     public function salesReport(Request $request)
//     {
//         $month = $request->query('month');
//         $year = $request->query('year');
//         $search = $request->query('search');

//         // Kita kini melakukan query ke tabel Agregat (Data Warehouse), BUKAN ke tabel transaksional mentah.
//         // Sangat ringan karena tidak perlu JOIN tabel.
//         $query = \App\Models\MonthlySalesAggregate::query()
//             ->select(
//                 'product_id as id',
//                 'product_code as code',
//                 'product_name as name',
//                 'product_image as image',
//                 'category_name',
//                 DB::raw('SUM(total_sold) as total_sold'),
//                 DB::raw('SUM(total_revenue) as total_revenue')
//             );

//         if ($month && $year) {
//             $query->where('month', $month)->where('year', $year);
//         } elseif ($year) {
//             $query->where('year', $year);
//         }

//         if ($search) {
//             $query->where(function ($q) use ($search) {
//                 $q
//                     ->where('product_name', 'like', "%{$search}%")
//                     ->orWhere('product_code', 'like', "%{$search}%");
//             });
//         }

//         // Kita tetap melakukan grouping akhir karena jika admin tidak memilih bulan (hanya tahun),
//         // kita perlu menjumlahkan bulan 1 sampai 12 untuk produk yang sama
//         $report = $query
//             ->groupBy('product_id', 'product_code', 'product_name', 'product_image', 'category_name')
//             ->orderByDesc('total_revenue')
//             ->get();

//         return response()->json([
//             'data' => $report,
//         ]);
//     }

//     // public function trackOrder($id)
//     // {
//     //     $transaction = Transaction::where('user_id', request()->user()->id)->findOrFail($id);

//     //     // [PERBAIKAN] Validasi menggunakan biteship_order_id
//     //     if ($transaction->shipping_method !== 'biteship' || ! $transaction->biteship_order_id) {
//     //         return response()->json(['message' => 'Tracking information is not available yet.'], 400);
//     //     }

//     //     try {
//     //         // [PERBAIKAN] Memanggil Endpoint GET Order Biteship
//     //         $response = Http::withHeaders([
//     //             'Authorization' => config('services.biteship.api_key'),
//     //         ])->get('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);

//     //         $data = $response->json();

//     //         if (isset($data['success']) && $data['success'] === false) {
//     //             return response()->json(['message' => $data['error'] ?? 'Order not found in Logistics'], 400);
//     //         }

//     //         // Kembalikan seluruh objek respon JSON dari Biteship ke Frontend
//     //         return response()->json($data);
//     //     } catch (\Exception $e) {
//     //         report($e);

//     //         return response()->json(['message' => 'Failed to retrieve tracking data: '.$e->getMessage()], 500);
//     //     }
//     // }

//     public function trackOrder($id, BiteshipService $biteship)
//     {
//         $transaction = Transaction::where('user_id', request()->user()->id)->findOrFail($id);
//         if ($transaction->shipping_method !== 'biteship' || !$transaction->biteship_order_id)
//             return response()->json(['message' => 'Tracking unavailable.'], 400);

//         try {
//             $data = $biteship->getOrderTracking($transaction->biteship_order_id);
//             if (isset($data['success']) && $data['success'] === false)
//                 return response()->json(['message' => $data['error'] ?? 'Order not found'], 400);
//             return response()->json($data);
//         } catch (\Exception $e) {
//             return response()->json(['message' => 'Failed to track: ' . $e->getMessage()], 500);
//         }
//     }

//     public function bulkTrackOrders(Request $request)
//     {
//         $request->validate([
//             'transaction_ids' => 'required|array',
//             'transaction_ids.*' => 'integer|exists:transactions,id',
//         ]);

//         // 1. Ambil data transaksi HANYA dengan 1 kali query ke Database (1 Koneksi DB)
//         $transactions = Transaction::where('user_id', $request->user()->id)
//             ->whereIn('id', $request->transaction_ids)
//             ->whereNotNull('biteship_order_id')
//             ->where('shipping_method', 'biteship')
//             ->get();

//         $trackingData = [];

//         // 2. Looping untuk menembak API Biteship satu per satu di sisi Backend
//         foreach ($transactions as $transaction) {
//             try {
//                 $response = Http::withHeaders([
//                     'Authorization' => config('services.biteship.api_key'),
//                 ])->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

//                 if (isset($response['success']) && $response['success'] === true) {
//                     $trackingData[$transaction->id] = $response->json();
//                 } else {
//                     $trackingData[$transaction->id] = ['status' => 'pending'];  // Fallback jika belum teralokasi
//                 }
//             } catch (\Exception $e) {
//                 report($e);
//                 // Jangan gagalkan seluruh request jika 1 order error di sisi Biteship
//                 $trackingData[$transaction->id] = ['status' => 'error fetching data'];
//             }
//         }

//         // 3. Kembalikan data dalam bentuk Key-Value (ID Transaksi => Data Biteship)
//         return response()->json($trackingData);
//     }

//     // Fungsi khusus Admin: Mengambil semua tracking tanpa filter user_id
//     // public function adminBulkTrackOrders(Request $request)
//     // {
//     //     $request->validate([
//     //         'transaction_ids' => 'required|array',
//     //         'transaction_ids.*' => 'integer|exists:transactions,id',
//     //     ]);

//     //     // HAPUS filter ->where('user_id') agar Admin bisa melihat semua pesanan
//     //     $transactions = Transaction::whereIn('id', $request->transaction_ids)
//     //         ->whereNotNull('biteship_order_id')
//     //         ->where('shipping_method', 'biteship')
//     //         ->get();

//     //     $trackingData = [];

//     //     foreach ($transactions as $transaction) {
//     //         try {
//     //             $response = Http::withHeaders([
//     //                 'Authorization' => config('services.biteship.api_key'),
//     //             ])->get('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);

//     //             if (isset($response['success']) && $response['success'] === true) {
//     //                 $trackingData[$transaction->id] = $response->json();
//     //             } else {
//     //                 $trackingData[$transaction->id] = ['status' => 'pending'];
//     //             }
//     //         } catch (\Exception $e) {
//     //             $trackingData[$transaction->id] = ['status' => 'error fetching data'];
//     //         }
//     //     }

//     //     return response()->json($trackingData);
//     // }

//     // public function adminBulkTrackOrders(Request $request)
//     // {
//     //     $request->validate([
//     //         'transaction_ids' => 'required|array',
//     //         'transaction_ids.*' => 'integer|exists:transactions,id',
//     //     ]);

//     //     // Batasi maksimal 20 tracking sekaligus agar API Biteship tidak memblokir Anda (Rate Limiting)
//     //     if (count($request->transaction_ids) > 20) {
//     //         return response()->json(['message' => 'Maksimal tracking massal adalah 20 pesanan sekaligus.'], 422);
//     //     }

//     //     $transactions = Transaction::whereIn('id', $request->transaction_ids)
//     //         ->whereNotNull('biteship_order_id')
//     //         ->where('shipping_method', 'biteship')
//     //         ->get();

//     //     if ($transactions->isEmpty()) {
//     //         return response()->json([]);
//     //     }

//     //     // [PERBAIKAN KRITIS] Tembak API Biteship secara PARALEL (Bersamaan)
//     //     $responses = Http::pool(function (Pool $pool) use ($transactions) {
//     //         foreach ($transactions as $transaction) {
//     //             $pool->as($transaction->id)
//     //                 ->withHeaders(['Authorization' => config('services.biteship.api_key')])
//     //                 ->get('https://api.biteship.com/v1/orders/'.$transaction->biteship_order_id);
//     //         }
//     //     });

//     //     $trackingData = [];

//     //     // Rangkai hasil balasannya
//     //     foreach ($transactions as $transaction) {
//     //         $response = $responses[$transaction->id] ?? null;

//     //         if ($response && $response->ok() && isset($response['success']) && $response['success'] === true) {
//     //             $trackingData[$transaction->id] = $response->json();
//     //         } else {
//     //             $trackingData[$transaction->id] = ['status' => 'pending/error'];
//     //         }
//     //     }

//     //     return response()->json($trackingData);
//     // }

//     public function adminBulkTrackOrders(Request $request, BiteshipService $biteship)
//     {
//         $request->validate(['transaction_ids' => 'required|array', 'transaction_ids.*' => 'integer|exists:transactions,id']);
//         if (count($request->transaction_ids) > 20)
//             return response()->json(['message' => 'Max 20 tracking at once.'], 422);

//         $transactions = Transaction::whereIn('id', $request->transaction_ids)->whereNotNull('biteship_order_id')->where('shipping_method', 'biteship')->get();
//         if ($transactions->isEmpty())
//             return response()->json([]);

//         // Tembak secara paralel melalui Service
//         $biteshipIds = $transactions->pluck('biteship_order_id', 'id')->toArray();
//         $trackingData = $biteship->getBulkTrackingParallel(array_values($biteshipIds));

//         // Mapping ID Transaksi Lokal ke hasil tracking Biteship
//         $finalData = [];
//         foreach ($biteshipIds as $transactionId => $bId) {
//             $finalData[$transactionId] = $trackingData[$bId] ?? ['status' => 'pending/error'];
//         }
//         return response()->json($finalData);
//     }

//     // Fungsi khusus Admin untuk mengambil detail tracking 1 order
//     public function adminTrackOrder($id)
//     {
//         $transaction = Transaction::findOrFail($id);  // HAPUS filter user_id

//         if ($transaction->shipping_method !== 'biteship' || !$transaction->biteship_order_id) {
//             return response()->json(['message' => 'Tracking information is not available yet.'], 400);
//         }

//         try {
//             $response = Http::withHeaders([
//                 'Authorization' => config('services.biteship.api_key'),
//             ])->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

//             $data = $response->json();

//             if (isset($data['success']) && $data['success'] === false) {
//                 return response()->json(['message' => $data['error'] ?? 'Order not found in Logistics'], 400);
//             }

//             return response()->json($data);
//         } catch (\Exception $e) {
//             report($e);

//             return response()->json(['message' => 'Failed to retrieve tracking data: ' . $e->getMessage()], 500);
//         }
//     }

//     // public function printLabel(Request $request, $id)
//     // {
//     //     $transaction = Transaction::findOrFail($id);

//     //     if (! $transaction->biteship_order_id) {
//     //         return response()->json(['message' => 'Order ID Biteship tidak ditemukan'], 404);
//     //     }

//     //     // Ambil query parameter dari Vue (insurance_shown, dll)
//     //     $queryString = http_build_query($request->all());

//     //     // Target URL Biteship (Perhatikan ini menggunakan api.biteship.com, BUKAN biteship.com)
//     //     $biteshipUrl = "https://api.biteship.com/v1/orders/{$transaction->biteship_order_id}/labels?{$queryString}";

//     //     try {
//     //         // Tembak URL label Biteship dengan API Key kita
//     //         $response = Http::withHeaders([
//     //             'Authorization' => config('services.biteship.api_key'),
//     //         ])->get($biteshipUrl);

//     //         // Jika sukses, Biteship biasanya mengembalikan langsung file PDF (application/pdf)
//     //         if ($response->successful()) {
//     //             return response($response->body(), 200)
//     //                 ->header('Content-Type', 'application/pdf')
//     //                 ->header('Content-Disposition', 'inline; filename="Resi-'.$transaction->order_id.'.pdf"');
//     //         }

//     //         return response()->json(['message' => 'Gagal mengambil resi dari Biteship: '.$response->body()], 400);
//     //     } catch (\Exception $e) {
//     //         report($e);

//     //         return response()->json(['message' => 'Terjadi kesalahan sistem: '.$e->getMessage()], 500);
//     //     }
//     // }

//     public function printLabel(Request $request, $id, BiteshipService $biteship)
//     {
//         $transaction = Transaction::findOrFail($id);
//         if (!$transaction->biteship_order_id)
//             return response()->json(['message' => 'No Biteship ID'], 404);

//         try {
//             $response = $biteship->getLabelPdfResponse($transaction->biteship_order_id, http_build_query($request->all()));
//             if ($response->successful()) {
//                 return response($response->body(), 200)
//                     ->header('Content-Type', 'application/pdf')
//                     ->header('Content-Disposition', 'inline; filename="Resi-' . $transaction->order_id . '.pdf"');
//             }
//             return response()->json(['message' => 'Gagal mengambil resi'], 400);
//         } catch (\Exception $e) {
//             return response()->json(['message' => 'System error: ' . $e->getMessage()], 500);
//         }
//     }

//     // public function biteshipCallback(Request $request)
//     // {
//     //     // Validasi signature (Opsional tapi disarankan)
//     //     // $signature = $request->header('biteship-signature');
//     //     // $secret = config('services.biteship.webhook_secret'); // Tambahkan di config/services.php dan .env

//     //     // if ($signature !== $secret) {
//     //     //     Log::critical('Fake Biteship Webhook Detected!', $request->all());

//     //     //     return response()->json(['message' => 'Forbidden'], 403);
//     //     // }

//     //     $biteshipOrderId = $request->input('order_id');
//     //     $status = strtolower($request->input('status')); // picking_up, dropped, delivered, cancelled, rejected, dll
//     //     $waybill = $request->input('courier_waybill_id');

//     //     \Log::info('Biteship Webhook Received: ', $request->all());

//     //     // [PERBAIKAN MUTLAK: DB TRANSACTION & LOCKING]
//     //     return DB::transaction(function () use ($biteshipOrderId, $status, $waybill) {

//     //         // $transaction = Transaction::where('biteship_order_id', $biteshipOrderId)->first();
//     //         // Kunci baris ini agar webhook yang datang bersamaan harus antre!
//     //         $transaction = Transaction::where('biteship_order_id', $biteshipOrderId)
//     //             ->lockForUpdate()
//     //             ->first();

//     //         if (! $transaction) {
//     //             return response()->json(['message' => 'Transaction not found'], 200);
//     //         }

//     //         // Mencegah proses ulang jika status sudah 'completed'
//     //         if ($transaction->status === 'completed' && $status === 'delivered') {
//     //             return response()->json(['message' => 'Already completed'], 200);
//     //         }

//     //         // [PERBAIKAN UTAMA] Selalu update shipping_status terbaru dari Webhook!
//     //         $updates = ['shipping_status' => $status];

//     //         // 1. Update Resi jika baru turun
//     //         if ($waybill && in_array($transaction->tracking_number, ['Pending', null])) {
//     //             $updates['tracking_number'] = $waybill;
//     //         }

//     //         // 2. Jika paket berhasil dikirim ke pembeli, otomatis selesaikan transaksi
//     //         if ($status === 'delivered' && $transaction->status === 'processing') {
//     //             $updates['status'] = 'completed';

//     //             // ==========================================================
//     //             // 👇 [BARU] CAIRKAN KOMISI KARENA BARANG SUDAH SAMPAI 👇
//     //             // ==========================================================
//     //             if ($transaction->affiliate_id && $transaction->commission_status === 'pending') {
//     //                 $updates['commission_status'] = 'settled'; // Status komisi jadi Selesai

//     //                 $affiliateUser = User::find($transaction->affiliate_id);
//     //                 if ($affiliateUser) {
//     //                     // Tambahkan uang ke dompet afiliator sesuai perhitungan saat checkout
//     //                     $affiliateUser->increment('commission_balance', $transaction->commission_earned);
//     //                 }
//     //             }
//     //             // ==========================================================

//     //             // Simpan status transaksi agar query SUM di helper bisa menangkap transaksi ini
//     //             $transaction->update($updates);

//     //             // [PERBAIKAN] Cek dan jadikan member jika memenuhi syarat
//     //             $this->checkAndAssignMembership($transaction->user);

//     //             // Refresh data user
//     //             $transaction->user->refresh();

//     //             // Tambah poin user jika dia member dan transaksi punya poin
//     //             if ($transaction->point > 0 && $transaction->user->is_membership) {
//     //                 $transaction->user->increment('point', $transaction->point);
//     //             }

//     //             return response()->json(['message' => 'Webhook processed and membership checked']);
//     //         }

//     //         // 3. Jika logistik membatalkan pengiriman SEPIHAK
//     //         if (in_array($status, ['cancelled', 'rejected']) && $transaction->status === 'processing') {
//     //             $updates['status'] = 'refund_manual_required';
//     //             $updates['tracking_number'] = 'Logistics Cancelled/Rejected';
//     //             \Log::warning("Biteship Logistics Cancelled for Order ID: {$transaction->order_id}. Moved to Manual Refund.");
//     //         }

//     //         if ($status === 'disposed' && $transaction->status === 'processing') {
//     //             $updates['status'] = 'shipping_failed';
//     //             $updates['tracking_number'] = 'Shipping Failed';
//     //             \Log::warning("Biteship Shipping Failed for Order ID: {$transaction->order_id}.");
//     //         }

//     //         if ($status === 'returned' && $transaction->status === 'processing') {
//     //             $updates['status'] = 'returned';
//     //             $updates['tracking_number'] = 'Shipping Returned';
//     //             \Log::warning("Biteship Shipping Returned for Order ID: {$transaction->order_id}.");
//     //         }

//     //         // Eksekusi semua update ke database dalam 1 query
//     //         $transaction->update($updates);

//     //         // 👇 [BARU] TRIGGER PENGIRIMAN EMAIL OTOMATIS 👇
//     //         // Kita lempar ke Job Antrean agar webhook langsung merespons "success" ke Biteship
//     //         // tanpa menunggu proses pengiriman email selesai.
//     //         SendShippingUpdateJob::dispatch($transaction->id, $status);
//     //         // 👆 ========================================= 👆

//     //         // 👇 [BARU] TRIGGER WEBSOCKETS REVERB/PUSHER 👇
//     //         // Muat ulang data transaksi terbaru agar Frontend mendapat data segar
//     //         $transaction->refresh();
//     //         broadcast(new ShippingStatusUpdated($transaction, "Status pengiriman Anda telah diperbarui menjadi: " . strtoupper($status)));
//     //         // 👆 ========================================= 👆

//     //         return response()->json(['message' => 'Webhook processed successfully']);
//     //     });
//     // }

//     public function biteshipCallback(Request $request)
//     {
//         $payload = $request->all();
//         \Log::info('Biteship Webhook Received (Queued): ', ['order_id' => $payload['order_id'] ?? null]);

//         // Langsung lempar ke antrean background
//         \App\Jobs\ProcessBiteshipWebhookJob::dispatch($payload);

//         // Kembalikan 200 OK dalam hitungan milidetik
//         return response()->json(['message' => 'Webhook received and queued'], 200);
//     }

//     // --- [BARU] HELPER FUNGSI UNTUK CEK MEMBERSHIP ---
//     // private function checkAndAssignMembership($user)
//     // {
//     //     // Jika user sudah member, tidak perlu cek lagi
//     //     if ($user->is_membership) {
//     //         return;
//     //     }

//     //     // Hitung total belanja dari semua transaksi yang BERHASIL (completed)
//     //     $totalSpent = Transaction::where('user_id', $user->id)
//     //         ->where('status', 'completed')
//     //         ->sum('total_amount'); // Hanya hitung harga barang, ongkir tidak termasuk

//     //     // Jika total belanja >= 100.000, jadikan member
//     //     if ($totalSpent >= 100000) {
//     //         $user->update(['is_membership' => true]);
//     //     }
//     // }

//     // =====================================================================
//     // 👇 FUNGSI BARU UNTUK ADMIN MENGHAPUS TRANSAKSI PERMANEN 👇
//     // =====================================================================
//     // public function forceDeleteTransaction(Request $request, $id)
//     // {
//     //     // Temukan transaksi
//     //     $transaction = Transaction::with(['details', 'payment'])->find($id);

//     //     if (!$transaction) {
//     //         return response()->json(['message' => 'Transaksi tidak ditemukan.'], 404);
//     //     }

//     //     // Mulai transaksi database agar penghapusan konsisten
//     //     DB::transaction(function () use ($transaction) {
//     //         // 1. KEMBALIKAN STOK BARANG (Jika statusnya bukan batal/refund)
//     //         // Karena jika statusnya sudah batal/refund, stoknya sudah kembali.
//     //         $statusesThatAlreadyRestoredStock = ['refund_manual_required', 'cancelled', 'shipping_failed', 'returned', 'refunded'];

//     //         if (!in_array($transaction->status, $statusesThatAlreadyRestoredStock)) {
//     //             foreach ($transaction->details as $detail) {
//     //                 $this->restoreProductStock($detail->product_id, $detail->quantity);
//     //             }
//     //         }

//     //         // 2. KEMBALIKAN POIN (Opsional: Jika Anda ingin poin sandbox kembali)
//     //         if ($transaction->points_used > 0 && !in_array($transaction->status, $statusesThatAlreadyRestoredStock)) {
//     //             $transaction->user->increment('point', $transaction->points_used);
//     //         }

//     //         // 3. HAPUS DATA PEMBAYARAN TERKAIT
//     //         if ($transaction->payment) {
//     //             $transaction->payment->delete();
//     //         }

//     //         // 4. HAPUS DETAIL TRANSAKSI
//     //         // (Atau jika Anda sudah memakai skema 'onDelete cascade' di migrasi, ini opsional.
//     //         // Namun untuk amannya kita hapus manual)
//     //         foreach ($transaction->details as $detail) {
//     //             // Jangan lupa hapus cache per-produk yang terpengaruh
//     //             Cache::tags(['catalog'])->forget("products.detail.{$detail->product_id}");
//     //             $detail->delete();
//     //         }

//     //         // 5. HAPUS TRANSAKSI UTAMA
//     //         $transaction->delete();
//     //     });

//     //     // Flush seluruh cache untuk keamanan
//     //     Cache::flush();

//     //     // 👇 [BARU] TEMBAKKAN EVENT 👇
//     //     event(new \App\Events\DashboardUpdated());

//     //     return response()->json(['message' => 'Transaksi berhasil dihapus secara permanen beserta stok yang dikembalikan.']);
//     // }

//     public function forceDeleteTransaction(Request $request, $id, BiteshipService $biteship, RestoreInventoryAction $restoreInventory)
//     {
//         $transaction = Transaction::with(['details', 'payment', 'user'])->find($id);

//         if (!$transaction) {
//             return response()->json(['message' => 'Transaksi tidak ditemukan.'], 404);
//         }

//         if ($transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
//             try { $biteship->cancelOrder($transaction->biteship_order_id); } catch (\Exception $e) {}
//         }

//         DB::transaction(function () use ($transaction, $restoreInventory) {
//             $statusesThatAlreadyRestoredStock = ['refund_manual_required', 'cancelled', 'shipping_failed', 'returned', 'refunded'];

//             if (!in_array($transaction->status, $statusesThatAlreadyRestoredStock)) {
//                 foreach ($transaction->details as $detail) {
//                     $restoreInventory->execute($detail->product_id, $detail->quantity);
//                 }
//             }

//             if ($transaction->points_used > 0 && !in_array($transaction->status, $statusesThatAlreadyRestoredStock)) {
//                 $transaction->user->increment('point', $transaction->points_used);
//             }

//             if ($transaction->payment) {
//                 $transaction->payment->delete();
//             }

//             $this->clearTransactionProductCache($transaction);

//             foreach ($transaction->details as $detail) {
//                 $detail->delete();
//             }

//             $transaction->delete();
//         });

//         $this->revokeMembershipIfBelowThreshold($transaction->user);

//         event(new \App\Events\DashboardUpdated());
//         return response()->json(['message' => 'Transaksi berhasil dihapus secara permanen beserta stok yang dikembalikan.']);
//     }
// }

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
use App\Services\BiteshipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\SendShippingUpdateJob;
use App\Services\FileUploadService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Events\ShippingStatusUpdated;
use App\Services\PromoMerdekaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use App\Actions\Order\ProcessRefundAction;

// 👇 [BARU] IMPORT CLASS YANG DIBUTUHKAN 👇
use App\Actions\Order\RestoreInventoryAction;
use App\Actions\Order\CancelTransactionAction;
use App\Actions\Checkout\DeductInventoryAction;
use App\Actions\Checkout\CreateTransactionAction;
use App\Actions\Checkout\CalculateCartTotalsAction;
// 👆 ========================================= 👆

class TransactionController extends Controller
{
    // =========================================================================
    // HELPER FUNCTIONS (Prinsip DRY - Don't Repeat Yourself)
    // =========================================================================

    // 1. Membersihkan Cache Produk
    private function clearTransactionProductCache(Transaction $transaction)
    {
        foreach ($transaction->details as$detail) {
            Cache::tags(['catalog'])->forget("products.detail.{$detail->product_id}");
        }
    }

    // 2. Cek Naik Level Member
    private function checkAndAssignMembership(User $user)
    {
        if ($user->is_membership)
            return;
        $totalSpent = Transaction::where('user_id',$user->id)->where('status', 'completed')->sum('total_amount');
        if ($totalSpent >= 100000) {$user->update(['is_membership' => true]);
        }
    }

    // 3. Cek Turun Level Member (Jika ada pembatalan / Refund)
    private function revokeMembershipIfBelowThreshold(User $user)
    {
        if (!$user->is_membership)
            return;
        $totalSpent = Transaction::where('user_id',$user->id)->where('status', 'completed')->sum('total_amount');
        if ($totalSpent < 100000) {$user->update(['is_membership' => false]);
        }
    }

    public function restoreProductStock($productId,$quantityToRestore)
    {
        if ($quantityToRestore <= 0) {
            return;
        }

        // 1. Kunci (Lock) baris produk utama untuk mencegah modifikasi berbarengan
        $product = Product::lockForUpdate()->find($productId);
        if (!$product) {
            return;
        }

        $remainingToRestore =$quantityToRestore;

        // 2. Ambil batch stok yang TIDAK PENUH (quantity < initial_quantity)
        // Urutkan dari yang PALING LAMA (ASC) untuk mengembalikan secara FIFO
        $incompleteBatches = ProductStock::where('product_id',$productId)
            ->whereColumn('quantity', '<', 'initial_quantity')
            ->orderBy('created_at', 'asc')
            ->lockForUpdate()  // Kunci baris batch ini selama transaksi berlangsung
            ->get();

        foreach ($incompleteBatches as$batch) {
            if ($remainingToRestore <= 0) {
                break;
            }

            $spaceAvailable = $batch->initial_quantity -$batch->quantity;

            if ($spaceAvailable >=$remainingToRestore) {
                // Jika lubang di batch ini cukup untuk menampung semua barang kembalian
                $batch->increment('quantity', $remainingToRestore);$remainingToRestore = 0;
            } else {
                // Jika tidak cukup, penuhi batch ini, sisanya cari di batch berikutnya
                $batch->increment('quantity',$spaceAvailable);
                $remainingToRestore -=$spaceAvailable;
            }
        }

        // 3. Fallback/Penyelamat: Jika ternyata masih ada sisa (misal: batch lama terhapus manual oleh admin)
        if ($remainingToRestore > 0) {
            $latestBatch = ProductStock::where('product_id',$productId)
                ->orderBy('created_at', 'desc')
                ->lockForUpdate()
                ->first();

            if ($latestBatch) {
                // Masukkan ke batch terbaru dan naikkan kapasitas awalnya agar tidak error
                $latestBatch->increment('quantity',$remainingToRestore);
                $latestBatch->increment('initial_quantity',$remainingToRestore);
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
        $product->increment('stock',$quantityToRestore);
    }

    // --- USER ACTIONS ---
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

            $user =$request->user();

            $cartItems = Cart::with('product.category')
                ->where('user_id', $user->id)
                ->whereIn('id', $request->cart_ids)
                ->get();

            if ($cartItems->isEmpty()) {
                return response()->json(['message' => 'No items selected for checkout'], 400);
            }

            $transactionData = DB::transaction(function () use ($user, $cartItems,$request, $promoService,$calculateTotals, $createTransaction,$deductInventory) {
                // 1. Kunci Baris User (Mencegah Race Condition Saldo Poin)
                $lockedUser = User::lockForUpdate()->find($user->id);

                // 2. ACTION: Kalkulasi Harga, Promo, dan Poin
                $totals =$calculateTotals->execute($lockedUser,$cartItems, $request,$promoService);

                // 3. ACTION: Buat Transaksi Baru
                $transaction = $createTransaction->execute($lockedUser, $request,$totals);

                // 4. ACTION: Potong Stok Inventory FIFO
                $deductInventory->execute($transaction, $cartItems,$totals['finalItemPrices']);

                return [
                    'transaction' => $transaction,
                    'currency' => $request->currency,
                ];
            });

            // 👇 [BARU] TEMBAKKAN EVENT SAAT PESANAN BARU BERHASIL DIBUAT 👇
            event(new \App\Events\DashboardUpdated());

            // Lanjut ke Payment Gateway
            $paymentController = app(PaymentController::class);$request->merge([
                'transaction_id' => $transactionData['transaction']->id,
                'currency' => $transactionData['currency']
            ]);

            return $paymentController->createInvoice($request);
        } catch (\Throwable $e) {
            report($e);
            Log::error('CHECKOUT FATAL ERROR: ' . $e->getMessage(), ['trace' =>$e->getTraceAsString()]);
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

    // Melihat semua transaksi (Sisi Admin)
    public function allTransactions()
    {
        // Menambahkan relasi 'address' agar data penerima dan kodepos bisa dirender di Vue
        $transactions = Transaction::with(['user', 'details.product', 'address'])
            ->latest()
            ->get();

        return response()->json($transactions);
    }

    public function cancelOrder(Request $request,$id, CancelTransactionAction $cancelTransaction, BiteshipService$biteship)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

        if (!in_array($transaction->status, ['awaiting_payment', 'pending', 'processing'])) {
            return response()->json(['message' => 'Cannot cancel this order.'], 400);
        }

        try {
            $result =$cancelTransaction->execute($transaction,$biteship);

            $this->clearTransactionProductCache($transaction);
            $this->revokeMembershipIfBelowThreshold($transaction->user);

            event(new \App\Events\DashboardUpdated());

            return response()->json(['message' => $result['message']]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function confirmComplete(Request $request,$id)
    {
        $transaction = Transaction::where('user_id', $request->user()->id)->findOrFail($id);

        if ($transaction->status !== 'processing') {
            return response()->json(['message' => 'Order cannot be completed yet.'], 400);
        }

        $transaction->update(['status' => 'completed']);

        // 👇 TEMPEL KODE PENCAIRAN AFILIASI DI SINI 👇
        if ($transaction->affiliate_id && $transaction->commission_status === 'pending') {             // 1. Ubah status komisi menjadi cair (settled)$transaction->update(['commission_status' => 'settled']);

            // 2. Tambahkan uangnya ke dompet afiliator
            $affiliate = User::find($transaction->affiliate_id);
            if ($affiliate) {
                $affiliate->increment('commission_balance',$transaction->commission_earned);
            }
        }

        $this->checkAndAssignMembership($transaction->user);

        // [PERBAIKAN MUTLAK] Jangan lupakan poin pelanggan yang menyelesaikan pesanan manual!
        $transaction->user->refresh();
        if ($transaction->point > 0 &&$transaction->user->is_membership) {
            $transaction->user->increment('point',$transaction->point);
        }

        // 👇 [BARU] TEMBAKKAN EVENT 👇
        event(new \App\Events\DashboardUpdated());

        return response()->json(['message' => 'Order completed!']);
    }

    public function requestRefund(Request $request, $id, FileUploadService$fileUpload)
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
    public function processRefundUser(Request $request,$id, ProcessRefundAction $processRefund, BiteshipService$biteship)
    {
        try {
            $result =$processRefund->execute($id,$biteship);

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

    public function salesReport(Request $request)
    {
        $month =$request->query('month');
        $year =$request->query('year');
        $search =$request->query('search');

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

        if ($month && $year) {$query->where('month', $month)->where('year',$year);
        } elseif ($year) {
            $query->where('year',$year);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {$q
                    ->where('product_name', 'like', "%{$search}%")
                    ->orWhere('product_code', 'like', "%{$search}%");
            });
        }

        // Kita tetap melakukan grouping akhir karena jika admin tidak memilih bulan (hanya tahun),
        // kita perlu menjumlahkan bulan 1 sampai 12 untuk produk yang sama
        $report =$query
            ->groupBy('product_id', 'product_code', 'product_name', 'product_image', 'category_name')
            ->orderByDesc('total_revenue')
            ->get();

        return response()->json([
            'data' => $report,
        ]);
    }

    public function trackOrder($id, BiteshipService$biteship)
    {
        $transaction = Transaction::where('user_id', request()->user()->id)->findOrFail($id);
        if ($transaction->shipping_method !== 'biteship' \vert{}\vert{} !$transaction->biteship_order_id)
            return response()->json(['message' => 'Tracking unavailable.'], 400);

        try {
            $data = $biteship->getOrderTracking($transaction->biteship_order_id);
            if (isset($data['success']) &&$data['success'] === false)
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
        $transactions = Transaction::where('user_id',$request->user()->id)
            ->whereIn('id', $request->transaction_ids)
            ->whereNotNull('biteship_order_id')
            ->where('shipping_method', 'biteship')
            ->get();

        $trackingData = [];

        // 2. Looping untuk menembak API Biteship satu per satu di sisi Backend
        foreach ($transactions as$transaction) {
            try {
                $response = Http::withHeaders([                     'Authorization' => config('services.biteship.api_key'),                 ])->get('https://api.biteship.com/v1/orders/' .$transaction->biteship_order_id);

                if (isset($response['success']) && $response['success'] === true) {$trackingData[$transaction->id] =$response->json();
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

    public function adminBulkTrackOrders(Request $request, BiteshipService$biteship)
    {
        $request->validate(['transaction_ids' => 'required|array', 'transaction_ids.*' => 'integer|exists:transactions,id']);
        if (count($request->transaction_ids) > 20)
            return response()->json(['message' => 'Max 20 tracking at once.'], 422);

        $transactions = Transaction::whereIn('id',$request->transaction_ids)->whereNotNull('biteship_order_id')->where('shipping_method', 'biteship')->get();
        if ($transactions->isEmpty())
            return response()->json([]);

        // Tembak secara paralel melalui Service
        $biteshipIds = $transactions->pluck('biteship_order_id', 'id')->toArray();$trackingData = $biteship->getBulkTrackingParallel(array_values($biteshipIds));

        // Mapping ID Transaksi Lokal ke hasil tracking Biteship
        $finalData = [];
        foreach ($biteshipIds as $transactionId =>$bId) {
            $finalData[$transactionId] = $trackingData[$bId] ?? ['status' => 'pending/error'];
        }
        return response()->json($finalData);
    }

    // Fungsi khusus Admin untuk mengambil detail tracking 1 order
    public function adminTrackOrder($id)
    {
        $transaction = Transaction::findOrFail($id);  // HAPUS filter user_id

        if ($transaction->shipping_method !== 'biteship' \vert{}\vert{} !$transaction->biteship_order_id) {
            return response()->json(['message' => 'Tracking information is not available yet.'], 400);
        }

        try {
            $response = Http::withHeaders([                 'Authorization' => config('services.biteship.api_key'),             ])->get('https://api.biteship.com/v1/orders/' .$transaction->biteship_order_id);

            $data =$response->json();

            if (isset($data['success']) &&$data['success'] === false) {
                return response()->json(['message' => $data['error'] ?? 'Order not found in Logistics'], 400);
            }

            return response()->json($data);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'Failed to retrieve tracking data: ' . $e->getMessage()], 500);
        }
    }

    public function printLabel(Request $request, $id, BiteshipService$biteship)
    {
        $transaction = Transaction::findOrFail($id);
        if (!$transaction->biteship_order_id)
            return response()->json(['message' => 'No Biteship ID'], 404);

        try {
            $response =$biteship->getLabelPdfResponse($transaction->biteship_order_id, http_build_query($request->all()));
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

    public function biteshipCallback(Request $request)
    {
        $payload = $request->all();         \Log::info('Biteship Webhook Received (Queued): ', ['order_id' =>$payload['order_id'] ?? null]);

        // Langsung lempar ke antrean background
        \App\Jobs\ProcessBiteshipWebhookJob::dispatch($payload);

        // Kembalikan 200 OK dalam hitungan milidetik
        return response()->json(['message' => 'Webhook received and queued'], 200);
    }

    public function forceDeleteTransaction(Request $request,$id, BiteshipService $biteship, RestoreInventoryAction$restoreInventory)
    {
        $transaction = Transaction::with(['details', 'payment', 'user'])->find($id);

        if (!$transaction) {
            return response()->json(['message' => 'Transaksi tidak ditemukan.'], 404);
        }

        if ($transaction->shipping_method === 'biteship' && !empty($transaction->biteship_order_id)) {
            try { $biteship->cancelOrder($transaction->biteship_order_id); } catch (\Exception$e) {}
        }

        DB::transaction(function () use ($transaction, $restoreInventory) {$statusesThatAlreadyRestoredStock = ['refund_manual_required', 'cancelled', 'shipping_failed', 'returned', 'refunded'];

            if (!in_array($transaction->status,$statusesThatAlreadyRestoredStock)) {
                foreach ($transaction->details as $detail) {$restoreInventory->execute($detail->product_id,$detail->quantity);
                }
            }

            if ($transaction->points_used > 0 && !in_array($transaction->status,$statusesThatAlreadyRestoredStock)) {
                $transaction->user->increment('point',$transaction->points_used);
            }

            if ($transaction->payment) {$transaction->payment->delete();
            }

            $this->clearTransactionProductCache($transaction);

            foreach ($transaction->details as $detail) {$detail->delete();
            }

            $transaction->delete();
        });

        $this->revokeMembershipIfBelowThreshold($transaction->user);

        event(new \App\Events\DashboardUpdated());
        return response()->json(['message' => 'Transaksi berhasil dihapus secara permanen beserta stok yang dikembalikan.']);
    }
}
