<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\SendNewsletterJob;
use Illuminate\Support\Facades\Storage;

class NewsletterController extends Controller
{
    public function broadcast(Request $request)
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'content' => 'required|string', // Isi email dalam format HTML
        ]);

        // Lempar tugas pengiriman ke antrean (Queue)
        SendNewsletterJob::dispatch($request->subject, $request->content);

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
}
