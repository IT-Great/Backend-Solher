<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use App\Models\StockWaitlist;

class StockWaitlistController extends Controller
{
    public function subscribe(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'email'      => 'required|email|max:255',
        ]);

        $product = Product::findOrFail($request->product_id);

        if ($product->stock > 0) {
            return response()->json([
                'message' => 'Produk ini sudah tersedia dan dapat langsung dibeli.'
            ], 400);
        }

        $user = $request->user('sanctum');

        $waitlist = StockWaitlist::firstOrCreate(
            [
                'product_id'  => $product->id,
                'email'       => strtolower(trim($request->email)),
                'is_notified' => false,
            ],
            [
                'user_id' => $user?->id,
            ]
        );

        return response()->json([
            'message' => 'Anda telah terdaftar di daftar tunggu prioritas kami.',
            'data'    => $waitlist,
        ], 201);
    }
}
