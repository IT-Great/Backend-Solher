<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\SendNewsletterJob;

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
}
