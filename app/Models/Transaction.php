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

// 👇 FUNGSI STATE TRANSITION EKSPLISIT (PURE ELOQUENT) 👇
    // public function markAsCompleted(array $additionalUpdates = [])
    // {
    //     // Cegah eksekusi ganda jika status sudah completed
    //     if ($this->status === 'completed') {
    //         return;
    //     }

    //     // Simpan pembaruan status transaksi saat ini (memicu event update internal Laravel)
    //     $this->update(array_merge(['status' => 'completed'], $additionalUpdates));

    //     // Gunakan relasi standar Eloquent agar mutator & casts tetap berjalan
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

    //         // Segarkan data user dari database untuk memastikan status membership valid
    //         $user->refresh();

    //         // 2. Self-Healing Bug Logika Checkout Lama
    //         $earnedPoints = (int) $this->point;
    //         if ($earnedPoints <= 0) {
    //             $earnedPoints = (int) floor($this->total_amount / 100000);
    //             $this->update(['point' => $earnedPoints]);
    //         }

    //         // 3. Distribusi Poin Loyalitas Menggunakan Increment Bawaan Laravel
    //         if ($earnedPoints > 0 && $user->is_membership) {
    //             $user->increment('point', $earnedPoints);
    //         }

    //         // 4. Kirim Notifikasi FCM
    //         if (!empty($user->fcm_token)) {
    //             try {
    //                 app(\App\Services\FcmService::class)->sendPushNotification(
    //                     $user->fcm_token,
    //                     "Pesanan Selesai 🎉",
    //                     "Terima kasih telah berbelanja! Anda mendapatkan +{$earnedPoints} Poin Loyalitas."
    //                 );
    //             } catch (\Exception $e) {}
    //         }
    //     }

    //     // 5. Distribusi Komisi Afiliasi
    //     if ($this->affiliate_id && $this->commission_status === 'pending') {
    //         $this->update(['commission_status' => 'settled']);

    //         $affiliate = User::find($this->affiliate_id);
    //         if ($affiliate) {
    //             $affiliate->increment('commission_balance', $this->commission_earned);
    //         }
    //     }
    // }

    // 👇 FUNGSI STATE TRANSITION EKSPLISIT (PURE ELOQUENT) 👇
    // public function markAsCompleted(array $additionalUpdates = [])
    // {
    //     // Cegah eksekusi ganda jika status sudah completed
    //     if ($this->status === 'completed') {
    //         return;
    //     }

    //     // Simpan pembaruan status transaksi saat ini (memicu event update internal Laravel)
    //     $this->update(array_merge(['status' => 'completed'], $additionalUpdates));

    //     // Gunakan relasi standar Eloquent agar mutator & casts tetap berjalan
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

    //         // Segarkan data user dari database untuk memastikan status membership valid
    //         $user->refresh();

    //         // 2. Self-Healing Bug Logika Checkout Lama
    //         $earnedPoints = (int) $this->point;
    //         if ($earnedPoints <= 0) {
    //             $earnedPoints = (int) floor($this->total_amount / 100000);
    //             $this->update(['point' => $earnedPoints]);
    //         }

    //         // 3. Distribusi Poin Loyalitas Menggunakan Increment Bawaan Laravel
    //         if ($earnedPoints > 0 && $user->is_membership) {
    //             $user->increment('point', $earnedPoints);
    //         }

    //         // 4. Kirim Notifikasi FCM
    //         if (!empty($user->fcm_token)) {
    //             try {
    //                 app(\App\Services\FcmService::class)->sendPushNotification(
    //                     $user->fcm_token,
    //                     "Pesanan Selesai 🎉",
    //                     "Terima kasih telah berbelanja! Anda mendapatkan +{$earnedPoints} Poin Loyalitas."
    //                 );
    //             } catch (\Exception $e) {}
    //         }
    //     }

    //     // 5. Distribusi Komisi Afiliasi
    //     if ($this->affiliate_id && $this->commission_status === 'pending') {
    //         $this->update(['commission_status' => 'settled']);

    //         $affiliate = User::find($this->affiliate_id);
    //         if ($affiliate) {
    //             $affiliate->increment('commission_balance', $this->commission_earned);
    //         }
    //     }
    // }

    // 👇 FUNGSI STATE TRANSITION EKSPLISIT (BRUTE FORCE ELOQUENT) 👇
    // public function markAsCompleted(array $additionalUpdates = [])
    // {
    //     // Cegah eksekusi ganda jika status sudah completed
    //     if ($this->status === 'completed') {
    //         return;
    //     }

    //     // 1. Simpan pembaruan status transaksi dengan Bypass Fillable
    //     foreach ($additionalUpdates as $key => $value) {
    //         $this->{$key} = $value;
    //     }
    //     $this->status = 'completed';
    //     $this->save();

    //     // 2. Tarik ulang user secara paksa (Mencegah Caching Relasi Pekerja Antrean)
    //     $user = User::find($this->user_id);

    //     if ($user) {
    //         // Evaluasi longgar agar 1, '1', atau true lolos semua
    //         $isMember = $user->is_membership == 1 || $user->is_membership == true;

    //         // 3. Cek & Paksa Membership (Bypass Fillable)
    //         if (!$isMember) {
    //             $totalSpent = self::where('user_id', $user->id)
    //                 ->where('status', 'completed')
    //                 ->sum('total_amount');

    //             if ($totalSpent >= 100000) {
    //                 $user->is_membership = true;
    //                 $user->save();
    //                 $isMember = true;
    //             }
    //         }

    //         // 4. Hitung Poin (Bypass Fillable & Null Bug)
    //         $earnedPoints = (int) $this->point;
    //         if ($earnedPoints <= 0) {
    //             $earnedPoints = (int) floor($this->total_amount / 100000);
    //             $this->point = $earnedPoints;
    //             $this->save();
    //         }

    //         // 5. Eksekusi Poin Manual di PHP (Anti-NULL & Anti-Fillable Bug)
    //         if ($earnedPoints > 0 && $isMember) {
    //             $currentPoint = (int) $user->point;
    //             $user->point = $currentPoint + $earnedPoints;
    //             $user->save();
    //         }

    //         // 6. Notifikasi FCM
    //         if (!empty($user->fcm_token)) {
    //             try {
    //                 app(\App\Services\FcmService::class)->sendPushNotification(
    //                     $user->fcm_token,
    //                     "Pesanan Selesai 🎉",
    //                     "Terima kasih telah berbelanja! Anda mendapatkan +{$earnedPoints} Poin Loyalitas."
    //                 );
    //             } catch (\Exception $e) {}
    //         }
    //     }

    //     // 7. Komisi Afiliasi (Bypass Fillable)
    //     if ($this->affiliate_id && $this->commission_status === 'pending') {
    //         $this->commission_status = 'settled';
    //         $this->save();

    //         $affiliate = User::find($this->affiliate_id);
    //         if ($affiliate) {
    //             $currentComm = (float) $affiliate->commission_balance;
    //             $affiliate->commission_balance = $currentComm + (float) $this->commission_earned;
    //             $affiliate->save();
    //         }
    //     }
    // }

    // 👇 FUNGSI STATE TRANSITION EKSPLISIT (PURE ELOQUENT) 👇
    // public function markAsCompleted(array $additionalUpdates = [])
    // {
    //     // Cegah eksekusi ganda jika status sudah completed
    //     if ($this->status === 'completed') {
    //         return;
    //     }

    //     // 1. Simpan pembaruan status transaksi saat ini
    //     $this->update(array_merge(['status' => 'completed'], $additionalUpdates));

    //     // 2. Tarik relasi bawaan Eloquent
    //     $user = $this->user;

    //     if ($user) {
    //         // 3. Cek & Assign Membership Otomatis (Persis Algoritma Lama)
    //         if (!$user->is_membership) {
    //             $totalSpent = static::where('user_id', $user->id)
    //                 ->where('status', 'completed')
    //                 ->sum('total_amount');

    //             if ($totalSpent >= 100000) {
    //                 $user->update(['is_membership' => true]);
    //             }
    //         }

    //         // 4. Segarkan data dari database (Mencegah Stale Cache)
    //         $user->refresh();

    //         // 5. Kalkulasi Poin Dinamis (Self-Healing untuk transaksi lawas dengan poin 0)
    //         $pointsToAward = $this->point > 0 ? $this->point : floor($this->total_amount / 100000);

    //         // 6. Distribusi Poin Loyalitas Menggunakan Increment (ANTI-GAGAL)
    //         if ($pointsToAward > 0 && $user->is_membership) {
    //             $user->increment('point', $pointsToAward);
    //         }

    //         // 7. Kirim Notifikasi FCM
    //         if (!empty($user->fcm_token)) {
    //             try {
    //                 app(\App\Services\FcmService::class)->sendPushNotification(
    //                     $user->fcm_token,
    //                     "Pesanan Selesai 🎉",
    //                     "Terima kasih telah berbelanja! Anda mendapatkan +{$pointsToAward} Poin Loyalitas."
    //                 );
    //             } catch (\Exception $e) {}
    //         }
    //     }

    //     // 8. Distribusi Komisi Afiliasi (Menggunakan Increment)
    //     if ($this->affiliate_id && $this->commission_status === 'pending') {
    //         $this->update(['commission_status' => 'settled']);

    //         $affiliate = User::find($this->affiliate_id);
    //         if ($affiliate) {
    //             $affiliate->increment('commission_balance', $this->commission_earned);
    //         }
    //     }
    // }

    // 👇 FUNGSI STATE TRANSITION EKSPLISIT (ANTI-BUG DATABASE BUILDER) 👇
    // public function markAsCompleted(array $additionalUpdates = [])
    // {
    //     // 1. Cegah eksekusi ganda jika status sudah completed
    //     if ($this->status === 'completed') {
    //         return;
    //     }

    //     // 2. Simpan pembaruan status transaksi saat ini
    //     $this->status = 'completed';
    //     foreach ($additionalUpdates as $key => $val) {
    //         $this->{$key} = $val;
    //     }
    //     $this->save();

    //     if ($this->user_id) {
    //         // 3. Tarik data mentah dari MySQL (Bypass Stale Cache & Model Observer)
    //         $userRaw = DB::table('users')->where('id', $this->user_id)->first();

    //         if ($userRaw) {
    //             // Konversi tegas ke angka/boolean
    //             $isMembership = (bool) $userRaw->is_membership;

    //             // 4. Cek & Paksa Assign Membership (Bypass Fillable)
    //             if (!$isMembership) {
    //                 $totalSpent = DB::table('transactions')
    //                     ->where('user_id', $this->user_id)
    //                     ->where('status', 'completed')
    //                     ->sum('total_amount');

    //                 if ($totalSpent >= 100000) {
    //                     DB::table('users')->where('id', $this->user_id)->update(['is_membership' => 1]);
    //                     $isMembership = true; // Set manual untuk scope fungsi ini
    //                 }
    //             }

    //             // 5. Kalkulasi Poin Dinamis (Self-Healing jika poin di DB 0)
    //             $earnedPoints = (int) $this->point;
    //             if ($earnedPoints <= 0) {
    //                 $earnedPoints = (int) floor($this->total_amount / 100000);
    //                 DB::table('transactions')->where('id', $this->id)->update(['point' => $earnedPoints]);
    //             }

    //             // 6. Distribusi Poin Loyalitas (ANTI-NULL & ANTI-FILLABLE BUG)
    //             if ($earnedPoints > 0 && $isMembership) {
    //                 // Kita ambil nilai saat ini, jadikan integer absolut (NULL jadi 0), tambahkan, lalu injek ke DB.
    //                 $currentPoints = (int) $userRaw->point;
    //                 DB::table('users')->where('id', $this->user_id)->update([
    //                     'point' => $currentPoints + $earnedPoints
    //                 ]);
    //             }

    //             // 7. Kirim Notifikasi FCM
    //             if (!empty($userRaw->fcm_token)) {
    //                 try {
    //                     app(\App\Services\FcmService::class)->sendPushNotification(
    //                         $userRaw->fcm_token,
    //                         "Pesanan Selesai 🎉",
    //                         "Terima kasih telah berbelanja! Anda mendapatkan +{$earnedPoints} Poin Loyalitas."
    //                     );
    //                 } catch (\Exception $e) {}
    //             }
    //         }
    //     }

    //     // 8. Distribusi Komisi Afiliasi (Aman dari NULL)
    //     if ($this->affiliate_id && $this->commission_status === 'pending') {
    //         DB::table('transactions')->where('id', $this->id)->update(['commission_status' => 'settled']);

    //         $affiliateRaw = DB::table('users')->where('id', $this->affiliate_id)->first();
    //         if ($affiliateRaw) {
    //             $currentComm = (float) $affiliateRaw->commission_balance;
    //             DB::table('users')->where('id', $this->affiliate_id)->update([
    //                 'commission_balance' => $currentComm + (float) $this->commission_earned
    //             ]);
    //         }
    //     }
    // }

    // 👇 FUNGSI STATE TRANSITION EKSPLISIT (PURE RAW SQL EXECUTION) 👇
    public function markAsCompleted(array $additionalUpdates = [])
    {
        // 1. Cegah eksekusi ganda
        if ($this->status === 'completed') {
            return;
        }

        // 2. Simpan pembaruan status transaksi
        $this->status = 'completed';
        foreach ($additionalUpdates as $key => $val) {
            $this->{$key} = $val;
        }
        $this->save();

        if (!$this->user_id) {
            return;
        }

        // 3. Kalkulasi Poin Dinamis (Self-Healing jika poin di DB 0)
        $earnedPoints = (int) $this->point;
        if ($earnedPoints <= 0) {
            $earnedPoints = (int) floor($this->total_amount / 100000);
            DB::statement('UPDATE transactions SET point = ? WHERE id = ?', [$earnedPoints, $this->id]);
            $this->point = $earnedPoints;
        }

        // 4. Validasi Total Belanja Langsung via SQL
        $totalSpent = DB::table('transactions')
            ->where('user_id', $this->user_id)
            ->where('status', 'completed')
            ->sum('total_amount');

        // 5. Eksekusi Penambahan Poin & Membership dengan SQL Brutal (Anti-Gagal)
        if ($totalSpent >= 100000) {
            // Paksa set status membership menjadi 1 (true)
            DB::statement('UPDATE users SET is_membership = 1 WHERE id = ?', [$this->user_id]);

            if ($earnedPoints > 0) {
                // Operasi matematika atomik: Ambil poin, ubah NULL jadi 0, lalu tambah poin baru
                DB::statement('UPDATE users SET point = COALESCE(point, 0) + ? WHERE id = ?', [$earnedPoints, $this->user_id]);
            }
        }

        // 6. Kirim Notifikasi FCM
        $userFcm = DB::table('users')->where('id', $this->user_id)->value('fcm_token');
        if (!empty($userFcm)) {
            try {
                app(FcmService::class)->sendPushNotification(
                    $userFcm,
                    "Pesanan Selesai 🎉",
                    "Terima kasih telah berbelanja! Anda mendapatkan +{$earnedPoints} Poin Loyalitas."
                );
            } catch (\Exception $e) {}
        }

        // 7. Distribusi Komisi Afiliasi via Raw SQL
        if ($this->affiliate_id && $this->commission_status === 'pending') {
            DB::statement("UPDATE transactions SET commission_status = 'settled' WHERE id = ?", [$this->id]);
            DB::statement("UPDATE users SET commission_balance = COALESCE(commission_balance, 0) + ? WHERE id = ?", [$this->commission_earned, $this->affiliate_id]);
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
