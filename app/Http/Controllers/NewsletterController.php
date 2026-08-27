<?php

namespace App\Http\Controllers;

use App\Models\Subscriber;
use Illuminate\Http\Request;
use App\Jobs\SendNewsletterJob;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use App\Models\Campaign;     // 👇 Import Model Baru
use App\Models\CampaignLog;  // 👇 Import Model Baru

class NewsletterController extends Controller
{
    // public function broadcast(Request $request)
    // {
    //     $request->validate([
    //         'subject' => 'required|string|max:255',
    //         'content' => 'required|string', // Isi email dalam format HTML
    //     ]);

    //     // 1. Buat catatan Campaign baru
    //     $campaign = Campaign::create([
    //         'subject' => $request->subject,
    //     ]);

    //     // 2. Lempar ID Campaign ke antrean (Bukan lagi melempar subject string biasa)
    //     SendNewsletterJob::dispatch($campaign, $request->content);

    //     return response()->json([
    //         'status' => 'success',
    //         'message' => 'Kampanye Newsletter sedang dikirim ke antrean server. Email akan mulai dikirimkan dalam beberapa saat.'
    //     ]);
    // }

    // public function broadcast(Request $request)
    // {
    //     $request->validate([
    //         'subject' => 'required|string|max:255',
    //         'content' => 'required|string',
    //         'target_audience' => 'required|string|in:all,registered,guest', // 👇 Validasi opsi target
    //     ]);

    //     // 1. Buat catatan Campaign baru
    //     $campaign = Campaign::create([
    //         'subject' => $request->subject,
    //     ]);

    //     // 2. Lempar ID Campaign dan Target Audiens ke antrean
    //     SendNewsletterJob::dispatch($campaign, $request->content, $request->target_audience); // 👇 Tambahkan parameter ketiga

    //     return response()->json([
    //         'status' => 'success',
    //         'message' => 'Kampanye Newsletter sedang dikirim ke antrean server. Email akan mulai dikirimkan dalam beberapa saat.'
    //     ]);
    // }

    public function broadcast(Request $request)
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'content' => 'required|string',
            'target_audience' => 'required|string|in:all,registered,guest,test', // Tambahkan 'test'
            'test_emails' => 'nullable|string' // Parameter baru
        ]);

        // 1. Buat catatan Campaign baru
        $campaign = Campaign::create([
            'subject' => $request->subject,
        ]);

        // Ekstrak string email yang dipisahkan koma menjadi Array
        $testEmailsArray = [];
        if ($request->target_audience === 'test' && $request->test_emails) {
            $testEmailsArray = array_map('trim', explode(',', $request->test_emails));
        }

        // 2. Lempar ID Campaign, Target Audiens, dan Data Email Test ke antrean
        SendNewsletterJob::dispatch(
            $campaign,
            $request->content,
            $request->target_audience,
            $testEmailsArray // Parameter array baru untuk Worker
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Kampanye Newsletter sedang dikirim ke antrean server. Email akan mulai dikirimkan dalam beberapa saat.'
        ]);
    }

    // 👇 [TAMBAHAN BARU] Endpoint untuk menerima gambar dari Drag & Drop Unlayer 👇
    public function uploadImage(Request $request)
    {
        $request->validate([
            'file' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // Maks 5MB
        ]);

        if ($request->hasFile('file')) {
            // Simpan gambar ke folder storage/app/public/newsletter_images
            $path = $request->file('file')->store('newsletter_images', 'public');

            // Hasilkan URL lengkap (Misal: https://api.gycora.com/storage/newsletter_images/foto.jpg)
            $url = asset('storage/' . $path);

            return response()->json(['url' => $url]);
        }

        return response()->json(['message' => 'Tidak ada file yang diterima'], 400);
    }

    public function unsubscribe($token)
    {
        try {
            // Dekripsi token untuk mendapatkan email asli
            $email = Crypt::decryptString($token);

            // Cari subscriber dan nonaktifkan
            $subscriber = Subscriber::where('email', $email)->first();

            if ($subscriber) {
                $subscriber->update(['is_active' => false]);
            }

            // Kembalikan HTML sederhana agar Anda tidak perlu membuat halaman Vue baru
            return response("
                <div style='font-family: sans-serif; text-align: center; margin-top: 50px;'>
                    <h2 style='color: #333;'>Berhasil Berhenti Berlangganan</h2>
                    <p style='color: #666;'>Anda tidak akan menerima email promosi lagi dari Solher.</p>
                </div>
            ", 200)->header('Content-Type', 'text/html');

        } catch (\Exception $e) {
            // Jika token dimanipulasi atau kedaluwarsa
            return response("
                <div style='font-family: sans-serif; text-align: center; margin-top: 50px;'>
                    <h2 style='color: #d33;'>Tautan Tidak Valid</h2>
                    <p style='color: #666;'>Tautan ini sudah rusak atau tidak dapat digunakan.</p>
                </div>
            ", 400)->header('Content-Type', 'text/html');
        }
    }

    public function trackOpen($logId)
    {
        $log = CampaignLog::find($logId);

        // Jika log ditemukan dan belum pernah dibuka sebelumnya
        if ($log && !$log->is_opened) {
            // Catat waktu dibuka
            $log->update([
                'is_opened' => true,
                'opened_at' => now(),
            ]);

            // Tambah angka statistik di tabel utamanya
            $log->campaign()->increment('opened_count');
        }

        // Hasilkan gambar transparan GIF berukuran 1x1 Pixel
        $pixel = base64_decode('R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICBAEAOw==');

        // Kembalikan sebagai respon file gambar (bukan teks/JSON)
        return response($pixel, 200)->header('Content-Type', 'image/gif');
    }

    // public function getCampaignHistory()
    // {
    //     $campaigns = Campaign::orderBy('created_at', 'desc')->get()->map(function ($campaign) {
    //         // Hitung Persentase Open Rate
    //         $openRate = $campaign->sent_count > 0
    //             ? round(($campaign->opened_count / $campaign->sent_count) * 100, 1)
    //             : 0;

    //         return [
    //             'id' => $campaign->id,
    //             'subject' => $campaign->subject,
    //             'sent_count' => $campaign->sent_count,
    //             'opened_count' => $campaign->opened_count,
    //             'open_rate' => $openRate,
    //             'date' => $campaign->created_at->format('d M Y, H:i'),
    //         ];
    //     });

    //     return response()->json([
    //         'status' => 'success',
    //         'data' => $campaigns
    //     ]);
    // }

    // 👇 [FITUR BARU] Sistem Pengalih URL (Redirector) & Pelacak Klik 👇
    public function trackClick(Request $request, $logId)
    {
        $url = $request->query('url'); // Ambil url asli web yang dituju dari URL parameter

        // Jika tidak ada URL asli (Mencegah error/blank)
        if (!$url) return redirect('/');

        $log = \App\Models\CampaignLog::find($logId);

        if ($log && !$log->is_clicked) {
            // Catat waktu klik
            $log->update([
                'is_clicked' => true,
                'clicked_at' => now(),
            ]);

            // Tambahkan skor "clicked_count" ke tabel Campaign utama
            $log->campaign()->increment('clicked_count');
        }

        // Segera arahkan (Redirect) pengunjung ke URL aslinya seolah tidak terjadi apa-apa
        return redirect()->away($url);
    }

    // 👇 [UPDATE] Perbarui API History untuk mengirim Data Click Rate (CTR) 👇
    public function getCampaignHistory()
    {
        $campaigns = Campaign::orderBy('created_at', 'desc')->get()->map(function ($campaign) {
            // Persentase yang Buka Email
            $openRate = $campaign->sent_count > 0
                ? round(($campaign->opened_count / $campaign->sent_count) * 100, 1) : 0;

            // Persentase yang Mengklik Tautan (CTR)
            $clickRate = $campaign->sent_count > 0
                ? round(($campaign->clicked_count / $campaign->sent_count) * 100, 1) : 0;

            return [
                'id' => $campaign->id,
                'subject' => $campaign->subject,
                'sent_count' => $campaign->sent_count,
                'opened_count' => $campaign->opened_count,
                'clicked_count' => $campaign->clicked_count ?? 0, // Data baru
                'open_rate' => $openRate,
                'click_rate' => $clickRate, // Data baru
                'date' => $campaign->created_at->format('d M Y, H:i'),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $campaigns
        ]);
    }
}
