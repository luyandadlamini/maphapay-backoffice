<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MaphaPay Payment</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #0F1120; color: #fff; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); border-radius: 20px; padding: 32px; max-width: 380px; width: 100%; text-align: center; }
        .logo { font-size: 22px; font-weight: 700; color: #60A5FA; margin-bottom: 24px; }
        .avatar { width: 72px; height: 72px; border-radius: 50%; background: rgba(96,165,250,0.2); margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; font-size: 28px; overflow: hidden; }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .name { font-size: 20px; font-weight: 600; margin-bottom: 8px; }
        .amount { font-size: 32px; font-weight: 700; color: #60A5FA; margin: 16px 0 8px; }
        .note { font-size: 14px; color: rgba(255,255,255,0.6); margin-bottom: 24px; }
        .cta { display: block; width: 100%; padding: 16px; border-radius: 12px; font-size: 16px; font-weight: 600; text-decoration: none; border: none; cursor: pointer; margin-bottom: 12px; }
        .cta-primary { background: #60A5FA; color: #0F1120; }
        .cta-store { background: rgba(255,255,255,0.08); color: #fff; }
        .expired { color: rgba(255,255,255,0.5); font-size: 14px; margin-top: 24px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">MaphaPay</div>

        @if($found)
            <div class="avatar">
                @if($avatar_url)
                    <img src="{{ $avatar_url }}" alt="">
                @else
                    💳
                @endif
            </div>
            <div class="name">Pay {{ $display_name }}</div>
            @if($amount)
                <div class="amount">SZL {{ number_format((float) $amount, 2) }}</div>
            @endif
            @if($note)
                <div class="note">"{{ $note }}"</div>
            @endif

            @if($deep_link)
                <a href="{{ $deep_link }}" class="cta cta-primary" id="openApp">Open in MaphaPay</a>
            @endif
        @else
            <div class="name">This link is no longer active</div>
            <p class="expired">The payment request has already been completed or has expired.</p>
        @endif

        <a href="https://apps.apple.com/app/maphapay/id0000000000" class="cta cta-store">Download for iOS</a>
        <a href="https://play.google.com/store/apps/details?id=com.maphapay.app" class="cta cta-store">Download for Android</a>
    </div>

    @if($found && $deep_link)
    <script>
        window.location.href = "{{ $deep_link }}";
    </script>
    @endif
</body>
</html>
