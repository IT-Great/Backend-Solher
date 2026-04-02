{{-- <!DOCTYPE html>
<html>

<body style="font-family: Arial, sans-serif; text-align: center; color: #333;">
    <h2>Discover Our Newest Collection!</h2>
    <img src="{{ $product->image }}" alt="{{ $product->name }}"
        style="width: 300px; border-radius: 10px; margin-bottom: 20px;">
    <h3>{{ $product->name }}</h3>
    <p style="color: #666; max-width: 500px; margin: 0 auto;">{{ Str::limit($product->description, 100) }}</p>
    <br>
    <a href="{{ config('app.frontend_url') }}/product/{{ $product->id }}"
        style="background-color: #000; color: #fff; padding: 10px 20px; text-decoration: none; font-weight: bold; border-radius: 5px;">Shop
        Now</a>
    <br><br>
    <p style="font-size: 10px; color: #999;">You received this because you subscribed to Solher.</p>
</body>

</html> --}}

{{-- <!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; text-align: center; color: #333; background-color: #f9f9f9; padding: 30px 10px;">
    <div style="background-color: #ffffff; max-width: 600px; margin: 0 auto; padding: 40px 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <h4 style="letter-spacing: 3px; font-size: 10px; color: #999; text-transform: uppercase; margin-bottom: 5px;">S O L H E R</h4>
        <h2 style="font-size: 24px; margin-top: 0; margin-bottom: 20px;">Discover Our Newest Collection!</h2>

        <p style="color: #666; font-size: 14px; margin-bottom: 40px; line-height: 1.6;">
            We've just added some amazing new pieces to our store. Discover the latest styles designed perfectly for your everyday elegance.
        </p>

        @foreach($products as $product)
            <div style="margin-bottom: 40px; padding-bottom: 30px; border-bottom: 1px solid #f1f1f1;">
                <img src="{{ $product->image }}" alt="{{ $product->name }}" style="width: 100%; max-width: 280px; height: 280px; object-fit: cover; border-radius: 10px; margin-bottom: 15px;">
                <h3 style="font-size: 18px; margin: 0 0 5px 0; text-transform: uppercase; letter-spacing: 1px;">{{ $product->name }}</h3>
                <p style="color: #888; font-size: 13px; margin-bottom: 15px;">
                    Rp {{ number_format($product->discount_price ?? $product->price, 0, ',', '.') }}
                </p>
                <a href="{{ config('app.frontend_url') }}/product/{{ $product->id }}"
                    style="display: inline-block; background-color: #000; color: #fff; padding: 12px 30px; text-decoration: none; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; border-radius: 4px;">
                    Shop Now
                </a>
            </div>
        @endforeach

        <p style="font-size: 10px; color: #aaa; margin-top: 40px;">
            You received this email because you are subscribed to Solher updates.
        </p>
    </div>
</body>
</html> --}}

<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; text-align: center; color: #333; background-color: #f9f9f9; padding: 30px 10px;">
    <div style="background-color: #ffffff; max-width: 600px; margin: 0 auto; padding: 40px 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">

        <h4 style="letter-spacing: 3px; font-size: 10px; color: #999; text-transform: uppercase; margin-bottom: 5px;">S O L H E R</h4>
        <h2 style="font-size: 24px; margin-top: 0; margin-bottom: 20px;">Discover Our Newest Collection!</h2>

        <p style="color: #666; font-size: 14px; margin-bottom: 40px; line-height: 1.6;">
            We've just added some amazing new pieces to our store. Discover the latest styles designed perfectly for your everyday elegance.
        </p>

        @foreach($products as $product)
            <div style="margin-bottom: 40px; padding-bottom: 30px; border-bottom: 1px solid #f1f1f1;">

                <img src="{{ $product->image ?: config('app.url') . '/default-bag-icon.jpg' }}"
                     alt="{{ $product->name }}"
                     style="width: 100%; max-width: 280px; height: 280px; object-fit: cover; border-radius: 10px; margin-bottom: 15px; background-color: #f5f5f5;">

                <h3 style="font-size: 18px; margin: 0 0 5px 0; text-transform: uppercase; letter-spacing: 1px;">{{ $product->name }}</h3>
                <p style="color: #888; font-size: 13px; margin-bottom: 15px;">
                    Rp {{ number_format($product->discount_price ?? $product->price, 0, ',', '.') }}
                </p>
                <a href="{{ config('app.frontend_url') }}/product/{{ $product->id }}"
                    style="display: inline-block; background-color: #000; color: #fff; padding: 12px 30px; text-decoration: none; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; border-radius: 4px;">
                    Shop Now
                </a>
            </div>
        @endforeach

        <p style="font-size: 10px; color: #aaa; margin-top: 40px;">
            You received this email because you are subscribed to Solher updates.
        </p>
    </div>
</body>
</html>
