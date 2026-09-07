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

    // 👇 TAMBAHKAN BLOK EVENT MODEL INI 👇
    // protected static function booted()
    // {
    //     static::updated(function (Transaction $transaction) {
    //         // Deteksi jika status pesanan BARU SAJA berubah menjadi 'completed'
    //         if ($transaction->isDirty('status') && $transaction->status === 'completed') {

    //             $user = $transaction->user;

    //             if ($user) {
    //                 // 1. Cek & Assign Membership Otomatis
    //                 if (!$user->is_membership) {
    //                     $totalSpent = Transaction::where('user_id', $user->id)
    //                         ->where('status', 'completed')
    //                         ->sum('total_amount');

    //                     if ($totalSpent >= 100000) {
    //                         $user->update(['is_membership' => true]);
    //                     }
    //                 }

    //                 // 2. Distribusi Poin Loyalitas
    //                 $user->refresh();
    //                 if ($transaction->point > 0 && $user->is_membership) {
    //                     $user->increment('point', $transaction->point);
    //                 }

    //                 // 3. Kirim Notifikasi FCM
    //                 if ($user->fcm_token) {
    //                     app(FcmService::class)->sendPushNotification(
    //                         $user->fcm_token,
    //                         "Pesanan Selesai 🎉",
    //                         "Terima kasih telah berbelanja! Anda mendapatkan +{$transaction->point} Poin Loyalitas."
    //                     );
    //                 }
    //             }

    //             // 4. Distribusi Komisi Afiliasi
    //             if ($transaction->affiliate_id && $transaction->commission_status === 'pending') {
    //                 // Gunakan DB Builder untuk mencegah infinite loop pada Model Observer
    //                 DB::table('transactions')
    //                     ->where('id', $transaction->id)
    //                     ->update(['commission_status' => 'settled']);

    //                 $affiliate = User::find($transaction->affiliate_id);
    //                 if ($affiliate) {
    //                     $affiliate->increment('commission_balance', $transaction->commission_earned);
    //                 }
    //             }
    //         }
    //     });
    // }

    // 👇 TAMBAHKAN BLOK EVENT MODEL INI 👇
// 👇 FUNGSI STATE TRANSITION EKSPLISIT 👇
    // public function markAsCompleted(array $additionalUpdates = [])
    // {
    //     // Cegah eksekusi ganda jika status sudah completed
    //     if ($this->status === 'completed') {
    //         return;
    //     }

    //     // Simpan pembaruan status dan data tambahan
    //     $updates = array_merge(['status' => 'completed'], $additionalUpdates);
    //     $this->update($updates);

    //     $user = $this->user;

    //     if ($user) {
    //         // 1. Cek & Assign Membership Otomatis
    //         if (!$user->is_membership) {
    //             $totalSpent = self::where('user_id', $user->id)
    //                 ->where('status', 'completed')
    //                 ->sum('total_amount');

    //             if ($totalSpent >= 100000) {
    //                 $user->update(['is_membership' => true]);
    //             }
    //         }

    //         // 2. Distribusi Poin Loyalitas (ANTI-NULL BUG)
    //         $user->refresh();
    //         if ($this->point > 0 && $user->is_membership) {
    //             // Konversi paksa ke (int) akan mengubah NULL menjadi 0
    //             // Sehingga 0 + 15 = 15 (Berhasil disimpan)
    //             $user->point = (int) $user->point + (int) $this->point;
    //             $user->save();
    //         }

    //         // 3. Kirim Notifikasi FCM
    //         if ($user->fcm_token) {
    //             try {
    //                 app(FcmService::class)->sendPushNotification(
    //                     $user->fcm_token,
    //                     "Pesanan Selesai 🎉",
    //                     "Terima kasih telah berbelanja! Anda mendapatkan +{$this->point} Poin Loyalitas."
    //                 );
    //             } catch (\Exception $e) {}
    //         }
    //     }

    //     // 4. Distribusi Komisi Afiliasi
    //     if ($this->affiliate_id && $this->commission_status === 'pending') {
    //         DB::table('transactions')
    //             ->where('id', $this->id)
    //             ->update(['commission_status' => 'settled']);

    //         $affiliate = User::find($this->affiliate_id);
    //         if ($affiliate) {
    //             // Anti-NULL Bug untuk Afiliasi
    //             $affiliate->commission_balance = (float) $affiliate->commission_balance + (float) $this->commission_earned;
    //             $affiliate->save();
    //         }
    //     }
    // }

    // 👇 FUNGSI STATE TRANSITION EKSPLISIT (BULLETPROOF DB QUERY) 👇
    // public function markAsCompleted(array $additionalUpdates = [])
    // {
    //     // Cegah eksekusi ganda jika status sudah completed
    //     if ($this->status === 'completed') {
    //         return;
    //     }

    //     // Simpan pembaruan status transaksi saat ini
    //     $updates = array_merge(['status' => 'completed'], $additionalUpdates);
    //     $this->update($updates);

    //     $userId = $this->user_id;

    //     if ($userId) {
    //         // 1. Ambil data mentah langsung dari MySQL (Bypass Eloquent Memory Cache)
    //         $userRaw = DB::table('users')->where('id', $userId)->first();

    //         if ($userRaw) {
    //             // Konversi ketat ke tipe Boolean/Integer agar if-statement tidak meleset
    //             $isMembership = (bool) $userRaw->is_membership;

    //             // 2. Cek & Assign Membership Otomatis
    //             if (!$isMembership) {
    //                 $totalSpent = DB::table('transactions')
    //                     ->where('user_id', $userId)
    //                     ->where('status', 'completed')
    //                     ->sum('total_amount');

    //                 if ($totalSpent >= 100000) {
    //                     DB::table('users')->where('id', $userId)->update(['is_membership' => true]);
    //                     $isMembership = true;
    //                 }
    //             }

    //             // 3. Distribusi Poin Loyalitas (ANTI-NULL & ANTI-CACHE BUG)
    //             if ($this->point > 0 && $isMembership) {
    //                 // Raw query ini memaksa MySQL menjumlahkan angka secara atomik
    //                 DB::table('users')->where('id', $userId)->update([
    //                     'point' => DB::raw("COALESCE(point, 0) + " . (int)$this->point)
    //                 ]);
    //             }

    //             // 4. Kirim Notifikasi FCM
    //             if (!empty($userRaw->fcm_token)) {
    //                 try {
    //                     app(FcmService::class)->sendPushNotification(
    //                         $userRaw->fcm_token,
    //                         "Pesanan Selesai 🎉",
    //                         "Terima kasih telah berbelanja! Anda mendapatkan +{$this->point} Poin Loyalitas."
    //                     );
    //                 } catch (\Exception $e) {}
    //             }
    //         }
    //     }

    //     // 5. Distribusi Komisi Afiliasi (Jika Ada)
    //     if ($this->affiliate_id && $this->commission_status === 'pending') {
    //         DB::table('transactions')
    //             ->where('id', $this->id)
    //             ->update(['commission_status' => 'settled']);

    //         DB::table('users')
    //             ->where('id', $this->affiliate_id)
    //             ->update([
    //                 'commission_balance' => DB::raw("COALESCE(commission_balance, 0) + " . (float)$this->commission_earned)
    //             ]);
    //     }
    // }

    public function markAsCompleted(array $additionalUpdates = [])
    {
        // Cegah eksekusi ganda jika status sudah completed
        if ($this->status === 'completed') {
            return;
        }

        // Simpan pembaruan status transaksi saat ini
        $updates = array_merge(['status' => 'completed'], $additionalUpdates);
        $this->update($updates);

        $userId = $this->user_id;

        if ($userId) {
            // Bypass Eloquent Cache dengan Query Builder mentah
            $userRaw = DB::table('users')->where('id', $userId)->first();

            if ($userRaw) {
                $isMembership = (bool) $userRaw->is_membership;

                // Cek & Assign Membership Otomatis
                if (!$isMembership) {
                    $totalSpent = DB::table('transactions')
                        ->where('user_id', $userId)
                        ->where('status', 'completed')
                        ->sum('total_amount');

                    if ($totalSpent >= 100000) {
                        DB::table('users')->where('id', $userId)->update(['is_membership' => 1]);
                        $isMembership = true;
                    }
                }

                // [SELF-HEALING BUG FIX]
                // Jika point di DB 0 (karena bug lama), hitung ulang on-the-fly dari total_amount
                $earnedPoints = (int) $this->point;
                if ($earnedPoints <= 0) {
                    $earnedPoints = (int) floor($this->total_amount / 100000);
                    // Update juga di DB agar record transaksi menjadi akurat
                    $this->update(['point' => $earnedPoints]);
                }

                // Distribusi Poin Loyalitas (ANTI-NULL & ANTI-DB RAW BUG)
                if ($earnedPoints > 0 && $isMembership) {
                    $currentPoints = (int) $userRaw->point;
                    DB::table('users')->where('id', $userId)->update([
                        'point' => $currentPoints + $earnedPoints
                    ]);
                }

                // Kirim Notifikasi FCM
                if (!empty($userRaw->fcm_token)) {
                    try {
                        app(\App\Services\FcmService::class)->sendPushNotification(
                            $userRaw->fcm_token,
                            "Pesanan Selesai 🎉",
                            "Terima kasih telah berbelanja! Anda mendapatkan +{$earnedPoints} Poin Loyalitas."
                        );
                    } catch (\Exception $e) {}
                }
            }
        }

        // Distribusi Komisi Afiliasi (Aman dari null)
        if ($this->affiliate_id && $this->commission_status === 'pending') {
            DB::table('transactions')
                ->where('id', $this->id)
                ->update(['commission_status' => 'settled']);

            $affiliate = DB::table('users')->where('id', $this->affiliate_id)->first();
            if ($affiliate) {
                $currentComm = (float) $affiliate->commission_balance;
                $earnedComm = (float) $this->commission_earned;

                DB::table('users')->where('id', $this->affiliate_id)->update([
                    'commission_balance' => $currentComm + $earnedComm
                ]);
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
