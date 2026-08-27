<!DOCTYPE html>
<html>

<head>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
        }

        .container {
            max-width: 600px;
            margin: 20px auto;
            padding: 20px;
            border: 1px solid #eee;
            border-radius: 10px;
        }

        .alert-box {
            background-color: #FEF2F2;
            border-left: 4px solid #DC2626;
            padding: 15px;
            margin: 20px 0;
        }

        .btn {
            display: inline-block;
            padding: 10px 20px;
            background-color: #000;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div class="container">
        <h2>Halo Tim Solher Care,</h2>
        <p>Sistem AI kami mendeteksi bahwa seorang pelanggan meminta untuk berbicara langsung dengan staf manusia.</p>

        <div class="alert-box">
            <strong>Detail Pelanggan:</strong><br>
            Nama: {{ $customer->first_name }} {{ $customer->last_name }}<br>
            Email: {{ $customer->email }}
        </div>

        <p style="text-align: center; margin-top:30px;">
            <a href="{{ config('app.url') }}/admin/messages" class="btn">Balas Pesan Sekarang</a>
        </p>
    </div>
</body>

</html>
