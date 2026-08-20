<?php

namespace App\Traits;

use App\Models\WebhookLog;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

trait IdempotentWebhook
{
    /**
     * Menjalankan handler webhook secara aman dari race condition dan request duplikat.
     *
     * @param string $gateway (xendit, stripe, paypal, biteship)
     * @param string $eventId (ID unik dari payload webhook)
     * @param array $payload
     * @param Closure $handler
     * @return JsonResponse
     */
    protected function handleIdempotentWebhook(string $gateway, string $eventId, array $payload, Closure $handler): JsonResponse
    {
        if (empty($eventId)) {
            return response()->json(['status' => 'error', 'message' => 'Event ID tidak ditemukan pada payload.'], 400);
        }

        // 1. Cek apakah Event ID ini sudah pernah sukses diproses sebelumnya di Database
        $existingLog = WebhookLog::where('gateway', $gateway)
            ->where('event_id', $eventId)
            ->first();

        if ($existingLog && $existingLog->status === 'completed') {
            Log::info("Webhook [{$gateway}] Event ID {$eventId} diabaikan: Sudah pernah diproses (Idempotent Hit).");
            return response()->json([
                'status' => 'success',
                'message' => 'Webhook already processed successfully (Idempotent).',
            ], 200);
        }

        // 2. Pasang Redis Distributed Atomic Lock selama 10 detik
        // Mencegah request paralel dalam rentang milidetik yang sama
        $lockKey = "webhook_lock:{$gateway}:{$eventId}";
        $lock = Cache::lock($lockKey, 10);

        // Coba dapatkan kunci (menunggu maksimal 3 detik jika sedang dipegang proses lain)
        if (! $lock->get()) {
            Log::warning("Webhook [{$gateway}] Event ID {$eventId} terkunci oleh worker lain. Menolak race condition.");
            return response()->json([
                'status' => 'conflict',
                'message' => 'Webhook is currently being processed by another worker.',
            ], 409);
        }

        try {
            // 3. Catat atau perbarui status ke 'processing' di database
            $webhookRecord = WebhookLog::updateOrCreate(
                ['gateway' => $gateway, 'event_id' => $eventId],
                ['status' => 'processing', 'payload' => $payload]
            );

            // 4. Eksekusi logika inti transaksi di dalam Database Transaction
            $result = DB::transaction(function () use ($handler, $payload) {
                return $handler($payload);
            });

            // 5. Tandai status webhook sebagai 'completed'
            $webhookRecord->update([
                'status' => 'completed',
                'response_message' => is_string($result) ? $result : 'Processed successfully',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Webhook processed successfully.',
                'data' => $result,
            ], 200);

        } catch (\Throwable $e) {
            // Tandai failed jika terjadi error
            WebhookLog::updateOrCreate(
                ['gateway' => $gateway, 'event_id' => $eventId],
                ['status' => 'failed', 'response_message' => $e->getMessage()]
            );

            Log::error("Webhook [{$gateway}] Error pada Event ID {$eventId}: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memproses webhook: ' . $e->getMessage(),
            ], 500);

        } finally {
            // Selalu lepaskan kunci Redis setelah proses selesai
            $lock->release();
        }
    }
}