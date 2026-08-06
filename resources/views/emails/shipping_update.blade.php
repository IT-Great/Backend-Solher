<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f3f4f6; color: #111827; padding: 20px; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        .header { background-color: #000000; color: #ffffff; padding: 30px 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; }
        .content { padding: 30px; }
        .status-box { background-color: #ecfdf5; border-left: 4px solid #059669; padding: 15px; margin-bottom: 25px; border-radius: 4px; }
        .status-title { font-weight: 700; color: #065f46; margin: 0 0 5px 0; font-size: 18px; }
        .status-text { margin: 0; color: #047857; }
        .detail-row { margin-bottom: 10px; font-size: 14px; }
        .detail-label { font-weight: 600; color: #6b7280; width: 120px; display: inline-block; }
        .tracking-box { background-color: #f9fafb; border: 1px dashed #d1d5db; padding: 15px; text-align: center; margin: 25px 0; border-radius: 8px; }
        .tracking-number { font-family: monospace; font-size: 20px; font-weight: 700; color: #111827; letter-spacing: 2px; }
        .btn { display: inline-block; background-color: #000000; color: #ffffff; text-decoration: none; padding: 12px 25px; border-radius: 6px; font-weight: 600; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; margin-top: 10px; }
        .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 12px; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Solher Store</h1>
        </div>
        
        <div class="content">
            <p>Halo <strong>{{ $transaction->user->first_name ?? 'Pelanggan' }}</strong>,</p>
            <p>Ada kabar terbaru mengenai perjalanan pesanan Anda!</p>

            <div class="status-box">
                <h2 class="status-title">{{ $statusJudul }}</h2>
                <p class="status-text">{{ $statusPesan }}</p>
            </div>

            <div class="detail-row">
                <span class="detail-label">Order ID:</span> 
                <strong>{{ $transaction->order_id }}</strong>
            </div>
            <div class="detail-row">
                <span class="detail-label">Kurir:</span> 
                <span style="text-transform: uppercase;">{{ $transaction->courier_company }} - {{ $transaction->courier_type }}</span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Penerima:</span> 
                {{ $transaction->address->first_name_address ?? '' }} {{ $transaction->address->last_name_address ?? '' }}
            </div>

            @if($transaction->tracking_number && $transaction->tracking_number !== 'Pending')
            <div class="tracking-box">
                <p style="margin-top: 0; color: #6b7280; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">Nomor Resi Pelacakan</p>
                <div class="tracking-number">{{ $transaction->tracking_number }}</div>
            </div>
            
            <div style="text-align: center;">
                <a href="{{ config('app.frontend_url') }}/orders" class="btn">Lacak Pesanan Saya</a>
            </div>
            @endif
        </div>

        <div class="footer">
            <p>Email ini dikirim otomatis oleh sistem. Harap tidak membalas email ini.</p>
            <p>&copy; {{ date('Y') }} Solher. All rights reserved.</p>
        </div>
    </div>
</body>
</html>