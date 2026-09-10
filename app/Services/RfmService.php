<?php

namespace App\Services;

use App\Models\User;
use App\Services\FcmService;
use Illuminate\Support\Facades\DB;

class RfmService
{
    public function getCustomerSegments()
    {
        // 1. Ekstrak Data RFM menggunakan SQL Agregat (Sangat Cepat)
        $rfmData = DB::select("
            SELECT
                user_id,
                DATEDIFF(NOW(), MAX(created_at)) as recency_days,
                COUNT(id) as frequency,
                SUM(total_amount) as monetary
            FROM transactions
            WHERE status = 'completed'
            GROUP BY user_id
        ");

        $segments = [
            'Champions' => ['description' => 'Belanja baru-baru ini, sering, dan nominal besar.', 'users' => [], 'color' => 'bg-green-100 text-green-700', 'icon' => '🏆'],
            'Loyal Customers' => ['description' => 'Sering belanja dan nominal lumayan, tapi butuh disapa.', 'users' => [], 'color' => 'bg-blue-100 text-blue-700', 'icon' => '💎'],
            'At Risk (Sleeping VIPs)' => ['description' => 'Sultan di masa lalu, tapi sudah lama tidak kembali.', 'users' => [], 'color' => 'bg-orange-100 text-orange-700', 'icon' => '⚠️'],
            'Hibernating' => ['description' => 'Belanja sedikit dan sudah sangat lama menghilang.', 'users' => [], 'color' => 'bg-gray-100 text-gray-700', 'icon' => '💤'],
            'Recent Users' => ['description' => 'Baru belanja pertama kali, potensi untuk di-follow up.', 'users' => [], 'color' => 'bg-purple-100 text-purple-700', 'icon' => '✨'],
        ];

        // 2. Algoritma Scoring & Klasifikasi (Rules Base)
        foreach ($rfmData as $row) {
            $r = $row->recency_days;
            $f = $row->frequency;
            $m = $row->monetary;

            if ($r <= 30 && $f >= 3 && $m >= 1000000) {
                $segments['Champions']['users'][] = $row->user_id;
            } elseif ($r <= 90 && $f >= 2 && $m >= 500000) {
                $segments['Loyal Customers']['users'][] = $row->user_id;
            } elseif ($r > 90 && $f >= 3 && $m >= 1000000) {
                $segments['At Risk (Sleeping VIPs)']['users'][] = $row->user_id;
            } elseif ($r > 180 && $f <= 2) {
                $segments['Hibernating']['users'][] = $row->user_id;
            } elseif ($r <= 30 && $f == 1) {
                $segments['Recent Users']['users'][] = $row->user_id;
            }
        }

        return $segments;
    }

    public function blastPushNotification($segmentName, $title, $body)
    {
        $segments = $this->getCustomerSegments();

        if (!isset($segments[$segmentName])) {
            throw new \Exception("Segment tidak ditemukan.");
        }

        $userIds = $segments[$segmentName]['users'];
        if (empty($userIds)) return 0;

        // Ambil token secara berkelompok (Chunk) agar RAM server tidak meledak
        $sentCount = 0;
        $fcmService = app(FcmService::class);

        User::whereIn('id', $userIds)
            ->whereNotNull('fcm_token')
            ->chunk(100, function ($users) use ($fcmService, $title, $body, &$sentCount) {
                foreach ($users as $user) {
                    try {
                        $fcmService->sendPushNotification($user->fcm_token, $title, $body);
                        $sentCount++;
                    } catch (\Exception $e) {}
                }
            });

        return $sentCount;
    }
}
