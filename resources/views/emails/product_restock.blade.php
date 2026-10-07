<!DOCTYPE html>
<html>

<head>
    <title>Produk Kembali Tersedia!</title>
</head>

<body
    style="font-family: Arial, sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #000; text-transform: uppercase;">{{ $product->name }} Kembali Restock! 🔥</h2>

    <p>Halo Solher Babes,</p>
    <p>Kabar gembira untuk Anda! Produk yang sempat <strong>Sold Out</strong> kini telah kembali tersedia di katalog
        kami.</p>

    <div style="background-color: #f9f9f9; border-left: 4px solid #000; padding: 15px; margin: 20px 0;">
        <h3 style="margin-top: 0;">{{ $product->name }}</h3>
        <p style="margin-bottom: 0;">Kami baru saja menambahkan <strong>{{ $addedQuantity }} pcs</strong> stok baru.</p>
    </div>

    <p style="color: #d32f2f; font-weight: bold; font-size: 13px;">
        Segera dapatkan sebelum kehabisan lagi!
    </p>

    <p>
        <a href="{{ url(env('FRONTEND_URL', 'http://localhost:5173') . '/products/' . $product->slug) }}"
            style="background-color: #000; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;">
            Beli Sekarang
        </a>
    </p>
</body>

</html>
