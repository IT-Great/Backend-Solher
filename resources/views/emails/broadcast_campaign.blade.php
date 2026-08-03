<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333; background: #f9f9f9; padding: 20px;}
        .container { max-width: 600px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; }
        .footer { margin-top: 30px; font-size: 11px; color: #888; text-align: center; border-top: 1px solid #eee; padding-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Render HTML yang diketik oleh admin di Vue -->
        {!! $htmlContent !!}

        <div class="footer">
            Email ini dikirimkan ke {{ $subscriberEmail }} karena Anda berlangganan buletin Solher.<br>
            © 2026 Solher Essence. All rights reserved.
        </div>
    </div>
</body>
</html>
