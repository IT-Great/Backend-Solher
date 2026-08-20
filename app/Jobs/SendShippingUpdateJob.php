<?php

namespace App\Jobs;

use App\Mail\ShippingUpdateMail;
use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendShippingUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $transactionId;
    protected $status;

    /**
     * Create a new job instance.
     */
    public function __construct($transactionId, $status)
    {
        $this->transactionId = $transactionId;
        $this->status = strtolower($status);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $transaction = Transaction::with(['user', 'address'])->find($this->transactionId);

        if (!$transaction || !$transaction->user) {
            return;
        }

        // Terjemahkan status Biteship menjadi bahasa manusia yang ramah
        $statusMapping = [
            'confirmed' => [
                'judul' => 'Pesanan Dikonfirmasi Kurir',
                'pesan' => 'Pihak ekspedisi telah menerima permintaan pengiriman dan akan segera menjemput paket Anda.'
            ],
            'allocated' => [
                'judul' => 'Kurir Dialokasikan',
                'pesan' => 'Seorang kurir telah ditugaskan dan sedang menuju lokasi penjemputan paket.'
            ],
            'picking_up' => [
                'judul' => 'Kurir Sedang Menjemput',
                'pesan' => 'Kurir saat ini sedang dalam perjalanan untuk mengambil paket Anda dari gudang kami.'
            ],
            'picked' => [
                'judul' => 'Paket Telah Dijemput',
                'pesan' => 'Paket Anda sudah berada di tangan kurir dan memulai perjalanannya menuju lokasi Anda.'
            ],
            'dropping_off' => [
                'judul' => 'Paket Sedang Diantar',
                'pesan' => 'Kabar baik! Kurir sedang dalam perjalanan menuju alamat tujuan Anda. Mohon pastikan ada penerima di lokasi.'
            ],
            'delivered' => [
                'judul' => 'Paket Berhasil Dikirim! 🎉',
                'pesan' => 'Paket Anda telah berhasil dikirimkan ke alamat tujuan. Terima kasih telah berbelanja di Solher!'
            ],
            'rejected' => [
                'judul' => 'Pengiriman Ditolak',
                'pesan' => 'Mohon maaf, pihak ekspedisi menolak pengiriman ini. Tim kami akan segera menindaklanjutinya.'
            ],
            'cancelled' => [
                'judul' => 'Pengiriman Dibatalkan',
                'pesan' => 'Proses pengiriman telah dibatalkan. Dana Anda akan segera kami proses untuk dikembalikan.'
            ],
            'returned' => [
                'judul' => 'Paket Dikembalikan',
                'pesan' => 'Paket Anda dikembalikan ke gudang kami oleh pihak kurir. Silakan hubungi layanan pelanggan kami.'
            ],
        ];

        // Jika statusnya ada di daftar, kirim email
        if (array_key_exists($this->status, $statusMapping)) {
            $statusJudul = $statusMapping[$this->status]['judul'];
            $statusPesan = $statusMapping[$this->status]['pesan'];

            try {
                Mail::to($transaction->user->email)->send(new ShippingUpdateMail($transaction, $statusPesan, $statusJudul));
            } catch (\Exception $e) {
                Log::error("Gagal kirim email shipping update ke {$transaction->user->email}: " . $e->getMessage());
            }
        }
    }
}