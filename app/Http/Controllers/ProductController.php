<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Str;

class ProductController extends Controller
{
    public function index()
    {

        $products = Cache::tags(['catalog'])->remember('products.active', 86400, function () {
            return Product::with('category')

                ->withSum(['transactionDetails' => function ($query) {

                    $query->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
                        ->whereIn('transactions.status', ['completed']);
                }], 'quantity')
                ->where('status', 'active')
                ->latest()
                ->get();
        });

        $products->map(function ($product) {

            $product->total_sold = (int) $product->transaction_details_sum_quantity ?? 0;
            unset($product->transaction_details_sum_quantity);

            return $product;
        });

        return response()->json($products, 200);
    }

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

    public function show($identifier)
    {
        $product = Cache::tags(['catalog'])->remember("products.detail.{$identifier}", 86400, function () use ($identifier) {
            return Product::with(['category', 'stocks' => function ($q) {
                $q->orderBy('created_at', 'asc');
            }])
                ->where('slug', $identifier)
                ->orWhere('id', $identifier)
                ->firstOrFail();
        });

        $product->setAttribute('raw_discount_price', $product->getRawOriginal('discount_price'));

        return response()->json($product, 200);
    }

    public function store(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'code' => 'required|unique:products',
            'name' => 'required',
            'category_id' => 'required|exists:categories,id',
            'price' => 'required|numeric|min:0',

            'prices' => 'nullable|array',
            'prices.*' => 'nullable|numeric|min:0',
            'discount_prices' => 'nullable|array',
            'discount_prices.*' => 'nullable|numeric|min:0',

            'stock' => 'required|integer|min:0',
            'weight' => 'required|integer|min:1',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'material' => 'nullable|string|max:255',

            'strap_length' => 'nullable|array',
            'strap_length.*' => 'string|max:255',

            'color' => 'nullable|array',
            'color.*' => 'string|max:50',

            'description' => 'nullable|string',
            'description_en' => 'nullable|string', // [BARU]
            'design' => 'nullable|string',
            'design_en' => 'nullable|string',      // [BARU]

            'image' => 'nullable|image',
            'variant_images' => 'nullable|array|max:5',

            'variant_images.*' => 'image',

            'variant_video' => 'nullable|mimes:mp4,mov,avi',
            'discount_start_date' => 'nullable|date',
            'discount_end_date' => 'nullable|date|after_or_equal:discount_start_date',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        DB::beginTransaction();
        try {
            $data = $request->except(['variant_images', 'variant_video', 'image']);

            $data['slug'] = \Illuminate\Support\Str::slug($request->name);

            $data['prices'] = $request->input('prices', null);
            $data['discount_prices'] = $request->input('discount_prices', null);

            $nullableFields = ['discount_price', 'discount_start_date', 'discount_end_date', 'length', 'width', 'height', 'material', 'strap_length','description','design','description_en', 'design_en'];
            foreach ($nullableFields as $field) {
                if (! isset($data[$field]) || $data[$field] === '' || $data[$field] === 'null') {
                    $data[$field] = null;
                }
            }

            if ($request->hasFile('image')) {
                $data['image'] = $this->optimizeAndSaveImage($request->file('image'), 'products');
            }

            $variantImagesUrls = [];
            if ($request->hasFile('variant_images')) {
                foreach ($request->file('variant_images') as $file) {
                    $variantImagesUrls[] = $this->optimizeAndSaveImage($file, 'products/variants');
                }
            }
            $data['variant_images'] = count($variantImagesUrls) > 0 ? $variantImagesUrls : null;

            if ($request->hasFile('variant_video')) {
                $path = $request->file('variant_video')->store('products/videos', 'public');
                $data['variant_video'] = Storage::url($path);
            }

            $product = Product::create($data);

            if ($request->stock > 0) {
                $batchCode = 'STK-'.now()->format('YmdHis').'-'.strtoupper(\Illuminate\Support\Str::random(4));
                ProductStock::create([
                    'product_id' => $product->id,
                    'batch_code' => $batchCode,
                    'quantity' => $request->stock,
                    'initial_quantity' => $request->stock,
                ]);
            }

            DB::commit();

            Cache::tags(['catalog'])->flush();

            return response()->json($product, 201);
        } catch (\Exception $e) {
            report($e);
            
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'code' => "required|unique:products,code,$id",
            'name' => 'required',
            'category_id' => 'required|exists:categories,id',
            'price' => 'required|numeric',

            'prices' => 'nullable|array',
            'prices.*' => 'nullable|numeric|min:0',
            'discount_prices' => 'nullable|array',
            'discount_prices.*' => 'nullable|numeric|min:0',

            'weight' => 'required|integer|min:1',
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'height' => 'nullable|numeric|min:0',
            'material' => 'nullable|string|max:255',

            'color' => 'nullable|array',
            'color.*' => 'string|max:50',

            'description' => 'nullable|string',
            'description_en' => 'nullable|string', // [BARU]
            'design' => 'nullable|string',
            'design_en' => 'nullable|string',      // [BARU]

            'image' => 'nullable|image',
            'variant_images' => 'nullable|array|max:5',

            'variant_images.*' => 'image',

            'variant_video' => 'nullable|mimes:mp4,mov,avi',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $data = $request->except(['variant_images', 'variant_video', 'image', 'stock', '_method']);

        $data['slug'] = \Illuminate\Support\Str::slug($request->name);

        $data['prices'] = $request->input('prices', null);
        $data['discount_prices'] = $request->input('discount_prices', null);

        $nullableFields = ['discount_price', 'length', 'width', 'height', 'material', 'strap_length','description','design','description_en', 'design_en', 'discount_start_date', 'discount_end_date'];

        foreach ($nullableFields as $field) {
            if (! isset($data[$field]) || $data[$field] === '' || $data[$field] === 'null') {
                $data[$field] = null;
            }
        }

        if ($request->hasFile('image')) {
            if ($product->image) {

                $oldPath = str_replace('/storage/', '', parse_url($product->image, PHP_URL_PATH));
                Storage::disk('public')->delete($oldPath);
            }
            $data['image'] = $this->optimizeAndSaveImage($request->file('image'), 'products');
        }

        if ($request->hasFile('variant_images')) {
            if ($product->variant_images) {
                foreach ($product->variant_images as $oldImgUrl) {

                    $oldPath = str_replace('/storage/', '', parse_url($oldImgUrl, PHP_URL_PATH));
                    Storage::disk('public')->delete($oldPath);
                }
            }
            $variantImagesUrls = [];
            foreach ($request->file('variant_images') as $file) {
                $variantImagesUrls[] = $this->optimizeAndSaveImage($file, 'products/variants');
            }
            $data['variant_images'] = $variantImagesUrls;
        }

        if ($request->hasFile('variant_video')) {
            if ($product->variant_video) {

                $oldPath = str_replace('/storage/', '', parse_url($product->variant_video, PHP_URL_PATH));
                Storage::disk('public')->delete($oldPath);
            }
            $path = $request->file('variant_video')->store('products/videos', 'public');
            $data['variant_video'] = Storage::url($path);
        }

        $product->update($data);

        Cache::tags(['catalog'])->flush();

        return response()->json($product, 200);
    }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        $product->update(['status' => 'inactive']);

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

    public function forceDelete($id)
    {
        $product = Product::findOrFail($id);

        if ($product->image) {

            $oldPath = str_replace('/storage/', '', parse_url($product->image, PHP_URL_PATH));
            Storage::disk('public')->delete($oldPath);
        }

        try {
            $product->delete();

            Cache::tags(['catalog'])->flush();

            return response()->json(['message' => 'Product deleted permanently'], 200);

        } catch (QueryException $e) {
            report($e);
            return response()->json(['message' => 'Produk tidak bisa dihapus karena sudah memiliki riwayat transaksi'], 422);
        }
    }

    /**
     * [BARU] FUNGSI HELPER UNTUK OPTIMASI GAMBAR
     * Fungsi ini akan me-resize gambar dan mengonversinya ke WebP
     */
    private function optimizeAndSaveImage($file, $folder)
    {

        $manager = new ImageManager(new Driver);

        $image = $manager->read($file);

        $image->scaleDown(width: 1000);

        $encoded = $image->toWebp(80);

        $filename = $folder.'/'.Str::random(40).'.webp';

        Storage::disk('public')->put($filename, $encoded->toString());

        return '/storage/'.$filename;
    }
}
