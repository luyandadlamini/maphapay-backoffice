{{--
  Demo card reveal page.

  Security boundary: HMAC-signed URL with TTL ≤ 60s.
  - No authentication required (the signed URL IS the auth boundary).
  - PAN displayed here is synthetic demo data, never real.
  - This page lives at /demo-cards/reveal and is whitelisted in mobile originWhitelist.
  - Do NOT add any JavaScript that calls back to MaphaPay servers.

  Per 08-processor-gateway.md §3.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Card Details</title>
    <meta name="robots" content="noindex, nofollow">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0a0f1e;
            color: #ffffff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .card-container {
            background: linear-gradient(135deg, #1a1f3a 0%, #2d3561 100%);
            border-radius: 16px;
            padding: 32px 24px;
            width: 100%;
            max-width: 360px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 28px;
        }

        .demo-badge {
            background: rgba(255, 193, 7, 0.15);
            color: #ffc107;
            border: 1px solid rgba(255, 193, 7, 0.3);
            border-radius: 6px;
            padding: 3px 10px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
        }

        .field-label {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.45);
            letter-spacing: 0.6px;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .field-value {
            font-size: 20px;
            font-weight: 600;
            letter-spacing: 3px;
            color: #ffffff;
            font-variant-numeric: tabular-nums;
            margin-bottom: 24px;
        }

        .field-row {
            display: flex;
            gap: 32px;
        }

        .field-row .field {
            flex: 1;
        }

        .field-row .field-value {
            font-size: 16px;
            letter-spacing: 2px;
        }

        .divider {
            height: 1px;
            background: rgba(255, 255, 255, 0.08);
            margin: 8px 0 24px;
        }

        /* Expired state */
        .expired-container {
            text-align: center;
            padding: 16px 0;
        }

        .expired-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }

        .expired-title {
            font-size: 18px;
            font-weight: 600;
            color: #ff6b6b;
            margin-bottom: 8px;
        }

        .expired-msg {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.55);
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="card-container">
        @if ($expired)
            <div class="expired-container">
                <div class="expired-icon">⏱</div>
                <div class="expired-title">Reveal Expired</div>
                <div class="expired-msg">Close this window and request again from the app.</div>
            </div>
        @else
            <div class="card-header">
                <span style="font-size: 14px; font-weight: 600; color: rgba(255,255,255,0.7);">MaphaPay Card</span>
                <span class="demo-badge">Demo</span>
            </div>

            <div class="field-label">Card Number</div>
            <div class="field-value">{{ $demoFullPan }}</div>

            <div class="divider"></div>

            <div class="field-row">
                <div class="field">
                    <div class="field-label">Expires</div>
                    <div class="field-value">{{ $card->expiry_month ?? '12' }}/{{ $card->expiry_year ?? '29' }}</div>
                </div>
                <div class="field">
                    <div class="field-label">CVV</div>
                    <div class="field-value">{{ $demoCvv }}</div>
                </div>
            </div>

            <div class="field-label">Cardholder</div>
            <div class="field-value" style="letter-spacing: 1px; font-size: 16px;">
                {{ $card->cardholderName ?? 'DEMO USER' }}
            </div>
        @endif
    </div>
</body>
</html>
