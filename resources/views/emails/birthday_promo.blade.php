<!DOCTYPE html>
<html>
<head>
    <title>Selamat Ulang Tahun!</title>
</head>
<body style="font-family: Arial, sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #000; text-transform: uppercase; letter-spacing: 2px;">Happy Birthday, {{ $user->first_name }}! 🎂</h2>

    <p>Semoga hari spesial Anda dipenuhi kebahagiaan. Sebagai tanda terima kasih kami karena telah menjadi bagian dari Solher, kami memberikan hadiah eksklusif untuk Anda.</p>

    <div style="background-color: #f9f9f9; border: 1px dashed #ccc; padding: 20px; text-align: center; margin: 30px 0;">
        <p style="margin-top: 0; font-size: 14px; color: #666; text-transform: uppercase; letter-spacing: 1px;">Kode Voucher Anda</p>
        <h1 style="margin: 10px 0; font-size: 32px; letter-spacing: 4px; color: #d97706;">{{ $promoCode }}</h1>
        <p style="margin-bottom: 0; font-weight: bold;">Diskon: Rp {{ number_format($discountValue, 0, ',', '.') }}</p>
    </div>

    <p style="color: #d32f2f; font-weight: bold; font-size: 12px;">
        *Voucher ini akan hangus dalam 7 hari (Berlaku hingga {{ \Carbon\Carbon::parse($expiresAt)->format('d M Y H:i') }}). Jangan lewatkan!
    </p>

    <p>Warm regards,<br><strong>The Solher Team</strong></p>
</body>
</html>
