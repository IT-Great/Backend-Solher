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
    public function markAsCompleted(array $additionalUpdates = [])
    {
        // Cegah eksekusi ganda jika status sudah completed
        if ($this->status === 'completed') {
            return;
        }

        // Simpan pembaruan status dan data tambahan
        $updates = array_merge(['status' => 'completed'], $additionalUpdates);
        $this->update($updates);

        $user = $this->user;

        if ($user) {
            // 1. Cek & Assign Membership Otomatis
            if (!$user->is_membership) {
                $totalSpent = self::where('user_id', $user->id)
                    ->where('status', 'completed')
                    ->sum('total_amount');

                if ($totalSpent >= 100000) {
                    $user->update(['is_membership' => true]);
                }
            }

            // 2. Distribusi Poin Loyalitas (ANTI-NULL BUG)
            $user->refresh();
            if ($this->point > 0 && $user->is_membership) {
                // Konversi paksa ke (int) akan mengubah NULL menjadi 0
                // Sehingga 0 + 15 = 15 (Berhasil disimpan)
                $user->point = (int) $user->point + (int) $this->point;
                $user->save();
            }

            // 3. Kirim Notifikasi FCM
            if ($user->fcm_token) {
                try {
                    app(FcmService::class)->sendPushNotification(
                        $user->fcm_token,
                        "Pesanan Selesai 🎉",
                        "Terima kasih telah berbelanja! Anda mendapatkan +{$this->point} Poin Loyalitas."
                    );
                } catch (\Exception $e) {}
            }
        }

        // 4. Distribusi Komisi Afiliasi
        if ($this->affiliate_id && $this->commission_status === 'pending') {
            DB::table('transactions')
                ->where('id', $this->id)
                ->update(['commission_status' => 'settled']);

            $affiliate = User::find($this->affiliate_id);
            if ($affiliate) {
                // Anti-NULL Bug untuk Afiliasi
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
