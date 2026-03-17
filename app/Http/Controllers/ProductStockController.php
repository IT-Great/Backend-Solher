<?php

// namespace App\Http\Controllers;

// use App\Models\Product;
// use Illuminate\Support\Str;
// use App\Models\ProductStock;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;

// class ProductStockController extends Controller
// {
//     // Mengambil semua produk beserta detail batch stoknya
//     public function index()
//     {
//         $products = Product::with(['category', 'stocks' => function($q) {
//             $q->where('quantity', '>', 0)->orderBy('created_at', 'asc');
//         }])->latest()->get();

//         return response()->json($products);
//     }

//     // Menambah stok baru (Batch baru)
//     public function store(Request $request, $productId)
//     {
//         $request->validate([
//             'quantity' => 'required|integer|min:1'
//         ]);

//         $product = Product::findOrFail($productId);

//         DB::transaction(function () use ($request, $product) {
//             // Generate Kode: STK-YYYYMMDDHHMMSS-RANDOM
//             $batchCode = 'STK-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4));

//             ProductStock::create([
//                 'product_id' => $product->id,
//                 'batch_code' => $batchCode,
//                 'quantity' => $request->quantity,
//                 'initial_quantity' => $request->quantity
//             ]);

//             // Sync total stok di tabel produk utama
//             $product->increment('stock', $request->quantity);
//         });

//         return response()->json(['message' => 'New stock batch added successfully.']);
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
        // [PERBAIKAN 1]: Biarkan Backend mengambil data mentah.
        // Kita buang orderBy('created_at', 'asc') karena Frontend (Vue)
        // sudah memiliki fungsi `sortBatchesFIFO()` yang menanganinya secara visual.
        $products = Product::with(['category', 'stocks' => function($q) {
            $q->where('quantity', '>', 0);
        }])->latest()->get();

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
            // [PERBAIKAN 2]: Membungkus SELURUH proses dalam DB Transaction
            DB::transaction(function () use ($request, $productId) {

                // 1. AMBIL & KUNCI BARIS (Pessimistic Locking)
                // Menggunakan lockForUpdate() MENCEGAH admin lain atau transaksi customer
                // mengubah stok produk ini pada milidetik yang sama. Mereka harus antre menunggu ini selesai.
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

                // 4. Perbarui Total Stok Master (Aman dari Race Condition karena sudah di-lock)
                $product->increment('stock', $request->quantity);
            });

            Cache::tags(['catalog'])->flush();

            return response()->json(['message' => 'New stock batch added successfully.']);

        } catch (\Exception $e) {
            // Pencatatan Error Sistem agar Admin Server bisa melakukan pelacakan (Debugging)
            Log::error("Stock Addition Error (Product ID: {$productId}): " . $e->getMessage());

            return response()->json([
                'message' => 'Failed to add stock batch due to system error.'
            ], 500);
        }
    }
}
