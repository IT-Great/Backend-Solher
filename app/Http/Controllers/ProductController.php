<?php

namespace App\Http\Controllers;

use App\Jobs\SendNewProductEmailJob;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Subscriber;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Cache;
use Str;

class ProductController extends Controller
{
    // public function index()
    // {
    //     return response()->json(Product::with('category')->latest()->get(), 200);
    // }

    // public function index()
    // {
    //     $products = Product::with('category')
    //         ->where('status', 'active') // Hanya yang aktif
    //         ->latest()
    //         ->get();

    //     return response()->json($products, 200);
    // }

    // // 1. MEMBACA DATA (BACA DARI REDIS CACHE, JIKA KOSONG BACA DARI MYSQL)
    // // =========================================================================
    // public function index()
    // {
    //     // Menyimpan data di RAM (Redis) selama 1 Hari (86400 detik) dengan label 'catalog'
    //     $products = Cache::tags(['catalog'])->remember('products.active', 86400, function () {
    //         return Product::with('category')
    //             ->where('status', 'active')
    //             ->latest()
    //             ->get();
    //     });

    //     return response()->json($products, 200);
    // }

    // 1. MEMBACA DATA (BACA DARI REDIS CACHE, JIKA KOSONG BACA DARI MYSQL)
    // =========================================================================
    public function index()
    {
        // Menyimpan data di RAM (Redis) selama 1 Hari (86400 detik) dengan label 'catalog'
        $products = Cache::tags(['catalog'])->remember('products.active', 86400, function () {
            return Product::with('category')
                // [BARU] Hitung total penjualan dari relasi transactionDetails
                // Ini akan menghasilkan atribut 'transaction_details_sum_quantity'
                ->withSum(['transactionDetails' => function ($query) {
                    // Opsional: Anda bisa membatasi hanya transaksi yang statusnya 'completed'
                    $query->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
                          ->whereIn('transactions.status', ['completed']);
                }], 'quantity')
                ->where('status', 'active')
                ->latest()
                ->get();
        });

        // [BARU] Ubah nama atribut yang panjang menjadi 'total_sold' agar rapi di Frontend
        $products->map(function ($product) {
            // Jika kosong (belum ada penjualan), set ke 0
            $product->total_sold = (int) $product->transaction_details_sum_quantity ?? 0;
            unset($product->transaction_details_sum_quantity); // Buang nama aslinya
            return $product;
        });

        return response()->json($products, 200);
    }

    // public function inactiveProducts()
    // {
    //     $products = Product::with('category')
    //         ->where('status', 'inactive')
    //         ->latest()
    //         ->get();

    //     return response()->json($products, 200);
    // }

    public function inactiveProducts()
    {
        $products = Cache::tags(['catalog'])->remember('products.inactive', 86400, function () {
            return Product::with('category')
                ->where('status', 'inactive')
                ->latest()
                ->get();
        });

        return response()->json($products, 200);
    }

    // public function show($id)
    // {
    //     return response()->json(Product::with('category')->findOrFail($id), 200);
    // }

    // Update fungsi show() agar memuat relasi stocks
    // public function show($id)
    // {
    //     return response()->json(Product::with(['category', 'stocks' => function ($q) {
    //         $q->orderBy('created_at', 'asc');
    //     }])->findOrFail($id), 200);
    // }

    public function show($id)
    {
        // Cache per produk berdasarkan ID-nya
        $product = Cache::tags(['catalog'])->remember("products.detail.{$id}", 86400, function () use ($id) {
            return Product::with(['category', 'stocks' => function ($q) {
                $q->orderBy('created_at', 'asc');
            }])->findOrFail($id);
        });

        return response()->json($product, 200);
    }

    // public function store(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'code' => 'required|unique:products',
    //         'name' => 'required',
    //         'category_id' => 'required|exists:categories,id',
    //         'price' => 'required|numeric',
    //         'discount_price' => 'nullable|numeric|lt:price',
    //         'stock' => 'required|integer',
    //         'image' => 'required|image|max:2048', // 2MB
    //         // [BARU] Validasi multi-image dan video
    //         'variant_images' => 'nullable|array|max:5',
    //         'variant_images.*' => 'image|max:2048', // Tiap gambar maks 2MB
    //         'variant_video' => 'nullable|mimes:mp4,mov,avi|max:5120', // Maks 5MB
    //     ]);

    //     if ($validator->fails()) return response()->json($validator->errors(), 422);

    //     // $data = $request->all();
    //     // if ($request->hasFile('image')) {
    //     //     // Berubah: Simpan ke disk 's3' dengan akses 'public'
    //     //     $path = $request->file('image')->store('products', 's3');
    //     //     Storage::disk('s3')->setVisibility($path, 'public');
    //     //     $data['image'] = Storage::disk('s3')->url($path); // Simpan URL penuh ke database
    //     // }

    //     $data = $request->except(['variant_images', 'variant_video', 'image']);

    //     // 1. Upload Gambar Utama
    //     if ($request->hasFile('image')) {
    //         $path = $request->file('image')->store('products', 's3');
    //         Storage::disk('s3')->setVisibility($path, 'public');
    //         $data['image'] = Storage::disk('s3')->url($path);
    //     }

    //     // 2. Upload Gambar Varian (Array)
    //     $variantImagesUrls = [];
    //     if ($request->hasFile('variant_images')) {
    //         foreach ($request->file('variant_images') as $file) {
    //             $path = $file->store('products/variants', 's3');
    //             Storage::disk('s3')->setVisibility($path, 'public');
    //             $variantImagesUrls[] = Storage::disk('s3')->url($path);
    //         }
    //     }
    //     $data['variant_images'] = count($variantImagesUrls) > 0 ? $variantImagesUrls : null;

    //     // 3. Upload Video
    //     if ($request->hasFile('variant_video')) {
    //         $path = $request->file('variant_video')->store('products/videos', 's3');
    //         Storage::disk('s3')->setVisibility($path, 'public');
    //         $data['variant_video'] = Storage::disk('s3')->url($path);
    //     }

    //     $product = Product::create($data);
    //     return response()->json($product, 201);
    // }

    // public function store(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'code' => 'required|unique:products',
    //         'name' => 'required',
    //         'category_id' => 'required|exists:categories,id',
    //         'price' => 'required|numeric',
    //         'stock' => 'required|integer',

    //         // sekarang URL
    //         'image' => 'required|string',
    //         'variant_images' => 'nullable|array',
    //         'variant_video' => 'nullable|string',
    //     ]);

    //     if ($validator->fails())
    //         return response()->json($validator->errors(), 422);

    //     $product = Product::create($request->all());

    //     return response()->json($product, 201);
    // }

    // public function store(Request $request)
    // {
    //     $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
    //         'code' => 'required|unique:products',
    //         'name' => 'required',
    //         'category_id' => 'required|exists:categories,id',
    //         'price' => 'required|numeric',
    //         'stock' => 'required|integer',
    //         'image' => 'required|string',
    //         'variant_images' => 'nullable|array',
    //         'variant_video' => 'nullable|string',
    //     ]);

    //     if ($validator->fails())
    //         return response()->json($validator->errors(), 422);

    //     // $product = Product::create($request->all());

    //     DB::beginTransaction(); // Gunakan transaksi database
    //     // try {
    //     //     $product = Product::create($request->all());

    //     //     // [BARU] Buat batch stok pertama kali
    //     //     if ($request->stock > 0) {
    //     //         $batchCode = 'STK-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(4));
    //     //         ProductStock::create([
    //     //             'product_id' => $product->id,
    //     //             'batch_code' => $batchCode,
    //     //             'quantity' => $request->stock,
    //     //             'initial_quantity' => $request->stock
    //     //         ]);
    //     //     }
    //     //     // [BARU] BROADCAST KE SEMUA SUBSCRIBER AKTIF
    //     //     // Catatan: Di production skala besar, gunakan Mail::to()->queue() agar web admin tidak loading lama.
    //     //     $subscribers = \App\Models\Subscriber::where('is_active', true)->pluck('email');

    //     //     foreach ($subscribers as $email) {
    //     //         try {
    //     //             \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\NewProductAlertMail($product));
    //     //         } catch (\Exception $e) {
    //     //             \Illuminate\Support\Facades\Log::error("Gagal broadcast produk ke $email: " . $e->getMessage());
    //     //             // Lanjut ke email berikutnya jika 1 gagal
    //     //             continue;
    //     //         }
    //     //     }

    //     //     DB::commit();
    //     //     return response()->json($product, 201);
    //     // } catch (\Exception $e) {
    //     //     DB::rollBack();
    //     //     return response()->json(['message' => $e->getMessage()], 500);
    //     // }

    //     try {
    //         $product = Product::create($request->all());

    //         // Buat batch stok pertama kali
    //         if ($request->stock > 0) {
    //             $batchCode = 'STK-' . now()->format('YmdHis') . '-' . strtoupper(\Illuminate\Support\Str::random(4));
    //             ProductStock::create([
    //                 'product_id' => $product->id,
    //                 'batch_code' => $batchCode,
    //                 'quantity' => $request->stock,
    //                 'initial_quantity' => $request->stock
    //             ]);
    //         }

    //         // =========================================================================
    //         // [PERBAIKAN] ASYNCHRONOUS BROADCAST MENGGUNAKAN LARAVEL QUEUE
    //         // =========================================================================
    //         $subscribers = \App\Models\Subscriber::where('is_active', true)->pluck('email');

    //         foreach ($subscribers as $email) {
    //             // Melempar (Dispatch) tugas ke tabel antrean (jobs)
    //             // Ini terjadi dalam hitungan milidetik, tanpa menunggu email terkirim!
    //             \App\Jobs\SendNewProductEmailJob::dispatch($email, $product);
    //         }
    //         // =========================================================================

    //         DB::commit();
    //         return response()->json($product, 201);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json(['message' => $e->getMessage()], 500);
    //     }
    // }

    public function store(Request $request)
    {
        // 1. Validasi File Fisik
        $validator = Validator::make($request->all(), [
            'code' => 'required|unique:products',
            'name' => 'required',
            'category_id' => 'required|exists:categories,id',
            'price' => 'required|numeric',
            'stock' => 'required|integer',
            'weight' => 'required|integer|min:1',          // <--- BARU
            'length' => 'nullable|numeric|min:0',          // <--- BARU
            'width' => 'nullable|numeric|min:0',           // <--- BARU
            'height' => 'nullable|numeric|min:0',          // <--- BARU
            'material' => 'nullable|string|max:255',       // <--- BARU
            'strap_length' => 'nullable|string|max:255', // <--- BARU DITAMBAHKAN
            // 'color' => 'nullable|string|max:50',  // <--- BARU
            'color' => 'nullable|array',             // <--- UBAH JADI ARRAY
            'color.*' => 'string|max:50',            // <--- VALIDASI ISI ARRAY
            // 'image' => 'required|image|max:2048', // Harus file gambar, maks 2MB
            'image' => 'required|image',
            'variant_images' => 'nullable|array|max:5',
            // 'variant_images.*' => 'image|max:2048',
            'variant_images.*' => 'image',
            // 'variant_video' => 'nullable|mimes:mp4,mov,avi|max:5120',
            'variant_video' => 'nullable|mimes:mp4,mov,avi',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        DB::beginTransaction();
        try {
            $data = $request->except(['variant_images', 'variant_video', 'image']);

            // 2. Upload Gambar Utama ke Local Storage
            if ($request->hasFile('image')) {
                // public disk akan mengarah ke storage/app/public
                $path = $request->file('image')->store('products', 'public');
                // url() akan menghasilkan https://domain.com/storage/products/...
                // $data['image'] = url(Storage::url($path));

                // [PERBAIKAN] Hanya simpan path relatifnya saja, buang fungsi url()
                $data['image'] = Storage::url($path);
            }

            // 3. Upload Gambar Varian (Array)
            $variantImagesUrls = [];
            if ($request->hasFile('variant_images')) {
                foreach ($request->file('variant_images') as $file) {
                    $path = $file->store('products/variants', 'public');
                    // $variantImagesUrls[] = url(Storage::url($path));
                    $variantImagesUrls[] = Storage::url($path); // [PERBAIKAN]
                }
            }
            $data['variant_images'] = count($variantImagesUrls) > 0 ? $variantImagesUrls : null;

            // 4. Upload Video
            if ($request->hasFile('variant_video')) {
                $path = $request->file('variant_video')->store('products/videos', 'public');
                // $data['variant_video'] = url(Storage::url($path));
                $data['variant_video'] = Storage::url($path); // [PERBAIKAN]
            }

            // Simpan ke DB
            $product = Product::create($data);

            // Buat batch stok pertama kali
            if ($request->stock > 0) {
                $batchCode = 'STK-'.now()->format('YmdHis').'-'.strtoupper(\Illuminate\Support\Str::random(4));
                ProductStock::create([
                    'product_id' => $product->id,
                    'batch_code' => $batchCode,
                    'quantity' => $request->stock,
                    'initial_quantity' => $request->stock,
                ]);
            }

            // BROADCAST KE SEMUA SUBSCRIBER AKTIF MENGGUNAKAN LARAVEL QUEUE
            $subscribers = Subscriber::where('is_active', true)->pluck('email');
            foreach ($subscribers as $email) {
                SendNewProductEmailJob::dispatch($email, $product);
            }

            DB::commit();

            // [BARU] Bersihkan Cache agar Katalog di Web User langsung ter-update!
            Cache::tags(['catalog'])->flush();

            return response()->json($product, 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    // public function update(Request $request, $id)
    // {
    //     $product = Product::findOrFail($id);
    //     // $data = $request->all();

    //     // if ($request->hasFile('image')) {
    //     //     // Berubah: Hapus file lama di S3 jika ada
    //     //     if ($product->image) {
    //     //         // Kita ambil path relatif dari URL penuh yang tersimpan
    //     //         $oldPath = str_replace(Storage::disk('s3')->url(''), '', $product->image);
    //     //         Storage::disk('s3')->delete($oldPath);
    //     //     }

    //     //     $path = $request->file('image')->store('products', 's3');
    //     //     Storage::disk('s3')->setVisibility($path, 'public');
    //     //     $data['image'] = Storage::disk('s3')->url($path);
    //     // }

    //     $data = $request->except(['variant_images', 'variant_video', 'image', '_method']);

    //     // 1. Update Gambar Utama
    //     if ($request->hasFile('image')) {
    //         if ($product->image) {
    //             $oldPath = str_replace(Storage::disk('s3')->url(''), '', $product->image);
    //             Storage::disk('s3')->delete($oldPath);
    //         }
    //         $path = $request->file('image')->store('products', 's3');
    //         Storage::disk('s3')->setVisibility($path, 'public');
    //         $data['image'] = Storage::disk('s3')->url($path);
    //     }

    //     // 2. Update Gambar Varian (Untuk update, kita asumsikan jika ada upload baru, hapus yang lama)
    //     // Jika Anda ingin UX di mana admin bisa menghapus satu persatu, itu butuh endpoint terpisah.
    //     // Untuk saat ini, kita timpa total jika ada file baru diunggah.
    //     if ($request->hasFile('variant_images')) {
    //         if ($product->variant_images) {
    //             foreach ($product->variant_images as $oldImgUrl) {
    //                 $oldPath = str_replace(Storage::disk('s3')->url(''), '', $oldImgUrl);
    //                 Storage::disk('s3')->delete($oldPath);
    //             }
    //         }
    //         $variantImagesUrls = [];
    //         foreach ($request->file('variant_images') as $file) {
    //             $path = $file->store('products/variants', 's3');
    //             Storage::disk('s3')->setVisibility($path, 'public');
    //             $variantImagesUrls[] = Storage::disk('s3')->url($path);
    //         }
    //         $data['variant_images'] = $variantImagesUrls;
    //     }

    //     // 3. Update Video
    //     if ($request->hasFile('variant_video')) {
    //         if ($product->variant_video) {
    //             $oldPath = str_replace(Storage::disk('s3')->url(''), '', $product->variant_video);
    //             Storage::disk('s3')->delete($oldPath);
    //         }
    //         $path = $request->file('variant_video')->store('products/videos', 's3');
    //         Storage::disk('s3')->setVisibility($path, 'public');
    //         $data['variant_video'] = Storage::disk('s3')->url($path);
    //     }

    //     $product->update($data);
    //     return response()->json($product, 200);
    // }

    // public function update(Request $request, $id)
    // {
    //     $product = Product::findOrFail($id);

    //     $validator = Validator::make($request->all(), [
    //         'code' => "required|unique:products,code,$id",
    //         'name' => 'required',
    //         'category_id' => 'required|exists:categories,id',
    //         'price' => 'required|numeric',
    //         // 'stock' => 'required|integer',

    //         'image' => 'nullable|string',
    //         'variant_images' => 'nullable|array',
    //         'variant_video' => 'nullable|string',
    //     ]);

    //     if ($validator->fails())
    //         return response()->json($validator->errors(), 422);

    //     /*
    // |--------------------------------------------------------------------------
    // | DELETE OLD FILE IF URL CHANGED
    // |--------------------------------------------------------------------------
    // */

    //     if ($request->image && $product->image !== $request->image) {
    //         $oldPath = str_replace(
    //             Storage::disk('s3')->url(''),
    //             '',
    //             $product->image
    //         );
    //         Storage::disk('s3')->delete($oldPath);
    //     }

    //     if ($request->variant_images) {
    //         foreach ($product->variant_images ?? [] as $oldImg) {
    //             if (!in_array($oldImg, $request->variant_images)) {
    //                 $oldPath = str_replace(
    //                     Storage::disk('s3')->url(''),
    //                     '',
    //                     $oldImg
    //                 );
    //                 Storage::disk('s3')->delete($oldPath);
    //             }
    //         }
    //     }

    //     if (
    //         $request->variant_video &&
    //         $product->variant_video !== $request->variant_video
    //     ) {

    //         $oldPath = str_replace(
    //             Storage::disk('s3')->url(''),
    //             '',
    //             $product->variant_video
    //         );

    //         Storage::disk('s3')->delete($oldPath);
    //     }

    //     // $product->update($request->all());

    //     // [PERBAIKAN] Jangan biarkan 'stock' di-update dari halaman edit
    //     $data = $request->except(['stock']);
    //     $product->update($data);

    //     return response()->json($product, 200);
    // }

    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'code' => "required|unique:products,code,$id",
            'name' => 'required',
            'category_id' => 'required|exists:categories,id',
            'price' => 'required|numeric',
            'weight' => 'required|integer|min:1',          // <--- BARU
            'length' => 'nullable|numeric|min:0',          // <--- BARU
            'width' => 'nullable|numeric|min:0',           // <--- BARU
            'height' => 'nullable|numeric|min:0',          // <--- BARU
            'material' => 'nullable|string|max:255',       // <--- BARU
            // 'color' => 'nullable|string|max:50',  // <--- BARU
            'color' => 'nullable|array',             // <--- UBAH JADI ARRAY
            'color.*' => 'string|max:50',            // <--- VALIDASI ISI ARRAY
            // Saat update, image boleh kosong (jika tidak diganti)
            // 'image' => 'nullable|image|max:2048',
            'image' => 'nullable|image',
            'variant_images' => 'nullable|array|max:5',
            // 'variant_images.*' => 'image|max:2048',
            'variant_images.*' => 'image',
            // 'variant_video' => 'nullable|mimes:mp4,mov,avi|max:5120',
            'variant_video' => 'nullable|mimes:mp4,mov,avi',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $data = $request->except(['variant_images', 'variant_video', 'image', 'stock', '_method']);

        // 1. Hapus & Ganti Gambar Utama Jika Ada Upload Baru
        if ($request->hasFile('image')) {
            if ($product->image) {
                // $oldPath = str_replace(url(Storage::url('')), '', $product->image);
                // Storage::disk('public')->delete($oldPath);

                // Karena kita akan menyimpan path relatif yang dimulai dengan /storage/
                $oldPath = str_replace('/storage/', '', $product->image);
                Storage::disk('public')->delete($oldPath);
            }
            $path = $request->file('image')->store('products', 'public');
            // $data['image'] = url(Storage::url($path));
            $data['image'] = Storage::url($path); // [PERBAIKAN]
        }

        // 2. Hapus & Ganti Varian Jika Ada Upload Baru (OVERWRITE ALL)
        if ($request->hasFile('variant_images')) {
            if ($product->variant_images) {
                foreach ($product->variant_images as $oldImgUrl) {
                    // $oldPath = str_replace(url(Storage::url('')), '', $oldImgUrl);
                    $oldPath = str_replace('/storage/', '', $oldImgUrl);
                    Storage::disk('public')->delete($oldPath);
                }
            }
            $variantImagesUrls = [];
            foreach ($request->file('variant_images') as $file) {
                $path = $file->store('products/variants', 'public');
                // $variantImagesUrls[] = url(Storage::url($path));
                $variantImagesUrls[] = Storage::url($path); // [PERBAIKAN]
            }
            $data['variant_images'] = $variantImagesUrls;
        }

        // 3. Hapus & Ganti Video Jika Ada Upload Baru
        if ($request->hasFile('variant_video')) {
            if ($product->variant_video) {
                // $oldPath = str_replace(url(Storage::url('')), '', $product->variant_video);
                $oldPath = str_replace('/storage/', '', $product->variant_video);
                Storage::disk('public')->delete($oldPath);
            }
            $path = $request->file('variant_video')->store('products/videos', 'public');
            // $data['variant_video'] = url(Storage::url($path));
            $data['variant_video'] = Storage::url($path); // [PERBAIKAN]
        }

        $product->update($data);

        // [BARU] Bersihkan Cache!
        Cache::tags(['catalog'])->flush();

        return response()->json($product, 200);
    }

    // public function destroy($id)
    // {
    //     $product = Product::findOrFail($id);
    //     if ($product->image) {
    //         $path = str_replace(Storage::disk('s3')->url(''), '', $product->image);
    //         Storage::disk('s3')->delete($path);
    //     }
    //     $product->delete();
    //     return response()->json(['message' => 'Product deleted'], 200);
    // }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        $product->update(['status' => 'inactive']);

        // [BARU] Bersihkan Cache!
        Cache::tags(['catalog'])->flush();

        return response()->json(['message' => 'Product deactivated'], 200);
    }

    public function restore($id)
    {
        $product = Product::findOrFail($id);
        $product->update(['status' => 'active']);

        Cache::tags(['catalog'])->flush();

        return response()->json(['message' => 'Product activated'], 200);
    }

    // public function forceDelete($id)
    // {
    //     $product = Product::findOrFail($id);
    //     if ($product->image) {
    //         $path = str_replace(Storage::disk('s3')->url(''), '', $product->image);
    //         Storage::disk('s3')->delete($path);
    //     }
    //     // $product->delete();
    //     // return response()->json(['message' => 'Product deleted permanently'], 200);

    //     try {
    //         $product->delete();
    //     } catch (QueryException $e) {
    //         // return back()->with('error', 'Produk tidak bisa dihapus karena sudah memiliki riwayat transaksi.');
    //         return response()->json(['message' => 'Produk tidak bisa dihapus karena sudah memiliki riwayat transaksi'], 422);
    //     }
    // }

    public function forceDelete($id)
    {
        $product = Product::findOrFail($id);

        // 1. Sesuaikan dengan Local Storage (Bukan S3 lagi)
        // if ($product->image) {
        //     $path = str_replace(url(Storage::url('')), '', $product->image);
        //     Storage::disk('public')->delete($path);
        // }

        if ($product->image) {
            $oldPath = str_replace('/storage/', '', $product->image);
            Storage::disk('public')->delete($oldPath);
        }

        try {
            $product->delete();

            // 2. Bersihkan Cache setelah produk permanen dihapus
            Cache::tags(['catalog'])->flush();

            // 3. Kembalikan respon sukses
            return response()->json(['message' => 'Product deleted permanently'], 200);

        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json(['message' => 'Produk tidak bisa dihapus karena sudah memiliki riwayat transaksi'], 422);
        }
    }

    // public function forceDelete($id)
    // {
    //     // Gunakan findWithTrashed karena produk yang mau di-force delete
    //     // biasanya sudah dalam kondisi soft delete (inactive)
    //     $product = Product::withTrashed()->findOrFail($id);

    //     if ($product->image) {
    //         $path = str_replace(Storage::disk('s3')->url(''), '', $product->image);
    //         Storage::disk('s3')->delete($path);
    //     }

    //     try {
    //         // Gunakan forceDelete() jika Anda menggunakan SoftDeletes,
    //         // kalau delete() biasa dia hanya akan mengisi kolom deleted_at lagi
    //         $product->forceDelete();

    //         return response()->json([
    //             'message' => 'Produk berhasil dihapus permanen',
    //         ], 200);

    //     } catch (QueryException $e) {
    //         // Tambahkan status code 422 atau 400 agar Axios menganggap ini ERROR
    //         return response()->json([
    //             'message' => 'Produk tidak bisa dihapus karena sudah memiliki riwayat transaksi (Integrity Constraint).',
    //         ], 422);
    //     }
    // }
}
