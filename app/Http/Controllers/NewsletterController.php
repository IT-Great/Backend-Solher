<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\SendNewsletterJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use App\Models\Subscriber;
use App\Models\Campaign;     // 👇 Import Model Baru
use App\Models\CampaignLog;  // 👇 Import Model Baru

class NewsletterController extends Controller
{
    public function broadcast(Request $request)
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'content' => 'required|string', // Isi email dalam format HTML
        ]);

        // 1. Buat catatan Campaign baru
        $campaign = Campaign::create([
            'subject' => $request->subject,
        ]);

        // 2. Lempar ID Campaign ke antrean (Bukan lagi melempar subject string biasa)
        SendNewsletterJob::dispatch($campaign, $request->content);

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
                    <p style='color: #666;'>Anda tidak akan menerima email promosi lagi dari Gycora.</p>
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
}
