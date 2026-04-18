<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .btn { display: inline-block; padding: 12px 24px; background-color: #000; color: #fff; text-decoration: none; border-radius: 8px; font-weight: bold; margin-top: 20px;}
        .item { border-bottom: 1px solid #eee; padding: 15px 0; display: flex; align-items: center;}
    </style>
</head>
<body>
    <div class="container">
        <h2>Hai {{ $user->first_name ?? 'Sahabat Solher' }},</h2>
        <p>Kami melihat ada barang impian yang masih tertinggal di keranjang belanjamu. Stok kami terbatas dan cepat habis, lho!</p>

        <div style="margin-top: 20px; margin-bottom: 20px;">
            @foreach($carts as $cart)
                <div class="item">
                    <div>
                        <strong>{{ $cart->product->name }}</strong><br>
                        <small>Qty: {{ $cart->quantity }} | Harga: Rp {{ number_format($cart->gross_amount, 0, ',', '.') }}</small>
                        @if($cart->color)
                            <br><small>Warna: {{ explode('|', $cart->color)[0] }}</small>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <p>Yuk, selesaikan pesananmu sekarang sebelum kehabisan!</p>

        <a href="https://domain-solher-anda.com/cart" class="btn">Kembali ke Keranjang</a>

        <p style="margin-top: 30px; font-size: 12px; color: #888;">
            Tim Solher
        </p>
    </div>
</body>
</html>
