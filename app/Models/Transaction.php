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

    // 👇 FUNGSI STATE TRANSITION EKSPLISIT (PURE RAW SQL EXECUTION)

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

