<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 20px auto; padding: 20px; border: 1px solid #eee; border-radius: 10px; }
        .message-box { background-color: #f8fafc; border-left: 4px solid #000; padding: 15px; margin: 20px 0; font-style: italic; }
        .footer { margin-top: 30px; font-size: 12px; color: #888; text-align: center; border-top: 1px solid #eee; padding-top: 20px; }
        .btn { display: inline-block; padding: 10px 20px; background-color: #000; color: #fff; text-decoration: none; border-radius: 5px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Halo! Anda mendapat pesan baru.</h2>
        <p><strong>{{ $sender->first_name }} {{ $sender->last_name }}</strong> mengirimkan pesan kepada Anda melalui Solher:</p>

        <div class="message-box">
            @if($chatMessage->message)
                "{{ $chatMessage->message }}"
            @else
                <em>(Mengirimkan sebuah lampiran gambar/video)</em>
            @endif
        </div>

        <p style="text-align: center;">
            <a href="{{ config('app.frontend_url') }}/chat-list" class="btn">Balas Pesan Sekarang</a>
        </p>

        <div class="footer">
            Ini adalah email otomatis. Harap tidak membalas langsung ke alamat email ini.
        </div>
    </div>
</body>
</html>
