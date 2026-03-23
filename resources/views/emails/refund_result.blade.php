<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px; }
        .header { background-color: #000; color: #fff; padding: 15px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { padding: 20px; }
        .footer { font-size: 12px; color: #777; text-align: center; margin-top: 20px; }
        .approved { color: #16a34a; font-weight: bold; }
        .rejected { color: #dc2626; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Solher Bag - Info Refund</h2>
        </div>
        <div class="content">
            <p>Halo, <strong>{{ $transaction->user->first_name }} {{ $transaction->user->last_name }}</strong>,</p>

            <p>Ini adalah informasi terbaru mengenai pengajuan pengembalian dana (Refund) Anda untuk Order ID: <strong>{{ $transaction->order_id }}</strong>.</p>

            @if($action === 'approve')
                <p>Status Pengajuan: <span class="approved">DISETUJUI</span></p>
                <p>Admin kami telah menyetujui pengajuan refund Anda. Anda sekarang dapat menekan tombol <strong>"Refund Now"</strong> pada halaman riwayat pesanan Anda di website Solher Bag untuk mencairkan dana Anda secara otomatis melalui sistem pembayaran kami.</p>
            @else
                <p>Status Pengajuan: <span class="rejected">DITOLAK</span></p>
                <p>Mohon maaf, setelah melakukan peninjauan terhadap alasan dan bukti yang Anda lampirkan, admin kami tidak dapat memproses pengajuan refund Anda karena tidak memenuhi syarat & ketentuan kami.</p>
            @endif

            <p>Terima kasih atas pengertian Anda.</p>
            <p>Salam,<br>Tim Solher Bag</p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} Solher Bag. All rights reserved.
        </div>
    </div>
</body>
</html>
