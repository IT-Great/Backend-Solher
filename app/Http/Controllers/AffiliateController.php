<?php

namespace App\Http\Controllers;

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
    public function withdraw(Request $request)
    {
        $request->validate([
            'bank_name' => 'required|string|max:100',
            'account_number' => 'required|string|max:50',
            'account_name' => 'required|string|max:100',
            'amount' => 'required|numeric|min:10000', // Minimal tarik Rp10.000
        ]);

        $userId = $request->user()->id;
        $amountToWithdraw = $request->amount;

        try {
            // Gunakan DB Transaction agar jika gagal di tengah jalan, uang akan dikembalikan (Rollback)
            return DB::transaction(function () use ($userId, $request, $amountToWithdraw) {

                // KUNCI BARIS USER INI (lockForUpdate) untuk mencegah klik ganda secara brutal (Race Condition)
                $user = User::where('id', $userId)->lockForUpdate()->first();

                // Validasi final: Pastikan saldo mencukupi
                if ($user->commission_balance < $amountToWithdraw) {
                    throw new \Exception('Saldo aktif Anda tidak mencukupi untuk penarikan ini.');
                }

                // 1. Buat catatan penarikan dengan status pending
                $withdrawal = Withdrawal::create([
                    'affiliate_id' => $user->id,
                    'amount' => $amountToWithdraw,
                    'bank_name' => $request->bank_name,
                    'account_number' => $request->account_number,
                    'account_name' => $request->account_name,
                    'status' => 'pending',
                ]);

                // 2. Potong saldo komisi user
                $user->decrement('commission_balance', $amountToWithdraw);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Permintaan penarikan dana berhasil diajukan.',
                    'data' => [
                        'withdrawal_id' => $withdrawal->id,
                        'amount' => $amountToWithdraw,
                        'remaining_balance' => $user->commission_balance
                    ]
                ]);
            });

        } catch (\Exception $e) {
            // Tangkap error jika saldo kurang atau database gagal
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
