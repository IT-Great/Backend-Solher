<?php

// use App\Http\Controllers\ChatController;
// use App\Http\Controllers\EventController;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Route;
// use App\Http\Controllers\CoaController;
// use App\Http\Controllers\AuthController;
// use App\Http\Controllers\CartController;
// use App\Http\Controllers\HomeController;
// use App\Http\Controllers\ProductController;
// use App\Http\Controllers\ContactController;
// use App\Http\Controllers\PaymentController;
// use App\Http\Controllers\InvoiceController;
// use App\Http\Controllers\AddressController;
// use App\Http\Controllers\CategoryController;
// use App\Http\Controllers\S3UploadController;
// use App\Http\Controllers\WishlistController;
// use App\Http\Controllers\DashboardController;
// use App\Http\Controllers\CategoryCoaController;
// use App\Http\Controllers\TransactionController;
// use App\Http\Controllers\ProductStockController;
// use App\Http\Controllers\TransferReceivePaymentController;
// use Illuminate\Support\Facades\Artisan;
// use Illuminate\Support\Facades\Broadcast;
// use Illuminate\Support\Facades\Cache;

// /*
// |--------------------------------------------------------------------------
// | BROADCAST AUTH ROUTE (Khusus Laravel 11 & Vue SPA)
// |--------------------------------------------------------------------------
// */
// // [PERBAIKAN] Hapus prefix 'api' karena file ini sudah otomatis ber-prefix /api
// Broadcast::routes(['middleware' => ['auth:sanctum']]);

// // =========================================================================
// // PUBLIC ROUTES
// // =========================================================================
// Route::get('/home/find-product', [HomeController::class, 'getProductBySearch']);
// Route::get('/home/category/{code}', [HomeController::class, 'getProductsByCategory']);
// Route::post('/register', [AuthController::class, 'register']);
// Route::post('/login', [AuthController::class, 'login']);
// Route::post('/admin/login', [AuthController::class, 'adminLogin']);

// // Lupa Password (User)
// Route::post('/forgot-password/send-code', [AuthController::class, 'sendResetCode']);
// Route::post('/forgot-password/verify-code', [AuthController::class, 'verifyResetCode']);
// Route::post('/forgot-password/reset', [AuthController::class, 'resetPassword']);

// // Lupa Password (Admin/Staf)
// Route::post('/admin/forgot-password/send-code', [AuthController::class, 'adminSendResetCode']);
// Route::post('/admin/forgot-password/verify-code', [AuthController::class, 'adminVerifyResetCode']);
// Route::post('/admin/forgot-password/reset', [AuthController::class, 'adminResetPassword']);

// Route::get('/products', [ProductController::class, 'index']);
// Route::get('/products/inactive', [ProductController::class, 'inactiveProducts']);
// Route::get('/products/{id}', [ProductController::class, 'show']);
// Route::post('/contact', [ContactController::class, 'store']);
// Route::post('/subscribe', [ContactController::class, 'subscribe']);
// Route::get('/guest/categories', [CategoryController::class, 'index']);
// Route::post('/biteship/callback', [TransactionController::class, 'biteshipCallback']);
// Route::post('/payments/callback', [PaymentController::class, 'callback']);
// // Route::post('/shipping/rates', [PaymentController::class, 'getShippingRates']);

// // Public Route (Pop-up Home)
// Route::post('/promo/claim', [App\Http\Controllers\PromoController::class, 'claim']);

// // ROUTE UNTUK USER BIASA (Bebas diakses tanpa login)
// Route::get('/events', [EventController::class, 'indexPublic']);

// // =========================================================================
// // PROTECTED ROUTES: GLOBAL LOGGED IN USERS (Semua User)
// // =========================================================================
// Route::middleware('auth:sanctum')->group(function () {
//     Route::get('/user', function (Request $request) {
//         return $request->user();
//     });
//     Route::post('/user/update-info', [AuthController::class, 'updateProfileInfo']);
//     Route::post('/user/update-image', [AuthController::class, 'updateImage']);
//     Route::post('/user/update-password', [AuthController::class, 'updatePassword']);
//     Route::post('/user/toggle-membership', [AuthController::class, 'toggleMembership']);

//     Route::get('/wishlists', [WishlistController::class, 'index']);
//     Route::post('/wishlists/toggle', [WishlistController::class, 'toggle']);

//     Route::get('/user/contact-history', [ContactController::class, 'userHistory']);

//     Route::get('/addresses', [AddressController::class, 'index']);
//     Route::post('/addresses', [AddressController::class, 'store']);
//     Route::put('/addresses/{id}', [AddressController::class, 'update']);
//     Route::delete('/addresses/{id}', [AddressController::class, 'destroy']);

//     Route::get('/carts', [CartController::class, 'index']);
//     Route::post('/carts', [CartController::class, 'store']);
//     Route::put('/carts/{id}', [CartController::class, 'update']);
//     Route::delete('/carts/{id}', [CartController::class, 'destroy']);

//     Route::post('/checkout', [TransactionController::class, 'checkout']);
//     Route::get('/transactions', [TransactionController::class, 'index']);
//     Route::get('/transactions/{id}', [TransactionController::class, 'show']);
//     Route::post('/transactions/{id}/cancel', [TransactionController::class, 'cancelOrder']);
//     Route::post('/transactions/{id}/confirm', [TransactionController::class, 'confirmComplete']);
//     Route::post('/transactions/{id}/refund-request', [TransactionController::class, 'requestRefund']);
//     Route::post('/transactions/{id}/refund-process', [TransactionController::class, 'processRefundUser']);
//     Route::get('/transactions/{id}/tracking', [TransactionController::class, 'trackOrder']);
//     Route::post('/transactions/tracking/bulk', [TransactionController::class, 'bulkTrackOrders']);

//     Route::post('/payments/invoice', [PaymentController::class, 'createInvoice']);
//     Route::post('/shipping/rates', [PaymentController::class, 'getShippingRates']);

//     Route::post('/promo/verify', [App\Http\Controllers\PromoController::class, 'verify']);
// });

// // =========================================================================
// // PROTECTED ROUTES: ADMIN & STAFF AREA (RBAC APPLIED)
// // =========================================================================

// // GRUP A: Rute yang bisa diakses oleh HAMPIR SEMUA STAF (Admin, Gudang, Accounting)
// Route::middleware(['auth:sanctum', 'role:admin,gudang,accounting'])->prefix('admin')->group(function () {
//     Route::get('/', function (Request $request) {
//         return $request->user();
//     });
//     Route::post('/update-info', [AuthController::class, 'updateAdminProfileInfo']);
//     Route::post('/update-image', [AuthController::class, 'updateAdminImage']);
//     Route::post('/update-password', [AuthController::class, 'updateAdminPassword']);
// });

// // GRUP B: DASHBOARD ANALITIK (Hanya Admin)
// Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/dashboard')->group(function () {
//     Route::get('/master-data', [DashboardController::class, 'getDashboardMasterData']);
//     // Endpoint lama jika masih dipakai
//     Route::get('/stats', [DashboardController::class, 'getStats']);
//     Route::get('/revenue-chart', [DashboardController::class, 'getRevenueChart']);
//     Route::get('/popular-products', [DashboardController::class, 'getPopularProducts']);
//     Route::get('/predicted-bestsellers', [DashboardController::class, 'getPredictedBestsellers']);
//     Route::get('/recent-activities', [DashboardController::class, 'getRecentActivities']);
//     Route::get('/daily-average', [DashboardController::class, 'getAverageDailyRevenue']);
// });

// // GRUP C: MANAJEMEN KATEGORI & SISTEM (Hanya Admin)
// Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
//     Route::get('/categories', [CategoryController::class, 'index']);
//     Route::post('/categories', [CategoryController::class, 'store']);
//     Route::put('/categories/{id}', [CategoryController::class, 'update']);
//     Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
//     Route::get('/categories/{id}', [CategoryController::class, 'show']);

//     Route::get('/admin/users', [AuthController::class, 'getAllUsers']);
//     Route::get('/admin/users/{id}', [AuthController::class, 'getUserDetail']);

//     Route::get('/admin/messages', [ContactController::class, 'getInboundMessages']);
//     Route::get('/admin/messages/unread-count', [ContactController::class, 'getUnreadCount']);
//     Route::get('/admin/messages/{id}', [ContactController::class, 'showAdminMessage']);
//     Route::post('/admin/messages/{id}/respond', [ContactController::class, 'respondMessage']);

//     Route::get('/admin/subscribers', function () {
//         return response()->json(\App\Models\Subscriber::latest()->get());
//     });
// });

// // GRUP D: MANAJEMEN PRODUK KREASI & HAPUS (Hanya Admin)
// // *Note: View product terbuka public, tapi CRUD butuh admin
// Route::middleware(['auth:sanctum', 'role:admin,superadmin'])->group(function () {
//     Route::post('/products', [ProductController::class, 'store']);
//     Route::put('/products/{id}', [ProductController::class, 'update']);
//     Route::delete('/products/{id}', [ProductController::class, 'destroy']);
//     Route::put('/products/{id}/restore', [ProductController::class, 'restore']);
//     Route::delete('/products/{id}/force', [ProductController::class, 'forceDelete']);
//     Route::post('/admin/s3/presign', [S3UploadController::class, 'presign']);

//     Route::get('/admin/audit-logs', [\App\Http\Controllers\AuditLogController::class, 'index']);

//     // CRUD Event
//     Route::get('/admin/events', [EventController::class, 'index']);
//     Route::get('/admin/events/{id}', [EventController::class, 'show']);
//     Route::post('/admin/events', [EventController::class, 'store']);
//     // Ingat: Laravel butuh _method=PUT dari FormData untuk update file, jadi routenya tetap POST/PUT sesuai setup Vue Anda
//     Route::put('/admin/events/{id}', [EventController::class, 'update']);
//     Route::delete('/admin/events/{id}', [EventController::class, 'destroy']);
// });

// // GRUP E: STOK & GUDANG (Admin & Gudang)
// Route::middleware(['auth:sanctum', 'role:admin,gudang'])->group(function () {
//     Route::get('/admin/product-stocks', [ProductStockController::class, 'index']);
//     Route::post('/admin/product-stocks/{productId}', [ProductStockController::class, 'store']);
// });

// // GRUP F: TRANSAKSI & PENGIRIMAN (Admin & Gudang)
// Route::middleware(['auth:sanctum', 'role:admin,gudang'])->group(function () {
//     Route::get('/admin/transactions', [TransactionController::class, 'allTransactions']);
//     Route::get('/admin/transactions/{id}', [TransactionController::class, 'adminShow']);
//     Route::post('/admin/transactions/tracking/bulk', [TransactionController::class, 'adminBulkTrackOrders']);
//     Route::get('/admin/transactions/{id}/tracking', [TransactionController::class, 'adminTrackOrder']);
//     Route::get('/admin/transactions/{id}/print-label', [TransactionController::class, 'printLabel']);
// });

// // GRUP G: ACCOUNTING & KEUANGAN (Admin & Accounting)
// Route::middleware(['auth:sanctum', 'role:admin,accounting'])->prefix('admin')->group(function () {
//     Route::get('/sales-report', [TransactionController::class, 'salesReport']);

//     // Approval Refund (Berurusan dengan uang keluar)
//     Route::post('/transactions/{id}/refund-approve', [TransactionController::class, 'approveRefund']);
//     Route::post('/transactions/{id}/refund-reject', [TransactionController::class, 'rejectRefund']);

//     // Modul Accounting Khusus
//     Route::apiResource('category-coas', CategoryCoaController::class);
//     Route::apiResource('coas', CoaController::class);
//     Route::post('coas/{id}/post', [CoaController::class, 'postCoa']);
//     Route::apiResource('payments', TransferReceivePaymentController::class);

//     Route::apiResource('suppliers', InvoiceController::class)->except(['create', 'edit', 'show']);
//     Route::get('suppliers', [InvoiceController::class, 'indexSupplier']);
//     Route::post('suppliers', [InvoiceController::class, 'storeSupplier']);
//     Route::put('suppliers/{id}', [InvoiceController::class, 'updateSupplier']);
//     Route::delete('suppliers/{id}', [InvoiceController::class, 'deleteSupplier']);
//     Route::get('invoices', [InvoiceController::class, 'indexInvoice']);
//     Route::post('invoices', [InvoiceController::class, 'storeInvoice']);
//     Route::put('invoices/{id}', [InvoiceController::class, 'updateInvoice']);
//     Route::post('invoices/{id}/pay', [InvoiceController::class, 'processPayment']);
//     Route::delete('invoices/{id}', [InvoiceController::class, 'deleteInvoice']);
// });

// // GRUP H: CHAT REALTIME (Admin & Customer)
// Route::middleware('auth:sanctum')->group(function () {
//     Route::get('/chat/admins', [ChatController::class, 'getAdmins']);
//     Route::get('/chat/messages/{id}', [ChatController::class, 'getMessages']);
//     Route::post('/chat/send', [ChatController::class, 'sendMessage']);
//     Route::post('/chat/read/{id}', [ChatController::class, 'markAsRead']);
//     Route::post('/chat/typing', [ChatController::class, 'typing']);
// });

// Route::get('/exchange-rates', function () {
//     // Cek apakah data kurs sudah ada di Cache
//     if (!Cache::has('exchange_rates')) {
//         // Jika kosong (mungkin cron belum jalan), paksa jalankan command sekarang juga
//         Artisan::call('currency:update-rates');
//     }

//     // Ambil data dari cache. Berikan nilai default IDR = 1 sebagai lapisan keamanan terakhir
//     $rates = Cache::get('exchange_rates', ['IDR' => 1]);

//     return response()->json([
//         'status' => 'success',
//         'base' => 'IDR',
//         'data' => [
//             'rates' => $rates,
//             'last_updated' => now()->timezone('Asia/Jakarta')->toDateTimeString()
//         ]
//     ], 200);
// });

use App\Http\Controllers\AddressController;
use App\Http\Controllers\AffiliateController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CategoryCoaController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\CoaController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductStockController;
use App\Http\Controllers\PromoController;
use App\Http\Controllers\S3UploadController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransferReceivePaymentController;
use App\Http\Controllers\WishlistController;
use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| BROADCAST AUTH ROUTE (Khusus Laravel 11 & Vue SPA)
|--------------------------------------------------------------------------
*/
Broadcast::routes(['middleware' => ['auth:sanctum']]);

// =========================================================================
// PUBLIC ROUTES
// =========================================================================

// --- KLASTER LONGGAR (Tanpa Pembatasan Ekstra) ---
Route::get('/home/find-product', [HomeController::class, 'getProductBySearch']);
Route::get('/home/category/{code}', [HomeController::class, 'getProductsByCategory']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/inactive', [ProductController::class, 'inactiveProducts']);
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::get('/guest/categories', [CategoryController::class, 'index']);
Route::get('/events', [EventController::class, 'indexPublic']);

// --- KLASTER AUTH (Dibatasi 5 request / menit untuk mencegah Brute-Force) ---
Route::middleware('throttle:auth-limiter')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/admin/login', [AuthController::class, 'adminLogin']);
});

// --- KLASTER OTP (Dibatasi 3 request / menit untuk mencegah Email Bombing) ---
Route::middleware('throttle:otp-limiter')->group(function () {
    // Lupa Password (User)
    Route::post('/forgot-password/send-code', [AuthController::class, 'sendResetCode']);
    Route::post('/forgot-password/verify-code', [AuthController::class, 'verifyResetCode']);
    Route::post('/forgot-password/reset', [AuthController::class, 'resetPassword']);

    // Lupa Password (Admin/Staf)
    Route::post('/admin/forgot-password/send-code', [AuthController::class, 'adminSendResetCode']);
    Route::post('/admin/forgot-password/verify-code', [AuthController::class, 'adminVerifyResetCode']);
    Route::post('/admin/forgot-password/reset', [AuthController::class, 'adminResetPassword']);
});

// --- WEBHOOKS & FORMS ---
Route::post('/contact', [ContactController::class, 'store']);
Route::post('/subscribe', [ContactController::class, 'subscribe']);
Route::post('/biteship/callback', [TransactionController::class, 'biteshipCallback']);
Route::post('/payments/callback', [PaymentController::class, 'xenditCallback']);
Route::post('/payments/stripe-webhook', [PaymentController::class, 'stripeWebhook']);
Route::post('/payments/paypal-webhook', [PaymentController::class, 'paypalWebhook']);
Route::get('/payments/paypal-capture', [PaymentController::class, 'capturePayPal']);
Route::post('/promo/claim', [PromoController::class, 'claim']);

// =========================================================================
// PROTECTED ROUTES: GLOBAL LOGGED IN USERS (Semua User)
// =========================================================================
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/user/update-info', [AuthController::class, 'updateProfileInfo']);
    Route::post('/user/update-image', [AuthController::class, 'updateImage']);
    Route::post('/user/update-password', [AuthController::class, 'updatePassword']);
    Route::post('/user/toggle-membership', [AuthController::class, 'toggleMembership']);

    Route::get('/wishlists', [WishlistController::class, 'index']);
    Route::post('/wishlists/toggle', [WishlistController::class, 'toggle']);

    Route::get('/user/contact-history', [ContactController::class, 'userHistory']);

    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::put('/addresses/{id}', [AddressController::class, 'update']);
    Route::delete('/addresses/{id}', [AddressController::class, 'destroy']);

    Route::get('/carts', [CartController::class, 'index']);
    Route::post('/carts', [CartController::class, 'store']);
    Route::put('/carts/{id}', [CartController::class, 'update']);
    Route::delete('/carts/{id}', [CartController::class, 'destroy']);

    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/transactions/{id}', [TransactionController::class, 'show']);
    Route::post('/transactions/{id}/cancel', [TransactionController::class, 'cancelOrder']);
    Route::post('/transactions/{id}/confirm', [TransactionController::class, 'confirmComplete']);
    Route::post('/transactions/{id}/refund-request', [TransactionController::class, 'requestRefund']);
    Route::post('/transactions/{id}/refund-process', [TransactionController::class, 'processRefundUser']);
    Route::get('/transactions/{id}/tracking', [TransactionController::class, 'trackOrder']);
    Route::post('/transactions/tracking/bulk', [TransactionController::class, 'bulkTrackOrders']);

    // --- KLASTER CHECKOUT (Dibatasi 10 request / menit untuk mencegah Spam Order) ---
    Route::middleware('throttle:checkout-limiter')->group(function () {
        Route::post('/checkout', [TransactionController::class, 'checkout']);
        Route::post('/payments/invoice', [PaymentController::class, 'createInvoice']);
    });

    Route::post('/shipping/rates', [PaymentController::class, 'getShippingRates']);
    Route::post('/promo/verify', [PromoController::class, 'verify']);

    // [BARU] Rute Khusus Afiliator Solher
    Route::prefix('affiliate')->group(function () {
        Route::get('/dashboard', [AffiliateController::class, 'dashboard']);
        Route::post('/withdraw', [AffiliateController::class, 'withdraw']);
        Route::post('/apply', [AffiliateController::class, 'apply']);
    });
});

// =========================================================================
// PROTECTED ROUTES: ADMIN & STAFF AREA (RBAC APPLIED)
// =========================================================================

// GRUP A: Rute yang bisa diakses oleh HAMPIR SEMUA STAF (Admin, Gudang, Accounting)
Route::middleware(['auth:sanctum', 'role:admin,gudang,accounting'])->prefix('admin')->group(function () {
    Route::get('/', function (Request $request) {
        return $request->user();
    });
    Route::post('/update-info', [AuthController::class, 'updateAdminProfileInfo']);
    Route::post('/update-image', [AuthController::class, 'updateAdminImage']);
    Route::post('/update-password', [AuthController::class, 'updateAdminPassword']);
});

// GRUP B: DASHBOARD ANALITIK (Hanya Admin)
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/dashboard')->group(function () {
    Route::get('/master-data', [DashboardController::class, 'getDashboardMasterData']);
    Route::get('/stats', [DashboardController::class, 'getStats']);
    Route::get('/revenue-chart', [DashboardController::class, 'getRevenueChart']);
    Route::get('/popular-products', [DashboardController::class, 'getPopularProducts']);
    Route::get('/predicted-bestsellers', [DashboardController::class, 'getPredictedBestsellers']);
    Route::get('/recent-activities', [DashboardController::class, 'getRecentActivities']);
    Route::get('/daily-average', [DashboardController::class, 'getAverageDailyRevenue']);
});

// // GRUP C: MANAJEMEN KATEGORI & SISTEM (Hanya Admin)
// Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
//     Route::get('/categories', [CategoryController::class, 'index']);
//     Route::post('/categories', [CategoryController::class, 'store']);
//     Route::put('/categories/{id}', [CategoryController::class, 'update']);
//     Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
//     Route::get('/categories/{id}', [CategoryController::class, 'show']);

//     Route::get('/admin/users', [AuthController::class, 'getAllUsers']);
//     Route::get('/admin/users/{id}', [AuthController::class, 'getUserDetail']);

//     Route::get('/admin/messages', [ContactController::class, 'getInboundMessages']);
//     Route::get('/admin/messages/unread-count', [ContactController::class, 'getUnreadCount']);
//     Route::get('/admin/messages/{id}', [ContactController::class, 'showAdminMessage']);
//     Route::post('/admin/messages/{id}/respond', [ContactController::class, 'respondMessage']);

//     Route::get('/admin/subscribers', function () {
//         return response()->json(Subscriber::latest()->get());
//     });
// });

// GRUP C: MANAJEMEN KATEGORI & SISTEM (Hanya Admin)
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::put('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
    Route::get('/categories/{id}', [CategoryController::class, 'show']);

    Route::get('/admin/users', [AuthController::class, 'getAllUsers']);
    Route::get('/admin/users/{id}', [AuthController::class, 'getUserDetail']);

    Route::get('/admin/subscribers', function () {
        return response()->json(Subscriber::latest()->get());
    });
});

// [BARU] GRUP KHUSUS CUSTOMER SERVICE & SUPERADMIN
Route::middleware(['auth:sanctum', 'role:admin,superadmin,cs'])->group(function () {
    Route::get('/admin/messages', [ContactController::class, 'getInboundMessages']);
    Route::get('/admin/messages/unread-count', [ContactController::class, 'getUnreadCount']);
    Route::get('/admin/messages/{id}', [ContactController::class, 'showAdminMessage']);
    Route::post('/admin/messages/{id}/respond', [ContactController::class, 'respondMessage']);
    Route::get('/admin/users', [AuthController::class, 'getAllUsers']);
    Route::get('/admin/users/{id}', [AuthController::class, 'getUserDetail']);
});

// [BARU] GRUP KHUSUS SUPERADMIN (SYSTEM SETTINGS)
// Route::middleware(['auth:sanctum', 'role:superadmin'])->group(function () {
//     Route::get('/admin/access-policies', [\App\Http\Controllers\AccessPolicyController::class, 'getPolicies']);
//     Route::post('/admin/access-policies', [\App\Http\Controllers\AccessPolicyController::class, 'savePolicies']);
// });

// [PERBAIKAN] Buka akses GET untuk semua tipe staf agar frontend bisa membaca rule tombol
Route::middleware(['auth:sanctum', 'role:superadmin,admin,gudang,accounting,cs'])->group(function () {
    Route::get('/admin/access-policies', [\App\Http\Controllers\AccessPolicyController::class, 'getPolicies']);
});

// [PERBAIKAN] POST tetap DIBATASI ketat HANYA untuk superadmin
Route::middleware(['auth:sanctum', 'role:superadmin'])->group(function () {
    Route::post('/admin/access-policies', [\App\Http\Controllers\AccessPolicyController::class, 'savePolicies']);
});

// GRUP D: MANAJEMEN PRODUK KREASI & HAPUS (Hanya Admin)
Route::middleware(['auth:sanctum', 'role:admin,superadmin'])->group(function () {
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
    Route::put('/products/{id}/restore', [ProductController::class, 'restore']);
    Route::delete('/products/{id}/force', [ProductController::class, 'forceDelete']);
    Route::post('/admin/s3/presign', [S3UploadController::class, 'presign']);

    Route::get('/admin/audit-logs', [AuditLogController::class, 'index']);

    // CRUD Event
    Route::get('/admin/events', [EventController::class, 'index']);
    Route::get('/admin/events/{id}', [EventController::class, 'show']);
    Route::post('/admin/events', [EventController::class, 'store']);
    Route::put('/admin/events/{id}', [EventController::class, 'update']);
    Route::delete('/admin/events/{id}', [EventController::class, 'destroy']);

    Route::prefix('admin/affiliates')->group(function () {
        Route::get('/dashboard', [AffiliateController::class, 'index']);
        Route::post('/withdrawals/{id}/approve', [AffiliateController::class, 'approve']);
        // [BARU] Rute Persetujuan Afiliator
        Route::post('/applications/{id}/approve', [AffiliateController::class, 'approveApplication']);
    });
});

// GRUP E: STOK & GUDANG (Admin & Gudang)
Route::middleware(['auth:sanctum', 'role:admin,gudang'])->group(function () {
    Route::get('/admin/product-stocks', [ProductStockController::class, 'index']);
    Route::post('/admin/product-stocks/{productId}', [ProductStockController::class, 'store']);
});

// GRUP F: TRANSAKSI & PENGIRIMAN (Admin & Gudang)
Route::middleware(['auth:sanctum', 'role:admin,gudang'])->group(function () {
    Route::get('/admin/transactions', [TransactionController::class, 'allTransactions']);
    Route::get('/admin/transactions/{id}', [TransactionController::class, 'adminShow']);
    Route::post('/admin/transactions/tracking/bulk', [TransactionController::class, 'adminBulkTrackOrders']);
    Route::get('/admin/transactions/{id}/tracking', [TransactionController::class, 'adminTrackOrder']);
    Route::get('/admin/transactions/{id}/print-label', [TransactionController::class, 'printLabel']);
});

// GRUP G: ACCOUNTING & KEUANGAN (Admin & Accounting)
Route::middleware(['auth:sanctum', 'role:admin,accounting'])->prefix('admin')->group(function () {
    Route::get('/sales-report', [TransactionController::class, 'salesReport']);

    // Approval Refund
    Route::post('/transactions/{id}/refund-approve', [TransactionController::class, 'approveRefund']);
    Route::post('/transactions/{id}/refund-reject', [TransactionController::class, 'rejectRefund']);

    // Modul Accounting Khusus
    Route::apiResource('category-coas', CategoryCoaController::class);
    Route::apiResource('coas', CoaController::class);
    Route::post('coas/{id}/post', [CoaController::class, 'postCoa']);
    Route::apiResource('payments', TransferReceivePaymentController::class);

    Route::apiResource('suppliers', InvoiceController::class)->except(['create', 'edit', 'show']);
    Route::get('suppliers', [InvoiceController::class, 'indexSupplier']);
    Route::post('suppliers', [InvoiceController::class, 'storeSupplier']);
    Route::put('suppliers/{id}', [InvoiceController::class, 'updateSupplier']);
    Route::delete('suppliers/{id}', [InvoiceController::class, 'deleteSupplier']);
    Route::get('invoices', [InvoiceController::class, 'indexInvoice']);
    Route::post('invoices', [InvoiceController::class, 'storeInvoice']);
    Route::put('invoices/{id}', [InvoiceController::class, 'updateInvoice']);
    Route::post('invoices/{id}/pay', [InvoiceController::class, 'processPayment']);
    Route::delete('invoices/{id}', [InvoiceController::class, 'deleteInvoice']);
});

// GRUP H: CHAT REALTIME (Admin & Customer)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/chat/admins', [ChatController::class, 'getAdmins']);
    Route::get('/chat/messages/{id}', [ChatController::class, 'getMessages']);
    Route::post('/chat/send', [ChatController::class, 'sendMessage']);
    Route::post('/chat/read/{id}', [ChatController::class, 'markAsRead']);
    Route::post('/chat/typing', [ChatController::class, 'typing']);
});

Route::get('/exchange-rates', function () {
    if (! Cache::has('exchange_rates')) {
        Artisan::call('currency:update-rates');
    }

    $rates = Cache::get('exchange_rates', ['IDR' => 1]);

    return response()->json([
        'status' => 'success',
        'base' => 'IDR',
        'data' => [
            'rates' => $rates,
            'last_updated' => now()->timezone('Asia/Jakarta')->toDateTimeString(),
        ],
    ], 200);
});
