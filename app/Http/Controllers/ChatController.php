<?php

// namespace App\Http\Controllers;

// use App\Events\MessageRead;
// use App\Events\MessageSent;
// use App\Events\UserTyping;
// use App\Models\Message;
// use App\Models\User;
// use Illuminate\Http\Request;

// class ChatController extends Controller
// {
//     // public function getAdmins()
//     // {
//     //     $admins = User::where('usertype', '!=', 'user')->get();
//     //     return response()->json($admins);
//     // }

//     // Mengambil daftar admin (hanya Superadmin & Admin) untuk halaman customer
//     public function getAdmins()
//     {
//         // [PERBAIKAN] Gunakan whereIn untuk secara eksplisit memilih usertype yang diizinkan melayani chat
//         $admins = User::whereIn('usertype', ['admin', 'superadmin'])
//             ->orderBy('usertype', 'desc') // Opsional: Superadmin bisa ditaruh di atas
//             ->withCount(['messages as unread_count' => function ($query) {
//                 // Hitung pesan yang dikirim oleh admin ini, ditujukan ke user yang sedang login, dan belum dibaca
//                 $query->where('is_read', false)
//                     ->where('receiver_id', auth()->id());
//             }])
//             ->get();

//         return response()->json($admins);
//     }

//     public function getMessages($userId)
//     {
//         $myId = auth()->id();

//         $messages = Message::where(function ($q) use ($myId, $userId) {
//             $q->where('sender_id', $myId)->where('receiver_id', $userId);
//         })->orWhere(function ($q) use ($myId, $userId) {
//             $q->where('sender_id', $userId)->where('receiver_id', $myId);
//         })->with('sender', 'receiver')->orderBy('created_at', 'asc')->get();

//         return response()->json($messages);
//     }

//     // public function sendMessage(Request $request)
//     // {
//     //     $request->validate([
//     //         'receiver_id' => 'required|exists:users,id',
//     //         'message' => 'required|string'
//     //     ]);

//     //     $message = Message::create([
//     //         'sender_id' => auth()->id(),
//     //         'receiver_id' => $request->receiver_id,
//     //         'message' => $request->message
//     //     ]);

//     //     broadcast(new MessageSent($message))->toOthers();

//     //     return response()->json($message->load('sender', 'receiver'));
//     // }

//     public function sendMessage(Request $request)
//     {
//         // 1. Validasi: Boleh teks saja, boleh file saja, boleh dua-duanya.
//         $request->validate([
//             'receiver_id' => 'required|exists:users,id',
//             'message' => 'nullable|string',
//             'attachment' => 'nullable|file|mimes:jpeg,png,jpg,webp,mp4,mov,avi|max:10240', // Maksimal 10MB
//         ]);

//         if (! $request->message && ! $request->hasFile('attachment')) {
//             return response()->json(['error' => 'Message or attachment is required'], 422);
//         }

//         $attachmentPath = null;
//         $attachmentType = null;

//         // 2. Logika Upload File
//         if ($request->hasFile('attachment')) {
//             $file = $request->file('attachment');
//             $mimeType = $file->getMimeType();

//             // Deteksi apakah ini gambar atau video
//             $attachmentType = str_contains($mimeType, 'video') ? 'video' : 'image';

//             // Simpan ke storage/app/public/chat_attachments
//             // Pastikan Anda sudah menjalankan `php artisan storage:link` di server VPS!
//             $attachmentPath = $file->store('chat_attachments', 'public');
//         }

//         // [PERBAIKAN] Bersihkan nilai message. Jika kosong "", paksa menjadi null.
//         $cleanMessage = $request->message;
//         if (trim($cleanMessage) === '') {
//             $cleanMessage = null;
//         }

//         // 3. Simpan ke Database
//         $message = Message::create([
//             'sender_id' => auth()->id(),
//             'receiver_id' => $request->receiver_id,
//             'message' => $cleanMessage,
//             'attachment' => $attachmentPath ?? null,
//             'attachment_type' => $attachmentType ?? null,
//         ]);

//         // 4. Pancarkan (Broadcast)
//         broadcast(new MessageSent($message))->toOthers();

//         return response()->json($message->load('sender', 'receiver'));
//     }

//     // [BARU] Menandai pesan telah dibaca
//     public function markAsRead($senderId)
//     {
//         $myId = auth()->id();

//         // Update semua pesan dari lawan bicara yang belum dibaca
//         Message::where('sender_id', $senderId)
//             ->where('receiver_id', $myId)
//             ->where('is_read', false)
//             ->update(['is_read' => true]);

//         // Beri tahu lawan bicara bahwa pesannya sudah kita baca
//         broadcast(new MessageRead($myId, $senderId))->toOthers();

//         return response()->json(['status' => 'success']);
//     }

//     // [BARU] Mengirim sinyal typing
//     public function typing(Request $request)
//     {
//         $request->validate(['receiver_id' => 'required|exists:users,id']);

//         broadcast(new UserTyping(auth()->id(), $request->receiver_id))->toOthers();

//         return response()->json(['status' => 'success']);
//     }
// }

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Message;
use App\Models\Product;
use App\Models\Transaction;
use App\Events\MessageSent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ChatController extends Controller
{
    // Mengambil daftar staf dan AI
    public function getStaffList() {

        $aiUser = User::firstOrCreate(
            ['email' => 'ai@gycora.com'],
            [
                'first_name' => 'Gycora',
                'last_name' => 'AI Assistant',
                'password' => bcrypt('password_rahasia_ai_123'),
                'usertype' => 'admin',
                'phone' => '00000000000'
            ]
        );

        $staff = User::where('usertype', 'admin')
            ->orWhere('email', 'ai@gycora.com')
            ->get();

        $staffArray = $staff->map(function ($user) {
            $data = $user->toArray();
            if ($data['email'] === 'ai@gycora.com') {
                $data['usertype'] = 'ai';
            }
            return $data;
        });

        $staffArray = $staffArray->sortByDesc(function ($user) {
            return $user['usertype'] === 'ai' ? 1 : 0;
        })->values();

        return response()->json($staffArray);
    }

    // Mengambil histori pesan
    public function getMessages($userId) {
        $myId = auth()->id();
        $messages = Message::where(function($q) use ($myId, $userId) {
            $q->where('sender_id', $myId)->where('receiver_id', $userId);
        })->orWhere(function($q) use ($myId, $userId) {
            $q->where('sender_id', $userId)->where('receiver_id', $myId);
        })->orderBy('created_at', 'asc')->get();

        return response()->json($messages);
    }

    // Menyimpan pesan
    public function sendMessage(Request $request) {
        $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'message' => 'required|string'
        ]);

        $myId = auth()->id();
        $receiver = User::findOrFail($request->receiver_id);

        $userMessage = Message::create([
            'sender_id' => $myId,
            'receiver_id' => $receiver->id,
            'message' => $request->message
        ]);

        if ($receiver->email === 'ai@gycora.com') {

            $aiResponseText = $this->generateGeminiResponse($request->message, $myId);

            $aiMessage = Message::create([
                'sender_id' => $receiver->id,
                'receiver_id' => $myId,
                'message' => $aiResponseText
            ]);

            broadcast(new MessageSent($aiMessage));

            return response()->json([
                'status' => 'success',
                'user_message' => $userMessage,
                'ai_message' => $aiMessage
            ]);
        }
        else {
            broadcast(new MessageSent($userMessage))->toOthers();

            return response()->json([
                'status' => 'success',
                'user_message' => $userMessage
            ]);
        }
    }

    /**
     * Helper Local Function untuk mengecek database pesanan
     */
    private function cekStatusPesananLokal($userId, $orderId = null)
    {
        $query = Transaction::where('user_id', $userId)->latest();

        if ($orderId) {
            $query->where('order_id', 'LIKE', '%' . $orderId . '%');
        }

        $transaction = $query->first();

        if (!$transaction) {
            return ['status' => 'error', 'message' => 'Data pesanan tidak ditemukan di sistem.'];
        }

        $result = [
            'order_id' => $transaction->order_id,
            'status_pembayaran' => $transaction->status,
            'metode_pengiriman' => $transaction->shipping_method,
            'nomor_resi' => $transaction->tracking_number ?? 'Resi belum tersedia',
            'status_pengiriman' => $transaction->shipping_status ?? 'Menunggu diproses',
            'total_bayar' => 'Rp ' . number_format($transaction->total_amount, 0, ',', '.')
        ];

        if ($transaction->shipping_method === 'biteship' && $transaction->biteship_order_id) {
            try {
                $res = Http::withHeaders(['Authorization' => config('services.biteship.api_key')])
                    ->get('https://api.biteship.com/v1/orders/' . $transaction->biteship_order_id);

                if ($res->successful()) {
                    $biteshipData = $res->json();
                    $result['status_pengiriman'] = $biteshipData['status'] ?? $transaction->shipping_status;
                    $result['kurir'] = ($biteshipData['courier']['company'] ?? '') . ' ' . ($biteshipData['courier']['type'] ?? '');
                }
            } catch (\Exception $e) {}
        }

        return $result;
    }

    /**
     * Helper Function: Generate Balasan Gemini (Dengan Function Calling)
     */
    private function generateGeminiResponse($userText, $userId)
    {
        try {
            $products = Product::where('status', 'active')
                ->select('name', 'price', 'discount_price', 'wholesale_price', 'bundle_price', 'stock', 'description', 'is_bundle_active')
                ->take(15)
                ->get();

            $dbContext = "DATA PRODUK GYCORA SAAT INI (REAL-TIME):\n";
            foreach ($products as $p) {
                $harga = number_format($p->price, 0, ',', '.');
                $dbContext .= "- {$p->name} (Harga: Rp {$harga}, Stok: {$p->stock}, Deskripsi: {$p->description})\n";
            }

            $hardcodedKnowledge = "
            INFORMASI PERUSAHAAN & KONTAK:
            - Nama: Gycora Essence
            - WhatsApp: 082273736200 | Email: gycora.essence@gmail.com

            PEMESANAN & KEBIJAKAN RETUR:
            - Batas Waktu Retur: Maksimal 3 HARI setelah barang diterima. Wajib Video Unboxing tanpa edit kirim ke email.
            - Proses Refund: Maksimal 30 hari kerja.
            ";

            $systemInstruction = "Kamu adalah Gycora AI, customer service representatif Gycora. Gunakan bahasa Indonesia santai (sapa pengguna 'Kak').\nTUGAS UTAMA:\n- Jika pengguna menanyakan STATUS PESANAN, RESI, atau LACAK PAKET, panggil fungsi 'lacak_pesanan_database' lalu terjemahkan data JSON yang didapat menjadi kalimat yang ramah (contoh: 'Halo Kak! Untuk pesanan nomor X, saat ini sedang dibawa kurir...').\n\n" . $hardcodedKnowledge . "\n\n" . $dbContext;

            $apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));

            if (empty($apiKey)) {
                return "Maaf kak, kunci API AI belum dikonfigurasi.";
            }

            $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey;

            // ====================================================================
            // 1. DEKLARASI ALAT/FUNGSI UNTUK AI
            // ====================================================================
            $tools = [
                [
                    'functionDeclarations' => [
                        [
                            'name' => 'lacak_pesanan_database',
                            'description' => 'Fungsi wajib dipanggil saat pengguna melacak pesanan.',
                            'parameters' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'order_id' => [
                                        'type' => 'STRING',
                                        'description' => 'ID Pesanan. Kosongkan jika tidak disebut.'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ];

            $payload = [
                'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $userText]]]],
                'tools' => $tools,
                'generationConfig' => ['temperature' => 0.3],
            ];

            $response = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

            if ($response->successful()) {
                $data = $response->json();
                $parts = $data['candidates'][0]['content']['parts'][0] ?? [];

                // ====================================================================
                // 3. JIKA AI MENGAMBIL KEPUTUSAN UNTUK MEMANGGIL FUNGSI LOKAL
                // ====================================================================
                if (isset($parts['functionCall'])) {
                    $functionCall = $parts['functionCall'];
                    $functionName = $functionCall['name'];
                    $args = $functionCall['args'] ?? [];

                    if ($functionName === 'lacak_pesanan_database') {

                        // A. Dapatkan data dari database lokal
                        $orderIdDicari = $args['order_id'] ?? null;
                        $hasilDatabase = $this->cekStatusPesananLokal($userId, $orderIdDicari);

                        // B. [PERBAIKAN FINAL]: Memaksa Array Kosong menjadi Objek Kosong
                        // menggunakan JSON_FORCE_OBJECT
                        $encodedArgs = json_encode($args, JSON_FORCE_OBJECT);
                        $safeArgs = json_decode($encodedArgs, false);

                        // C. [PERBAIKAN FINAL]: Membungkus response di dalam "result"
                        // sesuai struktur mutlak REST API Gemini
                        $wrappedResponse = [
                            'result' => $hasilDatabase
                        ];

                        $encodedResponse = json_encode($wrappedResponse, JSON_FORCE_OBJECT);
                        $safeResponse = json_decode($encodedResponse, false);

                        // D. Susun ulang pesan secara manual
                        $msg1 = ['role' => 'user', 'parts' => [['text' => $userText]]];

                        $msg2 = ['role' => 'model', 'parts' => [
                            ['functionCall' => ['name' => $functionName, 'args' => $safeArgs]]
                        ]];

                        $msg3 = ['role' => 'function', 'parts' => [
                            ['functionResponse' => ['name' => $functionName, 'response' => $safeResponse]]
                        ]];

                        $secondPayload = [
                            'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
                            'tools' => $tools,
                            'contents' => [$msg1, $msg2, $msg3],
                            'generationConfig' => ['temperature' => 0.4],
                        ];

                        $secondResponse = Http::timeout(20)->withHeaders(['Content-Type' => 'application/json'])->post($url, $secondPayload);

                        if ($secondResponse->successful()) {
                            $secondData = $secondResponse->json();
                            return $secondData['candidates'][0]['content']['parts'][0]['text'] ?? "Maaf kak, saya gagal menerjemahkan data pesanannya.";
                        } else {
                            // [PENTING] Jika masih error, pesan ini akan muncul di file storage/logs/laravel.log Anda
                            Log::error('Gemini API Function Call Error: ' . $secondResponse->body());
                            return "Maaf kak, sistem sedang kesulitan menerjemahkan data pesanan dari database.";
                        }
                    }
                }

                return $parts['text'] ?? "Maaf kak, saya sedang gagal memproses jawaban biasa.";
            }

            Log::error('Gemini API Error: ' . $response->body());
            return "Maaf kak, koneksi otak AI saya sedang bermasalah. Mohon hubungi CS manusia kami ya.";

        } catch (\Exception $e) {
            Log::error('Gemini Exception: ' . $e->getMessage());
            return "Maaf kak, sistem AI sedang offline saat ini. Silakan hubungi WA 082273736200.";
        }
    }
}
