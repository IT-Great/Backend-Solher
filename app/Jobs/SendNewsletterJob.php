<?php

namespace App\Jobs;

use App\Models\Subscriber;
use App\Models\CampaignLog;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\BroadcastNewsletterMail;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendNewsletterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $campaign;
    public $content;
    public $targetAudience;
    public $testEmails; // 👇 [BARU]

    // 👇 [UPDATE] Tangkap array $testEmails
    public function __construct($campaign, $content, $targetAudience, $testEmails = [])
    {
        $this->campaign = $campaign;
        $this->content = $content;
        $this->targetAudience = $targetAudience;
        $this->testEmails = $testEmails;
    }

    public function handle()
    {
        // 👇 Gunakan Laravel Collection kosong untuk menampung target
        $subscribers = collect();

        // LOGIKA PENENTUAN TARGET
        if ($this->targetAudience === 'test') {
            // Jika mode uji coba, JANGAN query ke database.
            // Cukup buat object tiruan secara dinamis berdasarkan input user agar email tetap terkirim
            foreach ($this->testEmails as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $subscribers->push((object)['email' => $email]);
                }
            }
        } else {
            // Jika mode normal, lakukan pencarian database seperti biasa
            $query = Subscriber::where('is_active', true);

            if ($this->targetAudience === 'registered') {
                $query->where('is_registered', true);
            } elseif ($this->targetAudience === 'guest') {
                $query->where('is_registered', false);
            }

            $subscribers = $query->get();
        }

        // Catat total target pengiriman yang sudah difilter
        $this->campaign->update(['sent_count' => $subscribers->count()]);

        // Mencegah error jika tidak ada target audiens (0 orang)
        if ($subscribers->count() === 0) return;

        foreach ($subscribers as $sub) {
            try {
                $log = CampaignLog::create([
                    'campaign_id' => $this->campaign->id,
                    'subscriber_email' => $sub->email,
                ]);

                $trackingUrl = url("/api/newsletters/track/{$log->id}");
                $token = Crypt::encryptString($sub->email);
                $unsubscribeUrl = url("/api/newsletters/unsubscribe/{$token}");

                $clickRedirectBaseUrl = url("/api/newsletters/click/{$log->id}");

                $personalizedContent = preg_replace_callback(
                    '/href=["\']([^"\']+)["\']/i',
                    function($matches) use ($clickRedirectBaseUrl) {
                        $originalUrl = $matches[1];

                        if (str_starts_with($originalUrl, 'mailto:') ||
                            str_starts_with($originalUrl, 'tel:') ||
                            str_contains($originalUrl, 'unsubscribe')) {
                            return $matches[0];
                        }

                        $encodedUrl = urlencode($originalUrl);
                        return 'href="' . $clickRedirectBaseUrl . '?url=' . $encodedUrl . '"';
                    },
                    $this->content
                );

                Mail::to($sub->email)->send(new BroadcastNewsletterMail(
                    $this->campaign->subject,
                    $personalizedContent,
                    $sub->email,
                    $unsubscribeUrl,
                    $trackingUrl
                ));
            } catch (\Exception $e) {
                Log::error("Gagal mengirim blast ke {$sub->email}: " . $e->getMessage());
            }
        }
    }
}
