<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class PromoMerdekaService
{
    /**
     * Algoritma Kalkulasi Promo 17 Agustus (Sesuai Aturan Bos)
     *
     * @param Collection $cartItems (Data item di keranjang belanja)
     * @param array $appliedVouchers (Voucher lain yang sedang aktif di keranjang)
     * @return array
     */
    public function calculatePromo($cartItems, $appliedVouchers = [])
    {
        // 1. RULE: Promo tidak bisa digabung dengan voucher code lainnya
        if (count($appliedVouchers) > 0) {
            return [
                'is_valid' => false,
                'message' => 'Promo 17-an tidak dapat digabungkan dengan voucher lain.',
                'discount_amount' => 0
            ];
        }

        // 2. RULE: Valid 17 Aug only
        // Menggunakan zona waktu Jakarta agar pergantian hari presisi
        $today = Carbon::now()->timezone('Asia/Jakarta');
        if ($today->format('m-d') !== '08-17') {
            return [
                'is_valid' => false,
                'message' => 'Voucher SOLHER17 hanya berlaku khusus pada tanggal 17 Agustus.',
                'discount_amount' => 0
            ];
        }

        // 3. RULE: Min purchase 699K
        $subtotal = 0;

        // Penampung subtotal harga per kelompok kategori
        $categoryTotals = [
            'group_500k' => 0, // Untuk tas C001, C003, C004
            'group_200k' => 0, // Untuk tas C002
            'group_100k' => 0, // Untuk belts C005
        ];

        foreach ($cartItems as $item) {
            // Ambil harga yang berlaku (jika produk lagi ada diskon coret, pakai harga diskonnya)
            $itemPrice = $item->product->discount_price ?? $item->product->price;
            $itemTotal = $itemPrice * $item->quantity;
            $subtotal += $itemTotal;

            // Dapatkan kode kategori produk
            $catCode = $item->product->category->code;

            // Pisahkan perhitungan berdasarkan kode kategori
            if (in_array($catCode, ['C001', 'C003', 'C004'])) {
                $categoryTotals['group_500k'] += $itemTotal;
            } elseif ($catCode === 'C002') {
                $categoryTotals['group_200k'] += $itemTotal;
            } elseif ($catCode === 'C005') {
                $categoryTotals['group_100k'] += $itemTotal;
            } else {
                // Fallback aman: jika ada kategori di luar list, ikut limit default max 500k
                $categoryTotals['group_500k'] += $itemTotal;
            }
        }

        // Cek Minimum Pembelian
        if ($subtotal < 699000) {
            return [
                'is_valid' => false,
                'message' => 'Minimum pembelian untuk promo ini adalah Rp 699.000.',
                'discount_amount' => 0
            ];
        }

        // 4. RULE: Disc 17% per kelompok, dengan Max Cap masing-masing Kategori
        $discount_500k_group = min($categoryTotals['group_500k'] * 0.17, 500000);
        $discount_200k_group = min($categoryTotals['group_200k'] * 0.17, 200000);
        $discount_100k_group = min($categoryTotals['group_100k'] * 0.17, 100000);

        $totalCalculatedDiscount = $discount_500k_group + $discount_200k_group + $discount_100k_group;

        // 5. RULE: Up to 500K for all items (Global Limit Maksimal)
        // Memastikan total diskon keseluruhan keranjang tidak pernah bocor lebih dari 500 Ribu
        $finalDiscount = min($totalCalculatedDiscount, 500000);

        return [
            'is_valid' => true,
            'message' => 'Promo 17 Agustus berhasil diterapkan!',
            'discount_amount' => $finalDiscount,
            'code' => 'SOLHER17'
        ];
    }
}
