<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use App\Models\User;
use App\Models\PromoClaim;
use Illuminate\Support\Str;
use App\Mail\BirthdayPromoMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendBirthdayPromos extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'promo:send-birthday';

    /**
     * The console command description.
     */
    protected $description = 'Mengecek user yang berulang tahun hari ini dan mengirimkan kode promo spesial';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $today = Carbon::today();

        $this->info("Menjalankan Pengecekan Ulang Tahun untuk tanggal: " . $today->format('m-d'));

        // Cari user yang bulan dan tanggal lahirnya sama dengan hari ini
        $birthdayUsers = User::whereNotNull('birthday_date')
            ->whereMonth('birthday_date', $today->month)
            ->whereDay('birthday_date', $today->day)
            ->get();

        if ($birthdayUsers->isEmpty()) {
            $this->info("Tidak ada user yang berulang tahun hari ini.");
            return;
        }

        $sentCount = 0;

        foreach ($birthdayUsers as $user) {
            // Kita harus memastikan promo ulang tahun TAHUN INI belum dikirim.
            // Format kode: SOLHER-BDAY-[TAHUN]-[RANDOM]
            $yearStr = date('Y');

            // Cek apakah di tahun ini user sudah dapat promo ulang tahun
            $alreadyClaimedThisYear = PromoClaim::where('email', $user->email)
                ->where('promo_code', 'LIKE', "SOLHER-BDAY-{$yearStr}-%")
                ->exists();

            if ($alreadyClaimedThisYear) {
                continue; // Lewati jika sudah dikirim hari ini/tahun ini
            }

            // Generate Kode dan Validitas
            $code = 'SOLHER-BDAY-' . $yearStr . '-' . strtoupper(Str::random(5));
            $discountValue = 250000; // Sesuaikan nominal hadiah ulang tahun Anda
            $expiresAt = now()->addDays(7)->endOfDay(); // Berlaku H+7 sampai jam 23:59:59

            try {
                // 1. Simpan ke database (Agar bisa diverifikasi saat checkout)
                PromoClaim::create([
                    'email' => $user->email,
                    'promo_code' => $code,
                    'discount_value' => $discountValue,
                    'expires_at' => $expiresAt,
                ]);

                // 2. Kirim Email (Jangan lupa gunakan queue jika jumlah user banyak)
                Mail::to($user->email)->send(new BirthdayPromoMail($user, $code, $discountValue, $expiresAt));

                $sentCount++;
                $this->line("Terkirim ke: {$user->email} (Kode: {$code})");

            } catch (\Exception $e) {
                Log::error("Gagal mengirim promo ulang tahun ke {$user->email}: " . $e->getMessage());
                // Hapus claim jika email gagal terkirim agar besok bisa dicoba lagi (opsional)
                PromoClaim::where('promo_code', $code)->delete();
            }
        }

        $this->info("Selesai. Total promo ulang tahun terkirim hari ini: {$sentCount}");
    }
}
