<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\CampaignLog;
use Illuminate\Support\Facades\Schema;

class SystemPruningCommand extends Command
{
    /**
     * Nama perintah artisan yang dipanggil.
     */
    protected $signature = 'system:prune {--days=90 : Batas umur data dalam hari yang akan dibersihkan}';

    /**
     * Deskripsi perintah.
     */
    protected $description = 'Membersihkan log kampanye usang, token autentikasi kedaluwarsa, dan file temporary secara otomatis tanpa mengunci database.';

    public function handle()
    {
        $days = (int) $this->option('days');
        $cutoffDate = now()->subDays($days);

        $this->info("Memulai proses pembersihan sistem (Data lebih lama dari {$days} hari / sebelum {$cutoffDate->toDateString()})...");

        // 1. Pembersihan Campaign Logs Usang (Menggunakan Chunking Delete agar Zero-Lock)
        $this->pruneCampaignLogs($cutoffDate);

        // 2. Pembersihan Token Autentikasi Sanctum yang Sudah Kedaluwarsa/Usang
        $this->pruneSanctumTokens($cutoffDate);

        // 3. Pembersihan File Temporary / Export CSV Lama di Storage
        $this->pruneTemporaryStorage();

        $this->info("Pembersihan sistem selesai!");
        Log::info("System Pruning Berhasil Dijalankan: Data sebelum {$cutoffDate->toDateString()} telah dibersihkan.");

        return Command::SUCCESS;
    }

    /**
     * Menghapus log campaign email lama secara bertahap (Chunking).
     */
    protected function pruneCampaignLogs($cutoffDate)
    {
        $this->comment('Membersihkan Campaign Logs lama...');
        $totalDeleted = 0;
        $batchSize = 1000;

        do {
            // Hapus dalam batch kecil (1000 baris) agar tidak membebani I/O Database
            $deleted = DB::table('campaign_logs')
                ->where('created_at', '<', $cutoffDate)
                ->limit($batchSize)
                ->delete();

            $totalDeleted += $deleted;
            
            // Beri jeda 50 milidetik antar-batch agar transaksi pengguna tidak terganggu
            if ($deleted > 0) {
                usleep(50000);
            }
        } while ($deleted > 0);

        $this->info("✓ Selesai: {$totalDeleted} baris Campaign Logs berhasil dibersihkan.");
    }

    /**
     * Menghapus token login Sanctum yang sudah tidak aktif lama.
     */
    protected function pruneSanctumTokens($cutoffDate)
    {
        if (Schema::hasTable('personal_access_tokens')) {
            $this->comment('Membersihkan Token Sanctum usang...');

            $deletedTokens = DB::table('personal_access_tokens')
                ->where(function ($query) use ($cutoffDate) {
                    $query->where('last_used_at', '<', $cutoffDate)
                          ->orWhere(function ($q) use ($cutoffDate) {
                              $q->whereNull('last_used_at')
                                ->where('created_at', '<', $cutoffDate);
                          });
                })
                ->delete();

            $this->info("✓ Selesai: {$deletedTokens} token login kedaluwarsa dibersihkan.");
        }
    }

    /**
     * Menghapus file-file temporary lokal (seperti export CSV atau sampah upload).
     */
    protected function pruneTemporaryStorage()
    {
        $this->comment('Memeriksa direktori storage sementara...');
        $tempFiles = Storage::disk('local')->files('temp');
        $deletedCount = 0;

        foreach ($tempFiles as $file) {
            $lastModified = Storage::disk('local')->lastModified($file);
            // Hapus file di folder temp yang umurnya lebih dari 24 jam
            if ($lastModified < now()->subDay()->timestamp) {
                Storage::disk('local')->delete($file);
                $deletedCount++;
            }
        }

        $this->info("✓ Selesai: {$deletedCount} file sementara di storage dibersihkan.");
    }
}