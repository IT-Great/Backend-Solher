<?php

// namespace App\Http\Controllers;

// use App\Models\Category;
// use App\Services\CategoryService;
// use Illuminate\Http\JsonResponse;
// use App\Http\Controllers\Controller;
// use App\Http\Requests\CategoryRequest;
// use App\Http\Resources\CategoryResource;

// class CategoryController extends Controller
// {
//     protected $categoryService;

//     public function __construct(CategoryService $categoryService)
//     {
//         $this->categoryService = $categoryService;
//     }

//     public function index()
//     {
//         $categories = $this->categoryService->getAllCategories();
//         return CategoryResource::collection($categories);
//     }

//     public function store(CategoryRequest $request): JsonResponse
//     {
//         $category = $this->categoryService->createCategory($request->validated());

//         return (new CategoryResource($category))
//             ->response()
//             ->setStatusCode(201);
//     }

//     public function update(CategoryRequest $request, $id): CategoryResource
//     {
//         $category = Category::findOrFail($id);
//         $updated = $this->categoryService->updateCategory($category, $request->validated());

//         return new CategoryResource($updated);
//     }

//     public function destroy($id): JsonResponse
//     {
//         try {
//             $this->categoryService->deleteCategory($id);
//             return response()->json(['message' => 'Category successfully deleted.']);
//         } catch (\Exception $e) {
//             report($e);

//             if ($e->getCode() === 409) {
//                 return response()->json(['message' => $e->getMessage()], 409);
//             }

//             return response()->json(['message' => 'Internal Server Error'], 500);
//         }
//     }

//     public function show($id): CategoryResource
//     {
//         $category = $this->categoryService->getCategoryById($id);
//         return new CategoryResource($category);
//     }
// }

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Cache;

class CategoryController extends Controller
{
    /**
     * Mengambil semua kategori dengan caching 24 jam.
     */
    public function index()
    {
        $categories = Cache::remember('categories_all', now()->addDay(), function () {
            return Category::latest()->get();
        });

        $formattedCategories = $categories->map(function ($category) {
            return $this->formatCategoryResponse($category);
        });

        return response()->json($formattedCategories);
    }

    /**
     * Mengambil detail kategori spesifik beserta produknya.
     */
    public function show($id)
    {
        $category = Category::with('products')->findOrFail($id);

        return response()->json($this->formatCategoryResponse($category));
    }

    /**
     * Membuat kategori baru.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('categories', 'code')],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'promo_config' => 'nullable|array',
        ]);

        $category = Category::create($validated);

        $this->clearCategoryCache();

        return response()->json($this->formatCategoryResponse($category), 201);
    }

    /**
     * Memperbarui kategori yang ada.
     */
    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('categories', 'code')->ignore($category->id)
            ],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'promo_config' => 'nullable|array',
        ]);

        $category->update($validated);

        $this->clearCategoryCache();

        return response()->json($this->formatCategoryResponse($category->fresh()));
    }

    /**
     * Menghapus kategori (dengan proteksi relasi produk).
     */
    public function destroy($id)
    {
        try {
            $category = Category::findOrFail($id);

            // Proteksi: Tidak bisa menghapus kategori yang masih memiliki produk
            if ($category->products()->exists()) {
                return response()->json([
                    'message' => 'Cannot delete category because it contains products.'
                ], 409);
            }

            $category->delete();
            $this->clearCategoryCache();

            return response()->json(['message' => 'Category successfully deleted.']);

        } catch (\Exception $e) {
            report($e);
            return response()->json(['message' => 'Internal Server Error'], 500);
        }
    }

    /**
     * Memformat objek Category ke dalam struktur JSON yang seragam (Pengganti CategoryResource).
     */
    private function formatCategoryResponse(Category $category): array
    {
        $response = [
            'id' => $category->id,
            'category_code' => $category->code,
            'category_name' => $category->name,
            'meta' => [
                'description' => $category->description ?? 'No description provided.',
                'slug' => str($category->name)->slug(),
            ],
            'promo_config' => $category->promo_config,
            'timestamps' => [
                'created_at' => $category->created_at?->toDateTimeString(),
            ]
        ];

        // Hanya sertakan produk jika relasinya dimuat (whenLoaded)
        if ($category->relationLoaded('products')) {
            $response['products'] = $category->products;
        }

        return $response;
    }

    /**
     * Menghapus cache kategori dan mereset cache katalog.
     */
    private function clearCategoryCache(): void
    {
        // 1. Menghapus cache khusus daftar kategori
        Cache::forget('categories_all');

        // 2. Menghapus cache seluruh katalog produk (karena harga bundle menempel di kategori)
        Cache::tags(['catalog'])->flush();
    }
}
