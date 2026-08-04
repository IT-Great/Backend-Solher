<?php

// namespace App\Jobs;

// use App\Models\Subscriber;
// use Illuminate\Bus\Queueable;
// use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Foundation\Bus\Dispatchable;
// use Illuminate\Queue\InteractsWithQueue;
// use Illuminate\Queue\SerializesModels;
// use Illuminate\Support\Facades\Mail;
// use App\Mail\BroadcastNewsletterMail;
// use Illuminate\Support\Facades\Log;

// class SendNewsletterJob implements ShouldQueue
// {
//     use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

//     public $subject;
//     public $content;

//     public function __construct($subject, $content)
//     {
//         $this->subject = $subject;
//         $this->content = $content;
//     }

//     public function handle()
//     {
//         // Ambil SEMUA subscriber yang statusnya AKTIF
//         $subscribers = Subscriber::where('is_active', true)->get();

//         foreach ($subscribers as $sub) {
//             try {
//                 // Kirim email satu per satu di background
//                 Mail::to($sub->email)->send(new BroadcastNewsletterMail($this->subject, $this->content, $sub->email));
//             } catch (\Exception $e) {
//                 Log::error("Gagal mengirim blast ke {$sub->email}: " . $e->getMessage());
//             }
//         }
//     }
// }

// namespace App\Jobs;

// use App\Models\Subscriber;
// use Illuminate\Bus\Queueable;
// use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Foundation\Bus\Dispatchable;
// use Illuminate\Queue\InteractsWithQueue;
// use Illuminate\Queue\SerializesModels;
// use Illuminate\Support\Facades\Mail;
// use App\Mail\BroadcastNewsletterMail;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Crypt;

// class SendNewsletterJob implements ShouldQueue
// {
//     use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

//     public $subject;
//     public $content;

//     public function __construct($subject, $content)
//     {
//         $this->subject = $subject;
//         $this->content = $content;
//     }

//     public function handle()
//     {
//         // Hanya ambil yang is_active = true
//         $subscribers = Subscriber::where('is_active', true)->get();

//         foreach ($subscribers as $sub) {
//             try {
//                 // 👇 [BARU] Buat Token Enkripsi & URL Unsubscribe khusus untuk user ini
//                 $token = Crypt::encryptString($sub->email);
//                 $unsubscribeUrl = url("/api/newsletters/unsubscribe/{$token}");

//                 // Sisipkan variabel baru ke Mailable
//                 Mail::to($sub->email)->send(new BroadcastNewsletterMail(
//                     $this->subject,
//                     $this->content,
//                     $sub->email,
//                     $unsubscribeUrl
//                 ));
//             } catch (\Exception $e) {
//                 Log::error("Gagal mengirim blast ke {$sub->email}: " . $e->getMessage());
//             }
//         }
//     }
// }

// namespace App\Jobs;

// use App\Models\Subscriber;
// use App\Models\CampaignLog;
// use Illuminate\Bus\Queueable;
// use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Foundation\Bus\Dispatchable;
// use Illuminate\Queue\InteractsWithQueue;
// use Illuminate\Queue\SerializesModels;
// use Illuminate\Support\Facades\Mail;
// use App\Mail\BroadcastNewsletterMail;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Crypt;

// class SendNewsletterJob implements ShouldQueue
// {
//     use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

//     public $campaign;
//     public $content;

//     public function __construct($campaign, $content)
//     {
//         $this->campaign = $campaign;
//         $this->content = $content;
//     }

//     public function handle()
//     {
//         $subscribers = Subscriber::where('is_active', true)->get();
        
//         // Catat total target pengiriman
//         $this->campaign->update(['sent_count' => $subscribers->count()]);

//         foreach ($subscribers as $sub) {
//             try {
//                 // 1. Buat Log unik untuk setiap email yang akan dikirim
//                 $log = CampaignLog::create([
//                     'campaign_id' => $this->campaign->id,
//                     'subscriber_email' => $sub->email,
//                 ]);

//                 // 2. Buat URL Tracking Pixel berdasarkan ID Log
//                 $trackingUrl = url("/api/newsletters/track/{$log->id}");
                
//                 // 3. Unsubscribe Token (Yang tadi kita kerjakan)
//                 $token = Crypt::encryptString($sub->email);
//                 $unsubscribeUrl = url("/api/newsletters/unsubscribe/{$token}");

//                 Mail::to($sub->email)->send(new BroadcastNewsletterMail(
//                     $this->campaign->subject, 
//                     $this->content, 
//                     $sub->email, 
//                     $unsubscribeUrl,
//                     $trackingUrl // 👇 Kirim variabel baru
//                 ));
//             } catch (\Exception $e) {
//                 Log::error("Gagal mengirim blast ke {$sub->email}: " . $e->getMessage());
//             }
//         }
//     }
// }

namespace App\Jobs;

use App\Models\Subscriber;
use App\Models\CampaignLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use App\Mail\BroadcastNewsletterMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;

class SendNewsletterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $campaign;
    public $content;
    public $targetAudience; // 👇 [BARU] Properti untuk menyimpan target

    public function __construct($campaign, $content, $targetAudience) // 👇 [BARU] Tangkap di konstruktor
    {
        $this->campaign = $campaign;
        $this->content = $content;
        $this->targetAudience = $targetAudience;
    }

    public function handle()
    {
        // 👇 [MAGIC HAPPENS HERE] Filter dinamis berdasarkan target
        $query = Subscriber::where('is_active', true);

        if ($this->targetAudience === 'registered') {
            $query->where('is_registered', true);
        } elseif ($this->targetAudience === 'guest') {
            $query->where('is_registered', false);
        }

        // Eksekusi pencarian
        $subscribers = $query->get();
        
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

                Mail::to($sub->email)->send(new BroadcastNewsletterMail(
                    $this->campaign->subject, 
                    $this->content, 
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