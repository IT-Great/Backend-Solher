<?php

// namespace App\Http\Controllers;

// use App\Models\Contact;
// use Illuminate\Http\Request;
// use App\Mail\AdminResponseMail;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Support\Facades\Mail;

// class ContactController extends Controller
// {
//     public function store(Request $request)
//     {
//         $data = $request->validate([
//             'name' => 'required',
//             'email' => 'required|email',
//             'phone' => 'nullable',
//             'description' => 'required'
//         ]);

//         if ($request->user('sanctum')) {
//             $data['user_id'] = $request->user('sanctum')->id;
//         }

//         Contact::create($data);

//         return response()->json(['message' => 'Message sent successfully'], 201);
//     }

//     // Fungsi Admin mengambil semua pesan
//     public function getInboundMessages()
//     {
//         // Ubah langsung hit ke model agar data lengkap sesuai DB terbaca di Vue
//         $messages = Contact::with('user')->latest()->get();
//         return response()->json($messages);
//     }

//     // [BARU] Fungsi Admin melihat detail (Sekaligus mark as read)
//     public function showAdminMessage($id)
//     {
//         $contact = Contact::with('user')->findOrFail($id);

//         // Jika belum dibaca, ubah jadi sudah dibaca saat dibuka
//         if (!$contact->is_read) {
//             $contact->update(['is_read' => true]);
//         }

//         return response()->json($contact);
//     }

//     // [BARU] Fungsi Admin membalas pesan
//     public function respondMessage(Request $request, $id)
//     {
//         $request->validate(['response' => 'required|string']);

//         $contact = Contact::findOrFail($id);

//         $contact->update([
//             'response' => $request->response,
//             'is_read' => true // Pastikan juga ter-read
//         ]);

//         // Kirim Email
//         try {
//             Mail::to($contact->email)->send(new AdminResponseMail($contact));
//         } catch (\Exception $e) {
//             report($e);
//             // Lanjutkan saja meskipun email gagal, data tetap tersimpan di web
//             \Log::error('Gagal kirim email kontak: ' . $e->getMessage());
//         }

//         return response()->json(['message' => 'Response sent successfully']);
//     }

//     // [BARU] Fungsi User melihat riwayat pesan mereka sendiri
//     public function userHistory(Request $request)
//     {
//         $messages = Contact::where('user_id', $request->user()->id)->latest()->get();
//         return response()->json($messages);
//     }

//     public function subscribe(\Illuminate\Http\Request $request)
//     {
//         $request->validate([
//             'email' => 'required|email:rfc,dns'
//         ], [
//             'email.dns' => 'The email domain does not seem to be valid or active.'
//         ]);

//         $email = $request->email;

//         // Cek apakah ini email dari user yang sudah terdaftar di web kita
//         $user = \App\Models\User::where('email', $email)->first();
//         $isRegistered = $user ? true : false;

//         $subscriber = \App\Models\Subscriber::where('email', $email)->first();

//         if ($subscriber) {
//             if (!$subscriber->is_active) {
//                 $subscriber->update(['is_active' => true, 'is_registered' => $isRegistered]);
//             } else {
//                 return response()->json(['message' => 'You are already subscribed!'], 400);
//             }
//         } else {
//             \App\Models\Subscriber::create([
//                 'email' => $email,
//                 'is_registered' => $isRegistered,
//                 'is_active' => true
//             ]);
//         }

//         // Jika dia user terdaftar, update status di tabel users juga
//         if ($user) {
//             $user->update(['is_subscribed' => true]);
//         }

//         // Kirim Email Welcome (Sama seperti sebelumnya)
//         try {
//             \Illuminate\Support\Facades\Mail::to($email)->send(new \App\Mail\WelcomeSubscriberMail($email));
//         } catch (\Exception $e) {
//             report($e);
//             \Illuminate\Support\Facades\Log::error('Subscribe Mail Error: ' . $e->getMessage());
//         }

//         return response()->json(['message' => 'Subscription successful! Welcome to our newsletter.']);
//     }

//     // [BARU] Fungsi Admin untuk mengambil jumlah pesan yang belum dibaca
//     public function getUnreadCount()
//     {
//         $count = Contact::where('is_read', false)->count();

//         return response()->json([
//             'unread_count' => $count
//         ]);
//     }
// }

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Mail\AdminResponseMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class ContactController extends Controller
{
    public function store(Request $request)
    {
        // 👇 PERBAIKAN: Rate Limiter Anti-Spam Bot (Maks 3 pesan / 5 menit per IP)
        $throttleKey = 'contact_store|' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            return response()->json(['message' => 'Terlalu banyak permintaan. Silakan coba lagi nanti.'], 429);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:20',
            'description' => 'required|string|max:2000'
        ]);

        if ($request->user('sanctum')) {
            $data['user_id'] = $request->user('sanctum')->id;
        }

        Contact::create($data);

        RateLimiter::hit($throttleKey, 300); // Kunci selama 5 menit per hit

        return response()->json(['message' => 'Message sent successfully'], 201);
    }

    // Fungsi Admin mengambil semua pesan
    // public function getInboundMessages()
    // {
    //     // 👇 PERBAIKAN: Gunakan paginate agar RAM server aman dari Memory Leak
    //     $messages = Contact::with('user')->latest()->paginate(50);
    //     return response()->json($messages);
    // }

    // Fungsi Admin mengambil semua pesan
    public function getInboundMessages()
    {
        // 👇 PERBAIKAN: Dikembalikan ke get() agar Front-End dapat menghitung
        // Total Messages, Unread, dan melakukan Search secara menyeluruh (Global).
        $messages = Contact::with('user')->latest()->get();
        return response()->json($messages);
    }
    // Fungsi Admin melihat detail (Sekaligus mark as read)
    public function showAdminMessage($id)
    {
        $contact = Contact::with('user')->findOrFail($id);

        if (!$contact->is_read) {
            $contact->update(['is_read' => true]);
        }

        return response()->json($contact);
    }

    // Fungsi Admin membalas pesan
    public function respondMessage(Request $request, $id)
    {
        $request->validate(['response' => 'required|string|max:5000']);

        $contact = Contact::findOrFail($id);

        $contact->update([
            'response' => $request->response,
            'is_read' => true
        ]);

        // 👇 PERBAIKAN: Gunakan queue() agar request admin tidak lambat/timeout
        try {
            Mail::to($contact->email)->queue(new AdminResponseMail($contact));
        } catch (\Exception $e) {
            report($e);
            Log::error('Gagal antre email balasan kontak: ' . $e->getMessage());
        }

        return response()->json(['message' => 'Response sent successfully']);
    }

    // Fungsi User melihat riwayat pesan mereka sendiri
    public function userHistory(Request $request)
    {
        // 👇 PERBAIKAN: Gunakan limit atau paginate untuk user history
        $messages = Contact::where('user_id', $request->user()->id)
            ->latest()
            ->limit(50)
            ->get();

        return response()->json($messages);
    }

    public function subscribe(\Illuminate\Http\Request $request)
    {
        // 👇 PERBAIKAN: Rate Limiter Anti-Spam Bot (Maks 2 subscribe / 5 menit per IP)
        $throttleKey = 'newsletter_subscribe|' . $request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 2)) {
            return response()->json(['message' => 'Terlalu banyak permintaan. Silakan coba lagi nanti.'], 429);
        }

        // 👇 PERBAIKAN: Hapus aturan 'dns' karena sering memicu Gateway Timeout di Production
        $request->validate([
            'email' => 'required|email:rfc|max:255'
        ]);

        $email = Str::lower($request->email);

        $user = \App\Models\User::where('email', $email)->first();
        $isRegistered = $user ? true : false;

        $subscriber = \App\Models\Subscriber::where('email', $email)->first();

        if ($subscriber) {
            if (!$subscriber->is_active) {
                $subscriber->update(['is_active' => true, 'is_registered' => $isRegistered]);
            } else {
                return response()->json(['message' => 'You are already subscribed!'], 400);
            }
        } else {
            \App\Models\Subscriber::create([
                'email' => $email,
                'is_registered' => $isRegistered,
                'is_active' => true
            ]);
        }

        if ($user) {
            $user->update(['is_subscribed' => true]);
        }

        // 👇 PERBAIKAN: Gunakan queue() agar pelanggan tidak perlu menunggu loading email selesai
        try {
            \Illuminate\Support\Facades\Mail::to($email)->queue(new \App\Mail\WelcomeSubscriberMail($email));
            RateLimiter::hit($throttleKey, 300); // Kunci jika sukses
        } catch (\Exception $e) {
            report($e);
            \Illuminate\Support\Facades\Log::error('Subscribe Mail Error: ' . $e->getMessage());
        }

        return response()->json(['message' => 'Subscription successful! Welcome to our newsletter.']);
    }

    // Fungsi Admin untuk mengambil jumlah pesan yang belum dibaca
    public function getUnreadCount()
    {
        $count = Contact::where('is_read', false)->count();

        return response()->json([
            'unread_count' => $count
        ]);
    }
}
