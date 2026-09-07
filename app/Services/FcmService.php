<?php

namespace App\Services;

use Google_Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class FcmService
{
    private function getAccessToken()
    {
        $client = new Google_Client();
        // Path ke file JSON yang diunduh dari Firebase Console
        $client->setAuthConfig(storage_path('app/firebase-service-account.json'));
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');

        $token = $client->fetchAccessTokenWithAssertion();
        return $token['access_token'];
    }

    public function sendPushNotification($fcmToken, $title, $body, $data = [])
    {
        if (!$fcmToken) return false;

        $accessToken = $this->getAccessToken();
        $projectId = 'solher-app'; // Cek di firebase-service-account.json

        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $data // Opsional: Untuk navigasi di Flutter
            ]
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ])->post($url, $payload);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error("FCM Send Error: " . $e->getMessage());
            return false;
        }
    }
}
