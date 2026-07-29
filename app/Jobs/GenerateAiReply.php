<?php

// namespace App\Jobs;

// use App\Events\MessageSent;
// use App\Events\UserTyping;
// use App\Models\Message;
// use App\Models\Product;
// use App\Models\User;
// use Illuminate\Bus\Queueable;
// use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Foundation\Bus\Dispatchable;
// use Illuminate\Queue\InteractsWithQueue;
// use Illuminate\Queue\SerializesModels;
// use Illuminate\Support\Facades\Http;
// use Illuminate\Support\Facades\Log;

// class GenerateAiReply implements ShouldQueue
// {
//     use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

//     public $tries = 3; // 👈 TAMBAHKAN BARIS INI (Maksimal 3 kali percobaan)

//     public $receiverId; // ID pelanggan

//     public $userMessage; // Teks dari pelanggan

//     public function __construct($receiverId, $userMessage)
//     {
//         $this->receiverId = $receiverId;
//         $this->userMessage = $userMessage;
//     }

//     // public function handle()
//     // {
//     //     // 1. Ambil ID AI (Sesuaikan dengan ID AI di database Anda)
//     //     $aiUserId = 99;

//     //     // 2. Pancarkan status "Typing..." agar UI Vue terlihat realistis
//     //     broadcast(new UserTyping($aiUserId, $this->receiverId))->toOthers();

//     //     // 3. Bangun System Prompt (Instruksi Utama AI)
//     //     $systemPrompt = "Kamu adalah CS yang ramah untuk bisnis Solher.
//     //     Gunakan bahasa Indonesia yang santai tapi sopan.
//     //     Kamu bertugas menjual dan menjawab pertanyaan seputar produk Ethereal Glow Brush.
//     //     Kebijakan Pengembalian: Maksimal 7 hari sejak barang diterima dengan segel utuh.
//     //     Flow Pembelian: Pelanggan bisa langsung checkout melalui keranjang belanja.";

//     //     // 4. Ambil 5 riwayat chat terakhir untuk memberikan konteks ke AI
//     //     $history = Message::where(function($q) use ($aiUserId) {
//     //             $q->where('sender_id', $this->receiverId)->where('receiver_id', $aiUserId);
//     //         })
//     //         ->orWhere(function($q) use ($aiUserId) {
//     //             $q->where('sender_id', $aiUserId)->where('receiver_id', $this->receiverId);
//     //         })
//     //         ->orderBy('created_at', 'desc')
//     //         ->take(5)
//     //         ->get()
//     //         ->reverse();

//     //     $messagesForAi = [
//     //         ['role' => 'system', 'content' => $systemPrompt]
//     //     ];

//     //     foreach ($history as $chat) {
//     //         $role = $chat->sender_id === $aiUserId ? 'assistant' : 'user';
//     //         $messagesForAi[] = ['role' => $role, 'content' => $chat->message ?? ''];
//     //     }

//     //     // Tambahkan pesan terbaru
//     //     $messagesForAi[] = ['role' => 'user', 'content' => $this->userMessage];

//     //     // 5. Panggil API AI (Contoh menggunakan OpenAI)
//     //     try {
//     //         $response = Http::withToken(env('OPENAI_API_KEY'))
//     //             ->post('https://api.openai.com/v1/chat/completions', [
//     //                 'model' => 'gpt-3.5-turbo', // atau gpt-4o
//     //                 'messages' => $messagesForAi,
//     //                 'max_tokens' => 300,
//     //             ]);

//     //         $aiReplyText = $response->json('choices.0.message.content');

//     //         // 6. Simpan balasan AI ke Database
//     //         $aiMessage = Message::create([
//     //             'sender_id' => $aiUserId,
//     //             'receiver_id' => $this->receiverId,
//     //             'message' => $aiReplyText,
//     //             'is_read' => false,
//     //         ]);

//     //         // 7. Pancarkan balasan kembali ke Frontend (Vue)
//     //         broadcast(new MessageSent($aiMessage))->toOthers();

//     //     } catch (\Exception $e) {
//     //         \Illuminate\Support\Facades\Log::error('AI Error: ' . $e->getMessage());
//     //     }
//     // }

//     public function handle()
//     {
//         //         // 1. Ambil ID AI (Sesuaikan dengan ID AI di database Anda)
//         //         $aiUserId = 811;

//         //         // 2. Pancarkan status "Typing..." agar UI Vue terlihat realistis
//         //         broadcast(new UserTyping($aiUserId, $this->receiverId))->toOthers();

//         //         // 3. Bangun System Prompt (Instruksi Utama AI)
//         //         $systemPrompt = 'Kamu adalah Customer Service yang ramah bernama Solher AI.
//         // Gunakan bahasa Indonesia yang santai, sopan, dan hangat.
//         // Tugas utamamu adalah membantu pelanggan dan menjual produk tas dari toko Solher.
//         // Saat ini kami menjual berbagai kategori tas, antara lain: Tas Selempang (Sling Bag), Ransel (Backpack), Totebag, dan Handbag.
//         // Jika ada yang bertanya rekomendasi, tanyakan dulu tas tersebut akan digunakan untuk acara apa (misal: kuliah, kerja, atau hangout).
//         // Kebijakan Pengembalian: Maksimal 7 hari sejak barang diterima dengan kondisi tag/segel utuh.
//         // Flow Pembelian: Pelanggan bisa langsung menambahkan produk ke keranjang belanja dan melakukan checkout di website.';

//         $aiUserId = 811; // Sesuaikan ID AI Anda

//         broadcast(new UserTyping($aiUserId, $this->receiverId))->toOthers();

//         // ====================================================================
//         // 1. PENCARIAN CERDAS KE DATABASE (Mini-RAG)
//         // ====================================================================
//         // Kita pecah pesan pelanggan menjadi kata kunci
//         $keywords = explode(' ', $this->userMessage);

//         // Panggil model Produk Anda (Sesuaikan 'App\Models\Product' dengan nama model asli Anda)
//         $query = Product::query();

//         foreach ($keywords as $word) {
//             // Hanya cari kata yang lumayan panjang untuk menghindari kata hubung (di, ke, dari)
//             if (strlen($word) > 3) {
//                 $query->orWhere('name', 'LIKE', '%'.$word.'%')
//                       // Asumsi Anda punya kolom 'category' dan 'description'
//                     ->orWhere('material', 'LIKE', '%'.$word.'%')
//                     ->orWhere('description', 'LIKE', '%'.$word.'%')
//                     ->orWhere('description_en', 'LIKE', '%'.$word.'%')
//                     ->orWhere('design', 'LIKE', '%'.$word.'%')
//                     ->orWhere('design_en', 'LIKE', '%'.$word.'%')
//                     ->orWhere('status', 'LIKE', '%'.$word.'%');
//             }
//         }

//         // Ambil maksimal 5 produk paling relevan agar prompt tidak terlalu panjang
//         $relatedProducts = $query->take(5)->get();

//         // ====================================================================
//         // 2. RAKIT DATA DATABASE MENJADI TEKS UNTUK DIBACA AI
//         // ====================================================================
//         $databaseContext = "DATA PRODUK SOLHER SAAT INI (REAL-TIME):\n";

//         if ($relatedProducts->isEmpty()) {
//             $databaseContext .= "- Tidak ada data produk spesifik yang ditarik untuk pertanyaan ini.\n";
//         } else {
//             foreach ($relatedProducts as $item) {
//                 // Sesuaikan nama field ($item->name, $item->price, dll) dengan kolom database Anda
//                 $harga = number_format($item->price, 0, ',', '.');
//                 $databaseContext .= "- Nama: {$item->name} | Kategori: {$item->category} | Harga: Rp {$harga} | Stok: {$item->stock} | Info: {$item->description}\n";
//             }
//         }

//         // ====================================================================
//         // 3. BANGUN SYSTEM PROMPT DINAMIS
//         // ====================================================================
//         $systemPrompt = "Kamu adalah Solher AI, asisten virtual ramah untuk toko Solher.
//         Gunakan bahasa yang hangat, profesional, dan gunakan emoji secukupnya.

//         TUGAS UTAMA:
//         Jawab pertanyaan pengguna HANYA berdasarkan 'DATA PRODUK SOLHER SAAT INI' di bawah ini.
//         Jika data produk di bawah kosong atau tidak relevan dengan pertanyaan, katakan dengan sopan bahwa kamu belum menemukan informasi tersebut dan tawarkan bantuan lain.
//         Jangan pernah mengarang harga atau stok!

//         ".$databaseContext;

//         // // 4. Ambil 5 riwayat chat terakhir untuk memberikan konteks ke AI
//         // // 👇 BAGIAN INI YANG SEBELUMNYA IKUT TER-COMMENT 👇
//         // $history = Message::where(function($q) use ($aiUserId) {
//         //         $q->where('sender_id', $this->receiverId)->where('receiver_id', $aiUserId);
//         //     })
//         //     ->orWhere(function($q) use ($aiUserId) {
//         //         $q->where('sender_id', $aiUserId)->where('receiver_id', $this->receiverId);
//         //     })
//         //     ->orderBy('created_at', 'desc')
//         //     ->take(5)
//         //     ->get()
//         //     ->reverse();

//         // // 5. Format Riwayat Chat untuk Gemini API
//         // $geminiContents = [];

//         // foreach ($history as $chat) {
//         //     // Di Gemini, role untuk AI adalah 'model', dan pelanggan adalah 'user'
//         //     $role = $chat->sender_id === $aiUserId ? 'model' : 'user';

//         //     // Skip pesan kosong (misal hanya kirim gambar tanpa teks) agar API tidak error
//         //     if (!empty($chat->message)) {
//         //         $geminiContents[] = [
//         //             'role' => $role,
//         //             'parts' => [['text' => $chat->message]]
//         //         ];
//         //     }
//         // }

//         // Tambahkan pesan terbaru dari pelanggan
//         // $geminiContents[] = [
//         //     'role' => 'user',
//         //     'parts' => [['text' => $this->userMessage]]
//         // ];

//         // 4. Ambil 10 riwayat chat terakhir (ditingkatkan agar AI punya konteks utuh)
//         $history = Message::where(function ($q) use ($aiUserId) {
//             $q->where('sender_id', $this->receiverId)->where('receiver_id', $aiUserId);
//         })
//             ->orWhere(function ($q) use ($aiUserId) {
//                 $q->where('sender_id', $aiUserId)->where('receiver_id', $this->receiverId);
//             })
//             ->orderBy('created_at', 'desc')
//             ->take(10)
//             ->get()
//             ->reverse();

//         // 5. Format Riwayat Chat untuk Gemini API (Aturan Wajib Selang-Seling)
//         $geminiContents = [];
//         $lastRole = '';

//         foreach ($history as $chat) {
//             // Hindari memproses pesan yang kosong
//             if (empty(trim($chat->message))) {
//                 continue;
//             }

//             $role = $chat->sender_id === $aiUserId ? 'model' : 'user';

//             // Jika role-nya berurutan berulang (misal: user mengetik 3 kali berturut-turut),
//             // Gabungkan pesannya dengan "enter" (\n) alih-alih membuat tumpukan role baru.
//             if ($role === $lastRole) {
//                 $lastIndex = count($geminiContents) - 1;
//                 $geminiContents[$lastIndex]['parts'][0]['text'] .= "\n".$chat->message;
//             } else {
//                 // Jika role berbeda, buat urutan blok baru yang sah
//                 $geminiContents[] = [
//                     'role' => $role,
//                     'parts' => [['text' => $chat->message]],
//                 ];
//                 $lastRole = $role;
//             }
//         }

//         // ⚠️ CATATAN PENTING: Kode "$geminiContents[] = ['role' => 'user'..."
//         // yang lama SUDAH DIHAPUS dari sini, karena pesan baru yang diketik pelanggan
//         // secara otomatis sudah terangkut ke dalam query $history di atas.

//         // 6. Panggil API Google Gemini
//         try {
//             $apiKey = env('GEMINI_API_KEY');
//             // $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}";

//             // $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key={$apiKey}";

//             // $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}";

//             $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key={$apiKey}";

//             $response = Http::post($url, [
//                 // Menyematkan instruksi khusus sebagai CS
//                 'system_instruction' => [
//                     'parts' => [['text' => $systemPrompt]],
//                 ],
//                 // Riwayat percakapan
//                 'contents' => $geminiContents,
//                 // Mengatur suhu agar jawaban tidak terlalu halusinasi
//                 'generationConfig' => [
//                     'temperature' => 0.4,
//                 ],
//             ]);

//             // Jika API membalas dengan sukses
//             // if ($response->successful()) {
//             //     $aiReplyText = $response->json('candidates.0.content.parts.0.text');

//             //     // 7. Simpan balasan AI ke Database
//             //     $aiMessage = Message::create([
//             //         'sender_id' => $aiUserId,
//             //         'receiver_id' => $this->receiverId,
//             //         'message' => $aiReplyText,
//             //         'is_read' => false,
//             //     ]);

//             //     // 8. Pancarkan balasan kembali ke Frontend (Vue)
//             //     broadcast(new MessageSent($aiMessage))->toOthers();
//             // } else {
//             //     Log::error('Gemini API Error: '.$response->body());
//             // }

//             // Jika API membalas dengan sukses
//             if ($response->successful()) {
//                 $aiReplyText = $response->json('candidates.0.content.parts.0.text');

//                 $aiMessage = Message::create([
//                     'sender_id' => $aiUserId,
//                     'receiver_id' => $this->receiverId,
//                     'message' => $aiReplyText,
//                     'is_read' => false,
//                 ]);

//                 broadcast(new MessageSent($aiMessage))->toOthers();

//             // 👈 TAMBAHKAN BLOK ELSEIF INI
//             } elseif ($response->status() === 503 || $response->status() === 429) {
//                 \Illuminate\Support\Facades\Log::warning('Server Gemini sibuk. Mencoba ulang dalam 10 detik...');

//                 // Lempar kembali Job ini ke dalam antrean, dan tunda selama 10 detik
//                 $this->release(10);

//             } else {
//                 // Jika error selain server sibuk (misal 404 atau salah API Key)
//                 \Illuminate\Support\Facades\Log::error('Gemini API Error: ' . $response->body());
//             }

//         } catch (\Exception $e) {
//             Log::error('Job AI Gagal: '.$e->getMessage());
//         }
//     }
// }

// namespace App\Jobs;

// use App\Events\MessageSent;
// use App\Events\UserTyping;
// use App\Models\Message;
// use App\Models\Product;
// use App\Models\User;
// use Illuminate\Bus\Queueable;
// use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Foundation\Bus\Dispatchable;
// use Illuminate\Queue\InteractsWithQueue;
// use Illuminate\Queue\SerializesModels;
// use Illuminate\Support\Facades\Http;
// use Illuminate\Support\Facades\Log;

// class GenerateAiReply implements ShouldQueue
// {
//     use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

//     public $tries = 3;

//     public $receiverId;
//     public $userMessage;

//     public function __construct($receiverId, $userMessage)
//     {
//         $this->receiverId = $receiverId;
//         $this->userMessage = $userMessage;
//     }

//     public function handle()
//     {
//         $aiUserId = 811; // Sesuaikan ID AI Anda di database Solher

//         // Pancarkan status "Typing..."
//         broadcast(new UserTyping($aiUserId, $this->receiverId))->toOthers();

//         // ====================================================================
//         // 1. PENCARIAN CERDAS KE DATABASE (Mini-RAG untuk Produk)
//         // ====================================================================
//         $keywords = explode(' ', $this->userMessage);
//         $query = Product::query();

//         foreach ($keywords as $word) {
//             if (strlen($word) > 3) {
//                 $query->orWhere('name', 'LIKE', '%'.$word.'%')
//                     ->orWhere('material', 'LIKE', '%'.$word.'%')
//                     ->orWhere('description', 'LIKE', '%'.$word.'%')
//                     ->orWhere('status', 'LIKE', '%'.$word.'%');
//             }
//         }

//         $relatedProducts = $query->take(5)->get();

//         $databaseContext = "DATA PRODUK SOLHER SAAT INI (REAL-TIME):\n";
//         if ($relatedProducts->isEmpty()) {
//             $databaseContext .= "- Tidak ada data produk spesifik yang relevan dengan keyword.\n";
//         } else {
//             foreach ($relatedProducts as $item) {
//                 $harga = number_format($item->price, 0, ',', '.');
//                 $databaseContext .= "- {$item->name} | Rp {$harga} | Stok: {$item->stock} | Info: {$item->description}\n";
//             }
//         }

//         // ====================================================================
//         // 2. INJEKSI PENGETAHUAN HARDCODE (Profil, Banner, Keunggulan)
//         // ====================================================================
//         $hardcodedKnowledge = "
//         PENGETAHUAN PERUSAHAAN (SOLHER):
//         - Solher adalah brand lokal premium yang berfokus pada tas kulit dengan teknologi 'Anti Bakar' (Fire-Retardant Leather).
//         - Material: Menggunakan Vegan Leather grade A yang dilapisi cairan tahan api (Fireproof Coating). Tahan percikan api rokok, tidak mudah meleleh, dan awet bertahun-tahun.
//         - Kategori Produk: Sling Bag (Tas Selempang), Backpack (Ransel), Totebag, dan Handbag.
//         - Target Market: Pekerja lapangan, pengendara motor, dan mahasiswa yang butuh tas tangguh.

//         PENJELASAN GAMBAR BANNER DI WEBSITE SAAT INI:
//         1. Banner Utama (Hero): Menampilkan tas 'Solher Defender Backpack' berwarna hitam matte yang sedang disorot cahaya, dengan latar belakang percikan api kecil untuk menonjolkan fitur anti bakarnya. Tagline: 'Tangguh di Segala Medan'.
//         2. Banner Promo (Tengah): Menampilkan model pria mengendarai motor custom memakai 'Solher Urban Sling Bag'. Teks: 'Diskon 20% Khusus Biker - Gunakan Kode BIKER20'.

//         KEBIJAKAN TOKO:
//         - Pengembalian: Maksimal 7 hari sejak barang diterima (segel utuh).
//         - Pengiriman: Mendukung JNE, J&T, SiCepat, dan Instant (Gojek/Grab).
//         ";

//         // ====================================================================
//         // 3. BANGUN SYSTEM PROMPT
//         // ====================================================================
//         $systemPrompt = "Kamu adalah Solher AI, asisten virtual ramah untuk toko tas Solher. Gunakan bahasa Indonesia yang santai, sopan (panggil pelanggan 'Kak'), dan hangat.

//         TUGAS UTAMA:
//         Jawab pertanyaan pengguna memadukan 'PENGETAHUAN PERUSAHAAN' dan 'DATA PRODUK SOLHER'. Jika ditanya soal tampilan website atau banner, jelaskan sesuai deskripsi di atas. Jangan mengarang fitur atau harga.

//         " . $hardcodedKnowledge . "\n\n" . $databaseContext;

//         // ====================================================================
//         // 4. AMBIL RIWAYAT CHAT
//         // ====================================================================
//         $history = Message::where(function ($q) use ($aiUserId) {
//             $q->where('sender_id', $this->receiverId)->where('receiver_id', $aiUserId);
//         })
//         ->orWhere(function ($q) use ($aiUserId) {
//             $q->where('sender_id', $aiUserId)->where('receiver_id', $this->receiverId);
//         })
//         ->orderBy('created_at', 'desc')
//         ->take(10)
//         ->get()
//         ->reverse();

//         $geminiContents = [];
//         $lastRole = '';

//         foreach ($history as $chat) {
//             if (empty(trim($chat->message))) continue;

//             $role = $chat->sender_id === $aiUserId ? 'model' : 'user';

//             if ($role === $lastRole) {
//                 $lastIndex = count($geminiContents) - 1;
//                 $geminiContents[$lastIndex]['parts'][0]['text'] .= "\n".$chat->message;
//             } else {
//                 $geminiContents[] = [
//                     'role' => $role,
//                     'parts' => [['text' => $chat->message]],
//                 ];
//                 $lastRole = $role;
//             }
//         }

//         // ====================================================================
//         // 5. PANGGIL API GOOGLE GEMINI (Menggunakan model resmi 3.5-flash)
//         // ====================================================================
//         try {
//             $apiKey = env('GEMINI_API_KEY');

//             // Perbaikan Model ke versi resmi yang paling stabil dan cepat
//             $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key={$apiKey}";

//             $response = Http::post($url, [
//                 'system_instruction' => [
//                     'parts' => [['text' => $systemPrompt]],
//                 ],
//                 'contents' => $geminiContents,
//                 'generationConfig' => [
//                     'temperature' => 0.4, // Cukup rendah agar tetap faktual soal produk tas
//                 ],
//             ]);

//             if ($response->successful()) {
//                 $aiReplyText = $response->json('candidates.0.content.parts.0.text');

//                 $aiMessage = Message::create([
//                     'sender_id' => $aiUserId,
//                     'receiver_id' => $this->receiverId,
//                     'message' => $aiReplyText,
//                     'is_read' => false,
//                 ]);

//                 broadcast(new MessageSent($aiMessage))->toOthers();

//             } elseif ($response->status() === 503 || $response->status() === 429) {
//                 Log::warning('Server Gemini sibuk. Mencoba ulang dalam 10 detik...');
//                 $this->release(10);
//             } else {
//                 Log::error('Gemini API Error: ' . $response->body());
//             }

//         } catch (\Exception $e) {
//             Log::error('Job AI Gagal: '.$e->getMessage());
//         }
//     }
// }

// namespace App\Jobs;

// use App\Events\MessageSent;
// use App\Events\UserTyping;
// use App\Models\Message;
// use App\Models\Product;
// use App\Models\User;
// use Illuminate\Bus\Queueable;
// use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Foundation\Bus\Dispatchable;
// use Illuminate\Queue\InteractsWithQueue;
// use Illuminate\Queue\SerializesModels;
// use Illuminate\Support\Facades\Http;
// use Illuminate\Support\Facades\Log;

// class GenerateAiReply implements ShouldQueue
// {
//     use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

//     public $tries = 3;

//     public $receiverId;
//     public $userMessage;

//     public function __construct($receiverId, $userMessage)
//     {
//         $this->receiverId = $receiverId;
//         $this->userMessage = $userMessage;
//     }

//     public function handle()
//     {
//         $aiUserId = 811; // Sesuaikan ID AI Anda di database Solher

//         // Pancarkan status "Typing..."
//         broadcast(new UserTyping($aiUserId, $this->receiverId))->toOthers();

//         // ====================================================================
//         // 1. PENCARIAN CERDAS KE DATABASE (Mini-RAG untuk Produk)
//         // ====================================================================
//         $keywords = explode(' ', $this->userMessage);
//         $query = Product::query();

//         foreach ($keywords as $word) {
//             if (strlen($word) > 3) {
//                 $query->orWhere('name', 'LIKE', '%'.$word.'%')
//                     ->orWhere('material', 'LIKE', '%'.$word.'%')
//                     ->orWhere('description', 'LIKE', '%'.$word.'%')
//                     ->orWhere('status', 'LIKE', '%'.$word.'%');
//             }
//         }

//         $relatedProducts = $query->take(5)->get();

//         $databaseContext = "DATA PRODUK SOLHER SAAT INI (REAL-TIME):\n";
//         if ($relatedProducts->isEmpty()) {
//             $databaseContext .= "- Tidak ada data produk spesifik yang relevan dengan keyword.\n";
//         } else {
//             foreach ($relatedProducts as $item) {
//                 $harga = number_format($item->price, 0, ',', '.');
//                 $databaseContext .= "- {$item->name} | Rp {$harga} | Stok: {$item->stock} | Info: {$item->description}\n";
//             }
//         }

//         // ====================================================================
//         // 2. INJEKSI PENGETAHUAN HARDCODE (Update About Us, CS, & FAQ)
//         // ====================================================================
//         $hardcodedKnowledge = "
//         FILOSOFI & CERITA BRAND (SOLHER):
//         - Hati dari SOLHER: Sebuah 'Surat Cinta untuk Kualitas'. Solher lahir dari pengamatan bahwa dunia bergerak terlalu cepat, namun keindahan sejati tidaklah tergesa-gesa.
//         - Kami tidak percaya pada tren sesaat, melainkan pada kepercayaan diri yang tenang dari siluet klasik (timeless luxury) yang akan semakin indah seiring berjalannya waktu.
//         - Nilai Sentimental: Tas seorang wanita adalah penjaga rahasianya, mimpi-mimpinya, dan kemenangan kecilnya sehari-hari.

//         MATERIAL & PRODUK:
//         - Setiap karya bermula dari sentuhan tangan para pengrajin yang menjunjung tinggi tradisi.
//         - Memadukan kulit sapi dengan mutu tertinggi (serta lini Vegan Leather grade A). Kulit dipilih karena tekstur uratnya yang unik, kekuatannya yang lentur, bernapas, dan hidup.
//         - Teknologi: Dilengkapi teknologi 'Anti Bakar' (Fireproof Coating) sehingga tahan percikan api rokok dan sangat tangguh di lapangan.
//         - Produksi: Diproduksi di tingkat lokal maupun global dengan mitra yang dipilih sangat hati-hati demi memastikan kualitas tertinggi.
//         - Kategori: Sling Bag, Backpack, Totebag, dan Handbag.

//         LAYANAN PELANGGAN (CUSTOMER CARE):
//         - Telepon / WhatsApp: +62 888 388 8585
//         - Email: care@solherbag.com
//         - Jam Operasional: Senin - Jumat (08:00 - 17:00 WIB), Sabtu (09:00 - 14:00 WIB). Minggu & Hari Libur Nasional Tutup.

//         KEBIJAKAN TOKO & FAQ:
//         - Pengiriman: Pesanan diproses sesegera mungkin. Waktu tiba tergantung lokasi. Biaya kirim dihitung otomatis sebelum pembayaran (checkout). Mendukung JNE, J&T, SiCepat, dan Instant.
//         - Pengembalian Barang (Refund): Kepuasan pelanggan adalah prioritas. Pengembalian maksimal 7 hari sejak diterima (segel harus utuh). Hubungi CS untuk kendala apa pun.
//         - Pengecualian: Produk yang diberi tanda khusus 'Final Sale' tidak dapat dikembalikan.

//         PENJELASAN GAMBAR BANNER DI WEBSITE SAAT INI:
//         1. Banner Utama (Hero): Menampilkan tas 'Solher Defender Backpack' hitam matte disorot cahaya dengan latar percikan api. Tagline: 'Tangguh di Segala Medan'.
//         2. Banner Promo (Tengah): Menampilkan model pria di motor custom memakai 'Solher Urban Sling Bag'. Teks: 'Diskon 20% Khusus Biker - Gunakan Kode BIKER20'.
//         ";

//         // ====================================================================
//         // 3. BANGUN SYSTEM PROMPT
//         // ====================================================================
//         $systemPrompt = "Kamu adalah Solher AI, asisten virtual representatif dari butik tas kulit premium Solher. Gunakan bahasa Indonesia yang elegan, santai namun tetap profesional, sopan (panggil pelanggan 'Kak' atau 'Anda'), dan hangat. Pahami bahwa brand ini sangat puitis dan menghargai nilai *craftsmanship*.

//         TUGAS UTAMA:
//         Jawab pertanyaan pengguna secara akurat dengan memadukan 'CERITA BRAND', 'FAQ', dan 'DATA PRODUK SOLHER'. Jika ada yang bertanya tentang kontak, pengembalian, atau jam buka, jawab sesuai data Customer Care. Jika bertanya soal tampilan website atau banner, jelaskan sesuai panduan banner. JANGAN mengarang kebijakan atau harga di luar data yang diberikan.

//         " . $hardcodedKnowledge . "\n\n" . $databaseContext;

//         // ====================================================================
//         // 4. AMBIL RIWAYAT CHAT
//         // ====================================================================
//         $history = Message::where(function ($q) use ($aiUserId) {
//             $q->where('sender_id', $this->receiverId)->where('receiver_id', $aiUserId);
//         })
//         ->orWhere(function ($q) use ($aiUserId) {
//             $q->where('sender_id', $aiUserId)->where('receiver_id', $this->receiverId);
//         })
//         ->orderBy('created_at', 'desc')
//         ->take(10)
//         ->get()
//         ->reverse();

//         $geminiContents = [];
//         $lastRole = '';

//         foreach ($history as $chat) {
//             if (empty(trim($chat->message))) continue;

//             $role = $chat->sender_id === $aiUserId ? 'model' : 'user';

//             if ($role === $lastRole) {
//                 $lastIndex = count($geminiContents) - 1;
//                 $geminiContents[$lastIndex]['parts'][0]['text'] .= "\n".$chat->message;
//             } else {
//                 $geminiContents[] = [
//                     'role' => $role,
//                     'parts' => [['text' => $chat->message]],
//                 ];
//                 $lastRole = $role;
//             }
//         }

//         // ====================================================================
//         // 5. PANGGIL API GOOGLE GEMINI
//         // ====================================================================
//         try {
//             $apiKey = env('GEMINI_API_KEY');

//             $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key={$apiKey}";

//             $response = Http::post($url, [
//                 'system_instruction' => [
//                     'parts' => [['text' => $systemPrompt]],
//                 ],
//                 'contents' => $geminiContents,
//                 'generationConfig' => [
//                     'temperature' => 0.4, // Suhu diatur rendah agar informasi toko tetap akurat
//                 ],
//             ]);

//             if ($response->successful()) {
//                 $aiReplyText = $response->json('candidates.0.content.parts.0.text');

//                 $aiMessage = Message::create([
//                     'sender_id' => $aiUserId,
//                     'receiver_id' => $this->receiverId,
//                     'message' => $aiReplyText,
//                     'is_read' => false,
//                 ]);

//                 broadcast(new MessageSent($aiMessage))->toOthers();

//             } elseif ($response->status() === 503 || $response->status() === 429) {
//                 Log::warning('Server Gemini sibuk. Mencoba ulang dalam 10 detik...');
//                 $this->release(10);
//             } else {
//                 Log::error('Gemini API Error: ' . $response->body());
//             }

//         } catch (\Exception $e) {
//             Log::error('Job AI Gagal: '.$e->getMessage());
//         }
//     }
// }

// namespace App\Jobs;

// use App\Events\MessageSent;
// use App\Events\UserTyping;
// use App\Models\Message;
// use App\Models\Product;
// use App\Models\User;
// use Illuminate\Bus\Queueable;
// use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Foundation\Bus\Dispatchable;
// use Illuminate\Queue\InteractsWithQueue;
// use Illuminate\Queue\SerializesModels;
// use Illuminate\Support\Facades\Http;
// use Illuminate\Support\Facades\Log;

// class GenerateAiReply implements ShouldQueue
// {
//     use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

//     public $tries = 3;

//     public $receiverId;
//     public $userMessage;

//     public function __construct($receiverId, $userMessage)
//     {
//         $this->receiverId = $receiverId;
//         $this->userMessage = $userMessage;
//     }

//     public function handle()
//     {
//         $aiUserId = 811; // Sesuaikan ID AI Anda di database Solher

//         // Pancarkan status "Typing..."
//         broadcast(new UserTyping($aiUserId, $this->receiverId))->toOthers();

//         // ====================================================================
//         // 1. PENCARIAN CERDAS KE DATABASE (Mini-RAG untuk Produk)
//         // ====================================================================
//         $keywords = explode(' ', $this->userMessage);
//         $query = Product::query();

//         foreach ($keywords as $word) {
//             if (strlen($word) > 3) {
//                 $query->orWhere('name', 'LIKE', '%'.$word.'%')
//                     ->orWhere('material', 'LIKE', '%'.$word.'%')
//                     ->orWhere('description', 'LIKE', '%'.$word.'%')
//                     ->orWhere('status', 'LIKE', '%'.$word.'%');
//             }
//         }

//         $relatedProducts = $query->take(5)->get();

//         $databaseContext = "DATA PRODUK SOLHER SAAT INI (REAL-TIME):\n";
//         if ($relatedProducts->isEmpty()) {
//             $databaseContext .= "- Tidak ada data produk spesifik yang relevan dengan keyword.\n";
//         } else {
//             foreach ($relatedProducts as $item) {
//                 $harga = number_format($item->price, 0, ',', '.');
//                 $databaseContext .= "- {$item->name} | Rp {$harga} | Stok: {$item->stock} | Info: {$item->description}\n";
//             }
//         }

//         // ====================================================================
//         // 2. INJEKSI PENGETAHUAN HARDCODE (Update About Us, CS, Kebijakan, FAQ)
//         // ====================================================================
//         $hardcodedKnowledge = "
//         FILOSOFI & CERITA BRAND (SOLHER):
//         - Hati dari SOLHER: Sebuah 'Surat Cinta untuk Kualitas'. Mewakili 'timeless luxury' atau kemewahan tak lekang waktu yang semakin indah seiring berjalannya waktu.
//         - Nilai Sentimental: Tas seorang wanita adalah penjaga rahasianya, mimpi-mimpinya, dan kemenangan kecilnya sehari-hari.

//         MATERIAL & TAMPILAN PRODUK:
//         - Kulit sapi mutu tertinggi dan Vegan Leather grade A. Dilengkapi teknologi 'Anti Bakar' (tahan percikan api rokok).
//         - Kategori: Sling Bag, Backpack, Totebag, dan Handbag.
//         - Catatan: Warna pada monitor mungkin tidak sepenuhnya presisi 100% dengan aslinya karena faktor pencahayaan.

//         LAYANAN PELANGGAN (CUSTOMER CARE):
//         - Telepon / WhatsApp: +62 888 388 8585 | Email: care@solherbag.com
//         - Jam Operasional: Senin - Jumat (08:00 - 17:00 WIB). Tutup: Minggu & Hari Libur Nasional.

//         AKUN, KEAMANAN & PRIVASI:
//         - Pengguna baru memenuhi syarat mendapat bonus selamat datang (Poin Loyalitas) untuk diskon.
//         - Keamanan: Pembayaran 100% aman via payment gateway resmi. Kami TIDAK menyimpan detail kartu kredit di server. Data pribadi dijaga ketat.
//         - Kebijakan Stok: Sistem 'Siapa Cepat, Dia Dapat'. Jika pelanggan terlanjur bayar tapi kehabisan stok, sistem akan memproses auto-refund (pengembalian dana otomatis).

//         PENGIRIMAN & PELACAKAN:
//         - Waktu Pemrosesan: 1-2 hari kerja.
//         - Metode Pengiriman: Reguler (2-5 hari), Hari Berikutnya (Next Day), dan Instan (pesan sebelum pukul 14:00 WIB).
//         - Pengambilan di Toko (In-Store Pickup): Gratis biaya kirim. Diambil di stan fisik / toko mitra (seperti Kecilung Kitchen & Resto) saat event khusus (siap dalam 1 hari kerja).
//         - Pelacakan: Lacak real-time via Dasbor Akun di halaman 'Pesanan' (tunggu 1x24 jam untuk pembaruan sistem logistik).

//         KEBIJAKAN PENGEMBALIAN (REFUND) & FAQ:
//         - Auto-Refund (Pembatalan): Jika dibatalkan sebelum diproses logistik, dana dan Poin Loyalitas kembali otomatis sepenuhnya.
//         - Refund Manual: Maksimal diajukan 3 HARI sejak barang diterima melalui halaman 'Pesanan'. WAJIB menyertakan Video Unboxing / Foto cacat produk.
//         - Syarat Kelayakan Retur: Barang belum digunakan, kemasan asli utuh (termasuk dust bag & label harga).
//         - Tidak Bisa Diretur (Final Sale): Barang diskon (sale), desain khusus, atau rusak karena perawatan tidak tepat. Biaya pengiriman awal tidak dapat dikembalikan.

//         PENJELASAN GAMBAR BANNER DI WEBSITE SAAT INI:
//         1. Banner Utama (Hero): Menampilkan tas 'Solher Defender Backpack' hitam matte disorot cahaya dengan latar percikan api. Tagline: 'Tangguh di Segala Medan'.
//         2. Banner Promo (Tengah): Menampilkan model pria di motor custom memakai 'Solher Urban Sling Bag'. Teks: 'Diskon 20% Khusus Biker - Gunakan Kode BIKER20'.
//         ";

//         // ====================================================================
//         // 3. BANGUN SYSTEM PROMPT
//         // ====================================================================
//         $systemPrompt = "Kamu adalah Solher AI, asisten virtual representatif dari butik tas kulit premium Solher. Gunakan bahasa Indonesia yang elegan, santai namun tetap profesional, sopan (panggil pelanggan 'Kak' atau 'Anda'), dan hangat. Pahami bahwa brand ini sangat puitis dan menghargai nilai *craftsmanship*.

//         TUGAS UTAMA:
//         Jawab pertanyaan pengguna secara akurat dengan memadukan 'CERITA BRAND', 'KEBIJAKAN TOKO', dan 'DATA PRODUK SOLHER'.
//         - Jika ada yang bertanya tentang pengiriman, pengembalian dana, privasi, atau jam operasional, jawab dengan tegas dan jelas sesuai panduan.
//         - Ingatkan pelanggan soal 'Video Unboxing' jika mereka bertanya tentang syarat komplain/retur.
//         - Jika bertanya soal website/banner, jelaskan sesuai data yang diberikan. JANGAN mengarang kebijakan yang bertentangan dengan informasi yang disediakan.

//         " . $hardcodedKnowledge . "\n\n" . $databaseContext;

//         // ====================================================================
//         // 4. AMBIL RIWAYAT CHAT
//         // ====================================================================
//         $history = Message::where(function ($q) use ($aiUserId) {
//             $q->where('sender_id', $this->receiverId)->where('receiver_id', $aiUserId);
//         })
//         ->orWhere(function ($q) use ($aiUserId) {
//             $q->where('sender_id', $aiUserId)->where('receiver_id', $this->receiverId);
//         })
//         ->orderBy('created_at', 'desc')
//         ->take(10)
//         ->get()
//         ->reverse();

//         $geminiContents = [];
//         $lastRole = '';

//         foreach ($history as $chat) {
//             if (empty(trim($chat->message))) continue;

//             $role = $chat->sender_id === $aiUserId ? 'model' : 'user';

//             if ($role === $lastRole) {
//                 $lastIndex = count($geminiContents) - 1;
//                 $geminiContents[$lastIndex]['parts'][0]['text'] .= "\n".$chat->message;
//             } else {
//                 $geminiContents[] = [
//                     'role' => $role,
//                     'parts' => [['text' => $chat->message]],
//                 ];
//                 $lastRole = $role;
//             }
//         }

//         // ====================================================================
//         // 5. PANGGIL API GOOGLE GEMINI
//         // ====================================================================
//         try {
//             $apiKey = env('GEMINI_API_KEY');

//             $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key={$apiKey}";

//             $response = Http::post($url, [
//                 'system_instruction' => [
//                     'parts' => [['text' => $systemPrompt]],
//                 ],
//                 'contents' => $geminiContents,
//                 'generationConfig' => [
//                     'temperature' => 0.4, // Suhu rendah = mematuhi aturan SOP dengan lebih ketat
//                 ],
//             ]);

//             if ($response->successful()) {
//                 $aiReplyText = $response->json('candidates.0.content.parts.0.text');

//                 $aiMessage = Message::create([
//                     'sender_id' => $aiUserId,
//                     'receiver_id' => $this->receiverId,
//                     'message' => $aiReplyText,
//                     'is_read' => false,
//                 ]);

//                 broadcast(new MessageSent($aiMessage))->toOthers();

//             } elseif ($response->status() === 503 || $response->status() === 429) {
//                 Log::warning('Server Gemini sibuk. Mencoba ulang dalam 10 detik...');
//                 $this->release(10);
//             } else {
//                 Log::error('Gemini API Error: ' . $response->body());
//             }

//         } catch (\Exception $e) {
//             Log::error('Job AI Gagal: '.$e->getMessage());
//         }
//     }
// }

// namespace App\Jobs;

// use App\Events\MessageSent;
// use App\Events\UserTyping;
// use App\Models\Message;
// use App\Models\Product;
// use App\Models\User;
// use Illuminate\Bus\Queueable;
// use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Foundation\Bus\Dispatchable;
// use Illuminate\Queue\InteractsWithQueue;
// use Illuminate\Queue\SerializesModels;
// use Illuminate\Support\Facades\Http;
// use Illuminate\Support\Facades\Log;

// class GenerateAiReply implements ShouldQueue
// {
//     use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

//     public $tries = 3;

//     public $receiverId;
//     public $userMessage;

//     public function __construct($receiverId, $userMessage)
//     {
//         $this->receiverId = $receiverId;
//         $this->userMessage = $userMessage;
//     }

//     public function handle()
//     {
//         $aiUserId = 811; // Sesuaikan ID AI Anda di database Solher

//         // Pancarkan status "Typing..."
//         broadcast(new UserTyping($aiUserId, $this->receiverId))->toOthers();

//         // ====================================================================
//         // 1. PENCARIAN CERDAS KE DATABASE (Mini-RAG untuk Produk)
//         // ====================================================================
//         $keywords = explode(' ', $this->userMessage);
//         $query = Product::query();

//         foreach ($keywords as $word) {
//             if (strlen($word) > 3) {
//                 $query->orWhere('name', 'LIKE', '%'.$word.'%')
//                     ->orWhere('material', 'LIKE', '%'.$word.'%')
//                     ->orWhere('description', 'LIKE', '%'.$word.'%')
//                     ->orWhere('status', 'LIKE', '%'.$word.'%');
//             }
//         }

//         $relatedProducts = $query->take(5)->get();

//         $databaseContext = "DATA PRODUK SOLHER SAAT INI (REAL-TIME):\n";
//         if ($relatedProducts->isEmpty()) {
//             $databaseContext .= "- Tidak ada data produk spesifik yang relevan dengan keyword.\n";
//         } else {
//             foreach ($relatedProducts as $item) {
//                 $harga = number_format($item->price, 0, ',', '.');
//                 $databaseContext .= "- {$item->name} | Rp {$harga} | Stok: {$item->stock} | Info: {$item->description}\n";
//             }
//         }

//         // ====================================================================
//         // 2. INJEKSI PENGETAHUAN HARDCODE (Update About Us, CS, Kebijakan, FAQ)
//         // ====================================================================
//         $hardcodedKnowledge = "
//         FILOSOFI & CERITA BRAND (SOLHER):
//         - Hati dari SOLHER: Sebuah 'Surat Cinta untuk Kualitas'. Mewakili 'timeless luxury' atau kemewahan tak lekang waktu yang semakin indah seiring berjalannya waktu.
//         - Nilai Sentimental: Tas seorang wanita adalah penjaga rahasianya, mimpi-mimpinya, dan kemenangan kecilnya sehari-hari.

//         MATERIAL & TAMPILAN PRODUK:
//         - Kulit sapi mutu tertinggi dan Vegan Leather grade A. Dilengkapi teknologi 'Anti Bakar' (tahan percikan api rokok).
//         - Kategori: Sling Bag, Backpack, Totebag, dan Handbag.
//         - Catatan: Warna pada monitor mungkin tidak sepenuhnya presisi 100% dengan aslinya karena faktor pencahayaan.

//         LAYANAN PELANGGAN (CUSTOMER CARE):
//         - Telepon / WhatsApp: +62 888 388 8585 | Email: care@solherbag.com
//         - Jam Operasional: Senin - Jumat (08:00 - 17:00 WIB). Tutup: Minggu & Hari Libur Nasional.

//         AKUN, KEAMANAN & PRIVASI:
//         - Pengguna baru memenuhi syarat mendapat bonus selamat datang (Poin Loyalitas) untuk diskon.
//         - Keamanan: Pembayaran 100% aman via payment gateway resmi. Kami TIDAK menyimpan detail kartu kredit di server. Data pribadi dijaga ketat.
//         - Kebijakan Stok: Sistem 'Siapa Cepat, Dia Dapat'. Jika pelanggan terlanjur bayar tapi kehabisan stok, sistem akan memproses auto-refund (pengembalian dana otomatis).

//         PENGIRIMAN & PELACAKAN:
//         - Waktu Pemrosesan: 1-2 hari kerja.
//         - Metode Pengiriman: Reguler (2-5 hari), Hari Berikutnya (Next Day), dan Instan (pesan sebelum pukul 14:00 WIB).
//         - Pengambilan di Toko (In-Store Pickup): Gratis biaya kirim. Diambil di stan fisik / toko mitra (seperti Kecilung Kitchen & Resto) saat event khusus (siap dalam 1 hari kerja).
//         - Pelacakan: Lacak real-time via Dasbor Akun di halaman 'Pesanan' (tunggu 1x24 jam untuk pembaruan sistem logistik).

//         KEBIJAKAN PENGEMBALIAN (REFUND) & FAQ:
//         - Auto-Refund (Pembatalan): Jika dibatalkan sebelum diproses logistik, dana dan Poin Loyalitas kembali otomatis sepenuhnya.
//         - Refund Manual: Maksimal diajukan 3 HARI sejak barang diterima melalui halaman 'Pesanan'. WAJIB menyertakan Video Unboxing / Foto cacat produk.
//         - Syarat Kelayakan Retur: Barang belum digunakan, kemasan asli utuh (termasuk dust bag & label harga).
//         - Tidak Bisa Diretur (Final Sale): Barang diskon (sale), desain khusus, atau rusak karena perawatan tidak tepat. Biaya pengiriman awal tidak dapat dikembalikan.

//         PENJELASAN GAMBAR BANNER DI WEBSITE SAAT INI:
//         1. Banner Utama (Hero): Menampilkan tas 'Solher Defender Backpack' hitam matte disorot cahaya dengan latar percikan api. Tagline: 'Tangguh di Segala Medan'.
//         2. Banner Promo (Tengah): Menampilkan model pria di motor custom memakai 'Solher Urban Sling Bag'. Teks: 'Diskon 20% Khusus Biker - Gunakan Kode BIKER20'.
//         ";

//         // ====================================================================
//         // 3. BANGUN SYSTEM PROMPT
//         // ====================================================================
//         $systemPrompt = "Kamu adalah Solher AI, asisten virtual representatif dari butik tas kulit premium Solher. Gunakan bahasa Indonesia yang elegan, santai namun tetap profesional, sopan (panggil pelanggan 'Kak' atau 'Anda'), dan hangat. Pahami bahwa brand ini sangat puitis dan menghargai nilai *craftsmanship*.

//         TUGAS UTAMA:
//         Jawab pertanyaan pengguna secara akurat dengan memadukan 'CERITA BRAND', 'KEBIJAKAN TOKO', dan 'DATA PRODUK SOLHER'.
//         - Jika ada yang bertanya tentang pengiriman, pengembalian dana, privasi, atau jam operasional, jawab dengan tegas dan jelas sesuai panduan.
//         - Ingatkan pelanggan soal 'Video Unboxing' jika mereka bertanya tentang syarat komplain/retur.
//         - Jika bertanya soal website/banner, jelaskan sesuai data yang diberikan. JANGAN mengarang kebijakan yang bertentangan dengan informasi yang disediakan.

//         " . $hardcodedKnowledge . "\n\n" . $databaseContext;

//         // ====================================================================
//         // 4. AMBIL RIWAYAT CHAT
//         // ====================================================================
//         $history = Message::where(function ($q) use ($aiUserId) {
//             $q->where('sender_id', $this->receiverId)->where('receiver_id', $aiUserId);
//         })
//         ->orWhere(function ($q) use ($aiUserId) {
//             $q->where('sender_id', $aiUserId)->where('receiver_id', $this->receiverId);
//         })
//         ->orderBy('created_at', 'desc')
//         ->take(10)
//         ->get()
//         ->reverse();

//         $geminiContents = [];
//         $lastRole = '';

//         foreach ($history as $chat) {
//             if (empty(trim($chat->message))) continue;

//             $role = $chat->sender_id === $aiUserId ? 'model' : 'user';

//             if ($role === $lastRole) {
//                 $lastIndex = count($geminiContents) - 1;
//                 $geminiContents[$lastIndex]['parts'][0]['text'] .= "\n".$chat->message;
//             } else {
//                 $geminiContents[] = [
//                     'role' => $role,
//                     'parts' => [['text' => $chat->message]],
//                 ];
//                 $lastRole = $role;
//             }
//         }

//         // ====================================================================
//         // 5. PANGGIL API GOOGLE GEMINI
//         // ====================================================================
//         try {
//             $apiKey = env('GEMINI_API_KEY');

//             $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key={$apiKey}";

//             $response = Http::post($url, [
//                 'system_instruction' => [
//                     'parts' => [['text' => $systemPrompt]],
//                 ],
//                 'contents' => $geminiContents,
//                 'generationConfig' => [
//                     'temperature' => 0.4, // Suhu rendah = mematuhi aturan SOP dengan lebih ketat
//                 ],
//             ]);

//             if ($response->successful()) {
//                 $aiReplyText = $response->json('candidates.0.content.parts.0.text');

//                 $aiMessage = Message::create([
//                     'sender_id' => $aiUserId,
//                     'receiver_id' => $this->receiverId,
//                     'message' => $aiReplyText,
//                     'is_read' => false,
//                 ]);

//                 broadcast(new MessageSent($aiMessage))->toOthers();

//             } elseif ($response->status() === 503 || $response->status() === 429) {
//                 Log::warning('Server Gemini sibuk. Mencoba ulang dalam 10 detik...');
//                 $this->release(10);
//             } else {
//                 Log::error('Gemini API Error: ' . $response->body());
//             }

//         } catch (\Exception $e) {
//             Log::error('Job AI Gagal: '.$e->getMessage());
//         }
//     }
// }

// 1 akun untuk AI ChatBot & human CS

namespace App\Jobs;

use App\Events\MessageSent;
use App\Events\UserTyping;
use App\Models\Message;
use App\Models\Product;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class GenerateAiReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $receiverId; // ID Customer
    public $userMessage;

    public function __construct($receiverId, $userMessage)
    {
        $this->receiverId = $receiverId;
        $this->userMessage = $userMessage;
    }

    public function handle()
    {
        // 1. Ambil ID AI Solher
        $aiUser = User::where('email', 'ai@solher.com')->first();
        if (!$aiUser) return;
        $aiUserId = $aiUser->id;

        broadcast(new UserTyping($aiUserId, $this->receiverId))->toOthers();

        // 2. RAG Produk
        $keywords = explode(' ', $this->userMessage);
        $query = Product::query();
        foreach ($keywords as $word) {
            if (strlen($word) > 3) {
                $query->orWhere('name', 'LIKE', '%'.$word.'%')->orWhere('description', 'LIKE', '%'.$word.'%');
            }
        }
        $relatedProducts = $query->take(5)->get();

        $databaseContext = "DATA PRODUK SOLHER:\n";
        foreach ($relatedProducts as $item) {
            $harga = number_format($item->price, 0, ',', '.');
            $databaseContext .= "- {$item->name} | Rp {$harga} | Stok: {$item->stock}\n";
        }

        $hardcodedKnowledge = "
        INFO: Solher Bag. WA: +62 888 388 8585 | Email: care@solherbag.com.
        RETUR: Maksimal 3 HARI sejak diterima. Wajib Video Unboxing utuh.
        ";

        $systemPrompt = "Kamu adalah Solher Care, asisten AI. Sapa pengguna 'Kak'.
        TUGAS MUTLAK:
        1. JIKA PENGGUNA MEMINTA BICARA DENGAN ADMIN ASLI/MANUSIA, WAJIB PANGGIL FUNGSI 'transfer_to_human'.\n" . $hardcodedKnowledge . "\n" . $databaseContext;

        // 3. Ambil Riwayat Chat
        $adminIds = User::whereIn('usertype', ['admin', 'superadmin'])->pluck('id')->toArray();
        $adminIds[] = $aiUserId;

        $history = Message::where(function ($q) use ($adminIds) {
            $q->where('sender_id', $this->receiverId)->whereIn('receiver_id', $adminIds);
        })->orWhere(function ($q) use ($adminIds) {
            $q->whereIn('sender_id', $adminIds)->where('receiver_id', $this->receiverId);
        })->orderBy('created_at', 'desc')->take(6)->get()->reverse();

        $geminiContents = [];
        $lastRole = '';

        foreach ($history as $chat) {
            if (empty(trim($chat->message))) continue;
            $role = $chat->sender_id === $this->receiverId ? 'user' : 'model';

            if ($role === $lastRole) {
                $lastIndex = count($geminiContents) - 1;
                $geminiContents[$lastIndex]['parts'][0]['text'] .= "\n".$chat->message;
            } else {
                $geminiContents[] = ['role' => $role, 'parts' => [['text' => $chat->message]]];
                $lastRole = $role;
            }
        }

        $geminiContents[] = ['role' => 'user', 'parts' => [['text' => $this->userMessage]]];

        try {
            $apiKey = env('GEMINI_API_KEY');
            $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key={$apiKey}";

            $tools = [
                ['functionDeclarations' => [
                    ['name' => 'transfer_to_human', 'description' => 'Panggil ini jika pengguna terang-terangan minta ngobrol sama manusia/admin asli.']
                ]]
            ];

            $payload = [
                'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents' => $geminiContents,
                'tools' => $tools,
                'generationConfig' => ['temperature' => 0.4],
            ];

            $response = Http::timeout(30)->post($url, $payload);

            if ($response->successful()) {
                $data = $response->json();
                $parts = $data['candidates'][0]['content']['parts'][0] ?? [];
                $aiReplyText = "";

                if (isset($parts['functionCall'])) {
                    $functionName = $parts['functionCall']['name'];
                    
                    if ($functionName === 'transfer_to_human') {
                        // KUNCI JADI MODE HUMAN 24 JAM
                        Cache::put('chat_mode_' . $this->receiverId, 'human', now()->addHours(24));
                        $aiReplyText = "Baik Kak, mohon ditunggu sebentar ya. Saya sedang menghubungkan Kakak dengan Admin Solher. Mereka akan segera membalas di obrolan ini 🙏";
                    } 
                } else {
                    $aiReplyText = $parts['text'] ?? "Maaf kak, saya gagal memproses jawaban.";
                }

                if ($aiReplyText) {
                    $aiMessage = Message::create([
                        'sender_id' => $aiUserId,
                        'receiver_id' => $this->receiverId,
                        'message' => $aiReplyText,
                        'is_read' => false,
                    ]);
                    broadcast(new MessageSent($aiMessage->load('sender')))->toOthers();
                }

            } elseif ($response->status() === 503 || $response->status() === 429) {
                Log::warning('Solher AI Kena Limit/Sibuk. Coba 30 detik lagi...');
                $this->release(30);
            } else {
                Log::error('Gemini API Error: ' . $response->body());
            }

        } catch (\Exception $e) {
            Log::error('Job Solher AI Gagal: '.$e->getMessage());
        }
    }
}