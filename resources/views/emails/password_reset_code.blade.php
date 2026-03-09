<!DOCTYPE html>
<html>

<body style="font-family: Arial, sans-serif; text-align: center; color: #333;">
    <h2>Password Reset Request</h2>
    <p>You requested to reset your password for your Solher Bag account.</p>
    <p>Please use the following 6-digit verification code to proceed:</p>

    <div
        style="background-color: #f3f4f6; padding: 20px; font-size: 24px; font-weight: bold; letter-spacing: 5px; margin: 20px auto; max-width: 300px; border-radius: 10px;">
        {{ $code }}
    </div>

    <p style="color: #666; font-size: 12px;">This code will expire in 15 minutes.</p>
    <p style="color: #666; font-size: 12px;">If you did not request this, please ignore this email.</p>
</body>

</html>
