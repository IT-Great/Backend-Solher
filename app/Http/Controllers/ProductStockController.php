<?php

// namespace App\Http\Controllers;

// use App\Models\Product;
// use Illuminate\Support\Str;
// use App\Models\ProductStock;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Cache;

// class ProductStockController extends Controller
// {
//     /**
//      * Mengambil semua produk beserta detail batch stoknya.
//      */
//     public function index()
//     {
//         // [PERBAIKAN 1]: Biarkan Backend mengambil data mentah.
//         // Kita buang orderBy('created_at', 'asc') karena Frontend (Vue)
//         // sudah memiliki fungsi `sortBatchesFIFO()` yang menanganinya secara visual.
//         $products = Product::with(['category', 'stocks' => function($q) {
//             $q->where('quantity', '>', 0);
//         }])->latest()->get();

//         return response()->json($products);
//     }

//     /**
//      * Menambah stok baru (Batch baru) secara aman dengan Pessimistic Locking.
//      */
//     public function store(Request $request, $productId)
//     {
//         $request->validate([
//             'quantity' => 'required|integer|min:1'
//         ]);

//         try {
//             // [PERBAIKAN 2]: Membungkus SELURUH proses dalam DB Transaction
//             DB::transaction(function () use ($request, $productId) {

//                 // 1. AMBIL & KUNCI BARIS (Pessimistic Locking)
//                 // Menggunakan lockForUpdate() MENCEGAH admin lain atau transaksi customer
//                 // mengubah stok produk ini pada milidetik yang sama. Mereka harus antre menunggu ini selesai.
//                 $product = Product::lockForUpdate()->findOrFail($productId);

//                 // 2. Generate Kode Unik Batch
//                 $batchCode = 'STK-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4));

//                 // 3. Catat Riwayat Kedatangan Batch
//                 ProductStock::create([
//                     'product_id' => $product->id,
//                     'batch_code' => $batchCode,
//                     'quantity' => $request->quantity,
//                     'initial_quantity' => $request->quantity
//                 ]);

//                 // 4. Perbarui Total Stok Master (Aman dari Race Condition karena sudah di-lock)
//                 $product->increment('stock', $request->quantity);
//             });

//             Cache::tags(['catalog'])->flush();

//             return response()->json(['message' => 'New stock batch added successfully.']);

//         } catch (\Exception $e) {
//             report($e);
//             // Pencatatan Error Sistem agar Admin Server bisa melakukan pelacakan (Debugging)
//             Log::error("Stock Addition Error (Product ID: {$productId}): " . $e->getMessage());

//             return response()->json([
//                 'message' => 'Failed to add stock batch due to system error.'
//             ], 500);
//         }
//     }
// }

// namespace App\Http\Controllers;

// use App\Models\Product;
// use Illuminate\Support\Str;
// use App\Models\ProductStock;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Cache;

// class ProductStockController extends Controller
// {
//     /**
//      * Mengambil semua produk beserta detail batch stoknya.
//      */
//     public function index()
//     {
//         // 👇 [PERBAIKAN FATAL 1]: Gunakan paginate() agar RAM Server aman
//         // dari OOM (Out of Memory) saat produk mencapai ribuan.
//         $products = Product::with(['category', 'stocks' => function($q) {
//             $q->where('quantity', '>', 0);
//         }])->latest()->paginate(50); // Ambil 50 data per halaman

//         return response()->json($products);
//     }

//     /**
//      * Menambah stok baru (Batch baru) secara aman dengan Pessimistic Locking.
//      */
//     public function store(Request $request, $productId)
//     {
//         $request->validate([
//             'quantity' => 'required|integer|min:1'
//         ]);

//         try {
//             DB::transaction(function () use ($request, $productId) {

//                 // 1. AMBIL & KUNCI BARIS (Pessimistic Locking) - AMAN DARI DEADLOCK
//                 $product = Product::lockForUpdate()->findOrFail($productId);

//                 // 2. Generate Kode Unik Batch
//                 $batchCode = 'STK-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4));

//                 // 3. Catat Riwayat Kedatangan Batch
//                 ProductStock::create([
//                     'product_id' => $product->id,
//                     'batch_code' => $batchCode,
//                     'quantity' => $request->quantity,
//                     'initial_quantity' => $request->quantity
//                 ]);

//                 // 4. Perbarui Total Stok Master
//                 $product->increment('stock', $request->quantity);
//             });

//             // 👇 [PERBAIKAN FATAL 2]: Jangan gunakan flush()!
//             // Cukup hapus cache produk SPESIFIK ini saja agar produk lain tidak ikut terhapus
//             // dan mencegah Cache Stampede yang bisa mematikan MySQL.
//             Cache::tags(['catalog'])->forget("products.detail.{$productId}");

//             return response()->json(['message' => 'New stock batch added successfully.']);

//         } catch (\Exception $e) {
//             report($e);
//             // Pencatatan Error Sistem agar Admin Server bisa melakukan pelacakan (Debugging)
//             Log::error("Stock Addition Error (Product ID: {$productId}): " . $e->getMessage(), [
//                 'trace' => $e->getTraceAsString()
//             ]);

//             return response()->json([
//                 'message' => 'Failed to add stock batch due to system error.'
//             ], 500);
//         }
//     }
// }

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Support\Str;
use App\Models\ProductStock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ProductStockController extends Controller
{
    /**
     * Mengambil semua produk beserta detail batch stoknya.
     */
    public function index()
    {
        // 👇 PERBAIKAN: Dikembalikan menggunakan get() agar Front-End Vue
        // dapat melakukan Client-Side Pagination, Search global, dan
        // Export ke Excel/PDF secara utuh.
        $products = Product::with(['category', 'stocks' => function($q) {
            $q->where('quantity', '>', 0);
        }])->latest()->get();

        return response()->json($products);
    }

    /**
     * Menambah stok baru (Batch baru) secara aman dengan Pessimistic Locking.
     */
    // public function store(Request $request, $productId)
    // {
    //     $request->validate([
    //         'quantity' => 'required|integer|min:1'
    //     ]);

    //     try {
    //         DB::transaction(function () use ($request, $productId) {

    //             // 1. AMBIL & KUNCI BARIS (Pessimistic Locking) - AMAN DARI DEADLOCK
    //             $product = Product::lockForUpdate()->findOrFail($productId);

    //             // 2. Generate Kode Unik Batch
    //             $batchCode = 'STK-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4));

    //             // 3. Catat Riwayat Kedatangan Batch
    //             ProductStock::create([
    //                 'product_id' => $product->id,
    //                 'batch_code' => $batchCode,
    //                 'quantity' => $request->quantity,
    //                 'initial_quantity' => $request->quantity
    //             ]);

    //             // 4. Perbarui Total Stok Master
    //             $product->increment('stock', $request->quantity);
    //         });

    //         // 5. Hapus cache spesifik produk agar tidak terjadi Cache Stampede
    //         Cache::tags(['catalog'])->forget("products.detail.{$productId}");

    //         return response()->json(['message' => 'New stock batch added successfully.']);

    //     } catch (\Exception $e) {
    //         report($e);

    //         Log::error("Stock Addition Error (Product ID: {$productId}): " . $e->getMessage(), [
    //             'trace' => $e->getTraceAsString()
    //         ]);

    //         return response()->json([
    //             'message' => 'Failed to add stock batch due to system error.'
    //         ], 500);
    //     }
    // }

    /**
     * Menambah atau Mengurangi stok baru secara aman dengan Pessimistic Locking.
     */
    public function store(Request $request, $productId)
    {
        // 👇 PERBAIKAN: Validasi diizinkan menerima angka negatif, kecuali angka 0
        $request->validate([
            'quantity' => 'required|integer|not_in:0'
        ]);

        try {
            DB::transaction(function () use ($request, $productId) {

                // 1. AMBIL & KUNCI BARIS (Pessimistic Locking) - AMAN DARI DEADLOCK
                $product = Product::lockForUpdate()->findOrFail($productId);

                // 2. [BARU] VALIDASI PENGURANGAN STOK
                // Pastikan jika quantity negatif, stok saat ini cukup untuk dikurangi
                if ($request->quantity < 0 && $product->stock < abs($request->quantity)) {
                    // Gunakan exception khusus untuk ditangkap nanti
                    throw new \Exception('InsufficientStockError');
                }

                // 3. Generate Kode Unik Batch (Beri tanda -OUT untuk pengeluaran)
                $prefix = $request->quantity > 0 ? 'STK-IN-' : 'STK-OUT-';
                $batchCode = $prefix . now()->format('YmdHis') . '-' . strtoupper(Str::random(4));

                // 4. Catat Riwayat Perubahan Batch
                ProductStock::create([
                    'product_id' => $product->id,
                    'batch_code' => $batchCode,
                    'quantity' => $request->quantity,
                    'initial_quantity' => abs($request->quantity) // Tetap simpan nilai absolut untuk referensi
                ]);

                // 5. Perbarui Total Stok Master
                // increment() aman digunakan dengan nilai negatif (akan menjadi decrement)
                $product->increment('stock', $request->quantity);
            });

            // 6. Hapus cache spesifik produk agar tidak terjadi Cache Stampede
            Cache::tags(['catalog'])->forget("products.detail.{$productId}");

            $msg = $request->quantity > 0 ? 'New stock batch added successfully.' : 'Stock deducted successfully.';
            return response()->json(['message' => $msg]);

        } catch (\Exception $e) {
            if ($e->getMessage() === 'InsufficientStockError') {
                 return response()->json([
                    'message' => 'Stok saat ini tidak mencukupi untuk dilakukan pengurangan.'
                ], 422);
            }

            report($e);

            Log::error("Stock Modification Error (Product ID: {$productId}): " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to process stock modification due to system error.'
            ], 500);
        }
    }
}
