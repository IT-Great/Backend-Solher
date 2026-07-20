<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReviewController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'transaction_id' => 'required|exists:transactions,id',
            'product_id' => 'required|exists:products,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
            'images' => 'nullable|array|max:3', // Maksimal 3 gambar
            'images.*' => 'image|mimes:jpeg,png,jpg|max:5120', // Maksimal 5MB per gambar
        ]);

        $user = $request->user();

        // 1. Validasi "Verified Buyer"
        $transaction = Transaction::where('user_id', $user->id)
            ->where('id', $request->transaction_id)
            ->where('status', 'completed') // HANYA BISA JIKA PESANAN SELESAI
            ->first();

        if (!$transaction) {
            return response()->json(['message' => 'Anda belum menyelesaikan pembelian untuk produk ini.'], 403);
        }

        // 2. Pastikan produk memang ada di dalam transaksi tersebut
        $hasProduct = $transaction->details()->where('product_id', $request->product_id)->exists();
        if (!$hasProduct) {
            return response()->json(['message' => 'Produk ini tidak ada di dalam pesanan Anda.'], 400);
        }

        // 3. Upload Gambar
        $imageUrls = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {
                // Simpan ke S3 seperti bukti refund Anda sebelumnya
                $path = $file->store('product_reviews', [
                    'disk' => 's3',
                    'visibility' => 'public'
                ]);
                $imageUrls[] = Storage::disk('s3')->url($path);
            }
        }

        // 4. Simpan ke Database
        $review = Review::create([
            'user_id' => $user->id,
            'transaction_id' => $transaction->id,
            'product_id' => $request->product_id,
            'rating' => $request->rating,
            'comment' => $request->comment,
            'images' => empty($imageUrls) ? null : $imageUrls,
            'is_approved' => true,
        ]);

        return response()->json(['message' => 'Review berhasil dikirim! Terima kasih atas ulasan Anda.', 'data' => $review]);
    }
}
