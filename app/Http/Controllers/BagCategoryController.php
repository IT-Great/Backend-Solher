<?php

namespace App\Http\Controllers;

use App\Models\BagCategory;
use Illuminate\Http\Request;

class BagCategoryController extends Controller
{
    public function index()
    {
        $bagCategories = BagCategory::orderBy('id', 'desc')->get();
        return response()->json(['data' => $bagCategories]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:bag_categories,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $bagCategory = BagCategory::create($validated);
        return response()->json(['data' => $bagCategory, 'message' => 'Bag category created'], 201);
    }

    public function show($id)
    {
        // Menyertakan products jika suatu saat nanti sudah ada relasinya
        $bagCategory = BagCategory::with('products')->findOrFail($id);
        return response()->json(['data' => $bagCategory]);
    }

    public function update(Request $request, $id)
    {
        $bagCategory = BagCategory::findOrFail($id);

        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:bag_categories,code,' . $id,
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $bagCategory->update($validated);
        return response()->json(['data' => $bagCategory, 'message' => 'Bag category updated']);
    }

    public function destroy($id)
    {
        $bagCategory = BagCategory::findOrFail($id);

        if ($bagCategory->products()->exists()) {
            return response()->json(['message' => 'Cannot delete because it contains products.'], 409);
        }

        $bagCategory->delete();
        return response()->json(['message' => 'Bag category deleted']);
    }
}
