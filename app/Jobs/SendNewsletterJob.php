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

namespace App\Jobs;

use App\Models\Subscriber;
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

    public $subject;
    public $content;

    public function __construct($subject, $content)
    {
        $this->subject = $subject;
        $this->content = $content;
    }

    public function handle()
    {
        // Hanya ambil yang is_active = true
        $subscribers = Subscriber::where('is_active', true)->get();

        foreach ($subscribers as $sub) {
            try {
                // 👇 [BARU] Buat Token Enkripsi & URL Unsubscribe khusus untuk user ini
                $token = Crypt::encryptString($sub->email);
                $unsubscribeUrl = url("/api/newsletters/unsubscribe/{$token}");

                // Sisipkan variabel baru ke Mailable
                Mail::to($sub->email)->send(new BroadcastNewsletterMail(
                    $this->subject,
                    $this->content,
                    $sub->email,
                    $unsubscribeUrl
                ));
            } catch (\Exception $e) {
                Log::error("Gagal mengirim blast ke {$sub->email}: " . $e->getMessage());
            }
        }
    }
}
