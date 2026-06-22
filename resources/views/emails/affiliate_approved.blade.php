<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 20px auto; padding: 20px; border: 1px solid #eee; border-radius: 10px; }
        .code-box { background-color: #f8fafc; border: 1px dashed #cbd5e1; padding: 15px; text-align: center; font-size: 24px; font-weight: bold; letter-spacing: 2px; color: #2563eb; border-radius: 8px; margin: 20px 0; }
        .footer { margin-top: 30px; font-size: 12px; color: #888; text-align: center; border-top: 1px solid #eee; padding-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Halo, {{ $user->first_name }}!</h2>
        <p>Kabar gembira! Aplikasi Anda untuk bergabung dengan <strong>Program Afiliasi Solher</strong> telah disetujui oleh tim kami.</p>
        
        <p>Anda sekarang dapat mulai mempromosikan produk-produk kami dan mendapatkan komisi khusus dari setiap penjualan yang berhasil. Berikut adalah Kode Referal unik Anda:</p>
        
        <div class="code-box">
            {{ $referralCode }}
        </div>
        
        <p>Silakan masuk ke menu <strong>Profil > Dasbor Afiliator</strong> di website Solher untuk melihat tautan khusus Anda, memantau penjualan, dan mencairkan komisi.</p>
        
        <p>Selamat bergabung dan semoga sukses!<br><strong>Tim Solher</strong></p>
        
        <div class="footer">
            Email ini dibuat otomatis oleh sistem Solher. Harap tidak membalas email ini.
        </div>
    </div>
</body>
</html>