<?php

namespace App\Http\Controllers;

use App\Models\AffiliateApplication;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\Withdrawal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AffiliateController extends Controller
{
    /**
     * Menarik semua data statistik untuk Dasbor Afiliator
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();

        // Keamanan lapis pertama: Tolak jika bukan afiliator
        if (!$user->is_affiliate) {
            return response()->json(['message' => 'Anda bukan afiliator terdaftar.'], 403);
        }

        // 1. Saldo Aktif (Tersimpan langsung di tabel user)
        $activeBalance = $user->commission_balance;

        // 2. Saldo Tertunda (Pesanan masih pending/dikirim)
        $pendingBalance = Transaction::where('affiliate_id', $user->id)
            ->where('commission_status', 'pending')
            ->sum('commission_earned');

        // 3. Total Pendapatan Historis (Yang sudah cair/settled)
        $totalEarned = Transaction::where('affiliate_id', $user->id)
            ->where('commission_status', 'settled')
            ->sum('commission_earned');

        // 4. Riwayat Transaksi (Ambil 50 terbaru untuk performa)
        $transactions = Transaction::where('affiliate_id', $user->id)
            ->whereNotNull('commission_status') // Hanya ambil yang ada afiliasinya
            ->orderBy('created_at', 'desc')
            ->take(50)
            ->get(['id', 'created_at', 'commission_status', 'commission_earned']);

        return response()->json([
            'status' => 'success',
            'data' => [
                'referral_code' => $user->referral_code,
                'active_balance' => $activeBalance,
                'pending_balance' => $pendingBalance,
                'total_earned' => $totalEarned,
                'transactions' => $transactions
            ]
        ]);
    }

    /**
     * Memproses permintaan penarikan dana (Withdrawal)
     */
    // public function withdraw(Request $request)
    // {
    //     $request->validate([
    //         'bank_name' => 'required|string|max:100',
    //         'account_number' => 'required|string|max:50',
    //         'account_name' => 'required|string|max:100',
    //         'amount' => 'required|numeric|min:10000', // Minimal tarik Rp10.000
    //     ]);

    //     $userId = $request->user()->id;
    //     $amountToWithdraw = $request->amount;

    //     try {
    //         // Gunakan DB Transaction agar jika gagal di tengah jalan, uang akan dikembalikan (Rollback)
    //         return DB::transaction(function () use ($userId, $request, $amountToWithdraw) {

    //             // KUNCI BARIS USER INI (lockForUpdate) untuk mencegah klik ganda secara brutal (Race Condition)
    //             $user = User::where('id', $userId)->lockForUpdate()->first();

    //             // Validasi final: Pastikan saldo mencukupi
    //             if ($user->commission_balance < $amountToWithdraw) {
    //                 throw new \Exception('Saldo aktif Anda tidak mencukupi untuk penarikan ini.');
    //             }

    //             // 1. Buat catatan penarikan dengan status pending
    //             $withdrawal = Withdrawal::create([
    //                 'affiliate_id' => $user->id,
    //                 'amount' => $amountToWithdraw,
    //                 'bank_name' => $request->bank_name,
    //                 'account_number' => $request->account_number,
    //                 'account_name' => $request->account_name,
    //                 'status' => 'pending',
    //             ]);

    //             // 2. Potong saldo komisi user
    //             $user->decrement('commission_balance', $amountToWithdraw);

    //             return response()->json([
    //                 'status' => 'success',
    //                 'message' => 'Permintaan penarikan dana berhasil diajukan.',
    //                 'data' => [
    //                     'withdrawal_id' => $withdrawal->id,
    //                     'amount' => $amountToWithdraw,
    //                     'remaining_balance' => $user->commission_balance
    //                 ]
    //             ]);
    //         });

    //     } catch (\Exception $e) {
    //         // Tangkap error jika saldo kurang atau database gagal
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => $e->getMessage()
    //         ], 400);
    //     }
    // }

    public function withdraw(Request $request)
    {
        $request->validate([
            'bank_name' => 'required|string|max:100',
            'account_number' => 'required|string|max:50',
            'account_name' => 'required|string|max:100',
            'amount' => 'required|numeric|min:10000',
        ]);

        $userId = $request->user()->id;
        $amountToWithdraw = $request->amount;
        $bankName = strtolower(trim($request->bank_name));

        // // 1. Logika Deteksi Potongan Bank
        // $adminFee = 0;
        // // Jika teks bank_name TIDAK mengandung kata "mandiri", kenakan potongan
        // if (!str_contains($bankName, 'mandiri')) {
        //     $adminFee = 6500; // Asumsi biaya transfer antarbank standar
        // }

        // 1. Logika Deteksi Potongan Bank (BI-FAST)
        $adminFee = 0;
        // Jika teks bank_name TIDAK mengandung kata "mandiri", kenakan potongan BI-FAST
        if (!str_contains($bankName, 'mandiri')) {
            $adminFee = 2500; 
        }

        $netReceived = $amountToWithdraw - $adminFee;

        try {
            return DB::transaction(function () use ($userId, $request, $amountToWithdraw, $adminFee, $netReceived) {
                
                $user = User::where('id', $userId)->lockForUpdate()->first();

                if ($user->commission_balance < $amountToWithdraw) {
                    throw new \Exception('Saldo aktif Anda tidak mencukupi.');
                }

                if ($netReceived <= 0) {
                    throw new \Exception('Nominal penarikan terlalu kecil untuk menutupi biaya admin bank lintas bank.');
                }

                // 2. Simpan instruksi transfer bersih untuk Admin di kolom admin_notes
                $transferInstruction = "Biaya Admin: Rp" . number_format($adminFee, 0, ',', '.') . 
                                       " | TRANSFER BERSIH KE AFILIATOR: Rp" . number_format($netReceived, 0, ',', '.');

                $withdrawal = Withdrawal::create([
                    'affiliate_id' => $user->id,
                    'amount' => $amountToWithdraw, // Saldo utuh yang dipotong dari dompet
                    'bank_name' => $request->bank_name,
                    'account_number' => $request->account_number,
                    'account_name' => $request->account_name,
                    'status' => 'pending',
                    'admin_notes' => $transferInstruction, // 👈 Ibu Melisa tinggal membaca ini nanti
                ]);

                $user->decrement('commission_balance', $amountToWithdraw);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Penarikan diajukan. Biaya admin Rp ' . number_format($adminFee, 0, ',', '.') . ' telah disesuaikan.',
                    'data' => [
                        'withdrawal_id' => $withdrawal->id,
                        'amount_deducted' => $amountToWithdraw,
                        'net_received' => $netReceived
                    ]
                ]);
            });

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    // Menarik semua data untuk Dashboard Admin
    public function index()
    {
        // 1. Hitung Statistik Atas
        $stats = [
            'totalAffiliates' => User::where('is_affiliate', true)->count(),
            'pendingRequests' => Withdrawal::where('status', 'pending')->count(),
            'totalTransferred' => Withdrawal::where('status', 'approved')->sum('amount'), // Dari migration pakai 'approved'
        ];

        // 2. Tarik Daftar Afiliator
        $affiliates = User::where('is_affiliate', true)->get()->map(function ($user) {
            // Hitung total historis yang sudah cair
            $totalEarned = Transaction::where('affiliate_id', $user->id)
                ->where('commission_status', 'settled')
                ->sum('commission_earned');

            return [
                'id' => $user->id,
                'name' => trim($user->first_name . ' ' . $user->last_name),
                'email' => $user->email,
                'referral_code' => $user->referral_code,
                'active_balance' => $user->commission_balance,
                'total_earned' => $totalEarned,
            ];
        });

        // 3. Tarik Riwayat Pencairan Dana (Withdrawals)
        $withdrawals = Withdrawal::with('affiliate')->orderBy('created_at', 'desc')->get()->map(function ($w) {
            return [
                'id' => $w->id,
                'date' => $w->created_at->format('d M Y H:i'),
                'affiliate_name' => trim($w->affiliate->first_name . ' ' . $w->affiliate->last_name),
                'bank_name' => $w->bank_name,
                'account_number' => $w->account_number,
                'account_name' => $w->account_name,
                'amount' => $w->amount,
                'status' => $w->status,
                'admin_notes' => $w->admin_notes,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'stats' => $stats,
                'affiliates' => $affiliates,
                'withdrawals' => $withdrawals
            ]
        ]);
    }

    // Memproses Persetujuan Pencairan Dana
    public function approve($id)
    {
        $withdrawal = Withdrawal::findOrFail($id);

        if ($withdrawal->status !== 'pending') {
            return response()->json(['message' => 'Permintaan ini sudah diproses sebelumnya.'], 400);
        }

        // Ubah status jadi approved dan catat waktunya
        $withdrawal->update([
            'status' => 'approved',
            'processed_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Pencairan dana berhasil ditandai selesai.'
        ]);
    }

    public function approveApplication($id)
    {
        $application = AffiliateApplication::findOrFail($id);

        if ($application->status !== 'pending') {
            return response()->json(['message' => 'Pendaftaran ini sudah diproses.'], 400);
        }

        try {
            DB::transaction(function () use ($application) {
                // 1. Ubah status aplikasi menjadi disetujui
                $application->update(['status' => 'approved']);

                $user = $application->user;

                // 2. Buat Kode Referal Otomatis (Contoh: Budi -> BUDI-8A2F)
                $prefix = strtoupper(substr($user->first_name, 0, 4));
                $randomString = strtoupper(Str::random(4));
                $referralCode = $prefix . '-' . $randomString;

                // 3. Sulap user biasa menjadi afiliator tanpa query manual!
                $user->update([
                    'is_affiliate' => true,
                    'referral_code' => $referralCode
                ]);
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Pendaftaran disetujui! Akun pengguna kini menjadi afiliator aktif.'
            ]);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal memproses persetujuan.'], 500);
        }
    }

    public function apply(Request $request)
    {
        $user = $request->user();

        // Tolak jika sudah jadi afiliator
        if ($user->is_affiliate) {
            return response()->json(['message' => 'Anda sudah menjadi afiliator.'], 400);
        }

        // Cek apakah sudah pernah mendaftar dan masih pending
        $existingApp = AffiliateApplication::where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if ($existingApp) {
            return response()->json(['message' => 'Anda sudah mengajukan pendaftaran yang saat ini sedang menunggu tinjauan admin.'], 400);
        }

        $request->validate([
            'social_media_url' => 'required|url|max:255',
            'reason' => 'required|string|max:1000',
        ]);

        AffiliateApplication::create([
            'user_id' => $user->id,
            'social_media_url' => $request->social_media_url,
            'reason' => $request->reason,
            'status' => 'pending'
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Pendaftaran berhasil dikirim.'
        ]);
    }
}
