<!DOCTYPE html>
<html>
<head>
    <title>Peringatan Voucher Kedaluwarsa</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f9f9f9; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background-color: #fff; padding: 40px; border: 1px solid #eee; border-radius: 10px; text-align: center;">
        <h2 style="color: #d9534f;">Jangan Lewatkan Kesempatan Ini!</h2>
        
        <p style="color: #555;">Hai!</p>
        <p style="color: #555;">Kami menyadari Anda belum menggunakan voucher spesial yang kami berikan kemarin. Voucher ini akan kedaluwarsa dalam waktu kurang dari <strong>1 Jam</strong>.</p>

        <div style="margin: 40px 0;">
            <span style="background-color: #111; color: #fff; padding: 15px 35px; font-size: 24px; font-weight: bold; letter-spacing: 5px; border-radius: 4px;">
                {{ $promoCode }}
            </span>
        </div>

        <p style="color: #666; font-size: 14px;">
            Gunakan kode di atas pada halaman <i>Checkout</i> untuk mendapatkan potongan <strong>Rp {{ number_format($discountValue, 0, ',', '.') }}</strong> untuk produk Solher pertama Anda.
        </p>

        <a href="{{ url('/collections') }}" style="display: inline-block; padding: 15px 30px; margin-top: 20px; background-color: #d9534f; color: white; text-decoration: none; font-weight: bold; text-transform: uppercase; letter-spacing: 2px;">
            Belanja Sekarang
        </a>

        <br><br>
        <p style="color: #555; font-size: 12px; margin-top: 40px;">Salam hangat,<br><strong style="color: #111;">Solher Team</strong></p>
    </div>
</body>
</html>