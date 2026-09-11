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
        // 👇 [PERBAIKAN FATAL 1]: Gunakan paginate() agar RAM Server aman
        // dari OOM (Out of Memory) saat produk mencapai ribuan.
        $products = Product::with(['category', 'stocks' => function($q) {
            $q->where('quantity', '>', 0);
        }])->latest()->paginate(50); // Ambil 50 data per halaman

        return response()->json($products);
    }

    /**
     * Menambah stok baru (Batch baru) secara aman dengan Pessimistic Locking.
     */
    public function store(Request $request, $productId)
    {
        $request->validate([
            'quantity' => 'required|integer|min:1'
        ]);

        try {
            DB::transaction(function () use ($request, $productId) {

                // 1. AMBIL & KUNCI BARIS (Pessimistic Locking) - AMAN DARI DEADLOCK
                $product = Product::lockForUpdate()->findOrFail($productId);

                // 2. Generate Kode Unik Batch
                $batchCode = 'STK-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4));

                // 3. Catat Riwayat Kedatangan Batch
                ProductStock::create([
                    'product_id' => $product->id,
                    'batch_code' => $batchCode,
                    'quantity' => $request->quantity,
                    'initial_quantity' => $request->quantity
                ]);

                // 4. Perbarui Total Stok Master
                $product->increment('stock', $request->quantity);
            });

            // 👇 [PERBAIKAN FATAL 2]: Jangan gunakan flush()!
            // Cukup hapus cache produk SPESIFIK ini saja agar produk lain tidak ikut terhapus
            // dan mencegah Cache Stampede yang bisa mematikan MySQL.
            Cache::tags(['catalog'])->forget("products.detail.{$productId}");

            return response()->json(['message' => 'New stock batch added successfully.']);

        } catch (\Exception $e) {
            report($e);
            // Pencatatan Error Sistem agar Admin Server bisa melakukan pelacakan (Debugging)
            Log::error("Stock Addition Error (Product ID: {$productId}): " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to add stock batch due to system error.'
            ], 500);
        }
    }
}
