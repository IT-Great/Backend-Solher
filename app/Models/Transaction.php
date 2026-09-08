<?php

namespace App\Models;

// 👇 Import DB dan FcmService
use App\Services\FcmService;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'order_id',
        'total_amount',
        'status',
        'address_id',
        'shipping_method',
        'shipping_cost',
        'payment_method',
        'courier_company',
        'courier_type',
        'delivery_type',
        'delivery_date',
        'delivery_time',
        'tracking_number',
        'shipping_status',
        'biteship_order_id',
        'point',
        'points_used',
        'promo_code',
        'promo_discount',
        'refund_reason',
        'refund_proof_url',
        'currency_code',
        'affiliate_id',
        'commission_earned',
        'commission_status',
    ];

    // 👇 FUNGSI STATE TRANSITION EKSPLISIT (PURE RAW SQL EXECUTION) 👇
    // public function markAsCompleted(array $additionalUpdates = [])
    // {
    //     // 1. Cegah eksekusi ganda
    //     if ($this->status === 'completed') {
    //         return;
    //     }

    //     // 2. Simpan pembaruan status transaksi
    //     $this->status = 'completed';
    //     foreach ($additionalUpdates as $key => $val) {
    //         $this->{$key} = $val;
    //     }
    //     $this->save();

    //     if (!$this->user_id) {
    //         return;
    //     }

    //     // 3. Kalkulasi Poin Dinamis (Self-Healing jika poin di DB 0)
    //     $earnedPoints = (int) $this->point;
    //     if ($earnedPoints <= 0) {
    //         $earnedPoints = (int) floor($this->total_amount / 100000);
    //         DB::statement('UPDATE transactions SET point = ? WHERE id = ?', [$earnedPoints, $this->id]);
    //         $this->point = $earnedPoints;
    //     }

    //     // 4. Validasi Total Belanja Langsung via SQL
    //     $totalSpent = DB::table('transactions')
    //         ->where('user_id', $this->user_id)
    //         ->where('status', 'completed')
    //         ->sum('total_amount');

    //     // 5. Eksekusi Penambahan Poin & Membership dengan SQL Brutal (Anti-Gagal)
    //     if ($totalSpent >= 100000) {
    //         // Paksa set status membership menjadi 1 (true)
    //         DB::statement('UPDATE users SET is_membership = 1 WHERE id = ?', [$this->user_id]);

    //         if ($earnedPoints > 0) {
    //             // Operasi matematika atomik: Ambil poin, ubah NULL jadi 0, lalu tambah poin baru
    //             DB::statement('UPDATE users SET point = COALESCE(point, 0) + ? WHERE id = ?', [$earnedPoints, $this->user_id]);
    //         }
    //     }

    //     // 6. Kirim Notifikasi FCM
    //     $userFcm = DB::table('users')->where('id', $this->user_id)->value('fcm_token');
    //     if (!empty($userFcm)) {
    //         try {
    //             app(FcmService::class)->sendPushNotification(
    //                 $userFcm,
    //                 "Pesanan Selesai 🎉",
    //                 "Terima kasih telah berbelanja! Anda mendapatkan +{$earnedPoints} Poin Loyalitas."
    //             );
    //         } catch (\Exception $e) {}
    //     }

    //     // 7. Distribusi Komisi Afiliasi via Raw SQL
    //     if ($this->affiliate_id && $this->commission_status === 'pending') {
    //         DB::statement("UPDATE transactions SET commission_status = 'settled' WHERE id = ?", [$this->id]);
    //         DB::statement("UPDATE users SET commission_balance = COALESCE(commission_balance, 0) + ? WHERE id = ?", [$this->commission_earned, $this->affiliate_id]);
    //     }
    // }

    public function markAsCompleted(array $additionalUpdates = [])
    {
        if ($this->status === 'completed') return;

        // 1. Simpan status (Bypass Fillable)
        foreach ($additionalUpdates as $key => $value) {
            $this->{$key} = $value;
        }
        $this->status = 'completed';
        $this->save();

        $user = User::find($this->user_id);
        if ($user) {
            $isMember = $user->is_membership == 1 || $user->is_membership == true;

            // 2. Cek & Paksa Membership
            if (!$isMember) {
                $totalSpent = self::where('user_id', $user->id)->where('status', 'completed')->sum('total_amount');
                if ($totalSpent >= 100000) {
                    $user->is_membership = true;
                    $user->save();
                    $isMember = true;
                }
            }

            // 3. Hitung & Simpan Poin
            $earnedPoints = (int) $this->point;
            if ($earnedPoints <= 0) {
                $earnedPoints = (int) floor($this->total_amount / 100000);
                $this->point = $earnedPoints;
                $this->save();
            }

            // 4. Tambah Poin ke User (Anti-NULL Bug)
            if ($earnedPoints > 0 && $isMember) {
                $user->point = (int) $user->point + $earnedPoints;
                $user->save();
            }

            if (!empty($user->fcm_token)) {
                try {
                    app(\App\Services\FcmService::class)->sendPushNotification(
                        $user->fcm_token, "Pesanan Selesai 🎉", "Anda mendapat +{$earnedPoints} Poin!"
                    );
                } catch (\Exception $e) {}
            }
        }

        // 5. Komisi Afiliasi
        if ($this->affiliate_id && $this->commission_status === 'pending') {
            $this->commission_status = 'settled';
            $this->save();
            $affiliate = User::find($this->affiliate_id);
            if ($affiliate) {
                $affiliate->commission_balance = (float) $affiliate->commission_balance + (float) $this->commission_earned;
                $affiliate->save();
            }
        }
    }

    /**
     * Relasi ke User
     * Transaction belongsTo User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relasi ke TransactionDetail
     * Transaction hasMany TransactionDetail
     */
    public function details()
    {
        return $this->hasMany(TransactionDetail::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }

    public function address()
    {
        return $this->belongsTo(Address::class);
    }
}

