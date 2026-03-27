<!DOCTYPE html>
<html>
<head>
    <title>SolHer Promo Code</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f9f9f9; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background-color: #fff; padding: 40px; border: 1px solid #eee; border-radius: 10px;">
        <h2 style="text-align: center; letter-spacing: 4px; color: #111;">S O L H E R</h2>
        <hr style="border: none; border-top: 1px solid #eaeaea; margin: 30px 0;">

        <h3 style="color: #222;">Hello there!</h3>
        <p style="color: #555;">Thank you for subscribing to SolHer. As promised, here is your exclusive promo code for your first order:</p>

        <div style="text-align: center; margin: 40px 0;">
            <span style="background-color: #111; color: #fff; padding: 15px 35px; font-size: 24px; font-weight: bold; letter-spacing: 5px; border-radius: 4px;">
                {{ $promoCode }}
            </span>
        </div>

        <p style="text-align: center; color: #666; font-size: 14px;">
            Use this code at checkout to get <strong>Rp {{ number_format($discountValue, 0, ',', '.') }} OFF</strong>.
        </p>

        <br>
        <p style="color: #555;">Happy Shopping,<br><strong style="color: #111;">SolHer Team</strong></p>
    </div>
</body>
</html>
