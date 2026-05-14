{{--
  Stripe Issuing card reveal page.

  Security boundary: this page and its ephemeral-key endpoint require a
  short-lived Laravel signed URL minted after the mobile reveal step-up.
  Sensitive card data renders only inside Stripe-hosted iframes.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Card Details</title>
    <meta name="robots" content="noindex, nofollow">
    <script src="https://js.stripe.com/v3/"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: #080d19;
            color: #ffffff;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        .card-container {
            width: 100%;
            max-width: 360px;
            padding: 30px 24px;
            border: 1px solid rgba(255, 255, 255, 0.10);
            border-radius: 16px;
            background: linear-gradient(135deg, #151b2c 0%, #24314d 100%);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.45);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
        }

        .brand {
            color: rgba(255, 255, 255, 0.74);
            font-size: 14px;
            font-weight: 650;
        }

        .badge {
            border: 1px solid rgba(42, 200, 162, 0.35);
            border-radius: 6px;
            padding: 3px 10px;
            background: rgba(42, 200, 162, 0.14);
            color: #56e2bd;
            font-size: 11px;
            font-weight: 750;
            letter-spacing: 0.8px;
            text-transform: uppercase;
        }

        .field-label {
            margin-bottom: 7px;
            color: rgba(255, 255, 255, 0.48);
            font-size: 11px;
            letter-spacing: 0.6px;
            text-transform: uppercase;
        }

        .element-frame {
            min-height: 24px;
            margin-bottom: 24px;
            color: #ffffff;
        }

        .divider {
            height: 1px;
            margin: 8px 0 24px;
            background: rgba(255, 255, 255, 0.10);
        }

        .field-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        .status {
            color: rgba(255, 255, 255, 0.64);
            font-size: 14px;
            line-height: 1.5;
        }

        .status.error { color: #ff8b8b; }
    </style>
</head>
<body>
    <main class="card-container">
        <div class="card-header">
            <span class="brand">MaphaPay Card</span>
            <span class="badge">Stripe</span>
        </div>

        <div id="status" class="status">Loading secure card details...</div>

        <section id="details" hidden>
            <div class="field-label">Card Number</div>
            <div id="card-number" class="element-frame"></div>

            <div class="divider"></div>

            <div class="field-row">
                <div>
                    <div class="field-label">Expires</div>
                    <div id="card-expiry" class="element-frame"></div>
                </div>
                <div>
                    <div class="field-label">CVV</div>
                    <div id="card-cvc" class="element-frame"></div>
                </div>
            </div>
        </section>
    </main>

    <script>
        const stripePublishableKey = @json($stripePublishableKey);
        const stripeCardId = @json($stripeCardId);
        const ephemeralKeyUrl = @json($ephemeralKeyUrl);
        const statusElement = document.getElementById('status');
        const detailsElement = document.getElementById('details');

        const elementStyle = {
            base: {
                color: '#ffffff',
                fontSize: '18px',
                fontWeight: '600',
                letterSpacing: '2px',
                fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
            },
        };

        function showError(message) {
            statusElement.textContent = message;
            statusElement.className = 'status error';
            detailsElement.hidden = true;
        }

        async function fetchEphemeralKey(stripe, cardId) {
            const nonceResult = await stripe.createEphemeralKeyNonce({ issuingCard: cardId });
            if (nonceResult.error) {
                throw new Error(nonceResult.error.message || 'Could not initialize secure card view.');
            }

            const response = await fetch(ephemeralKeyUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    card_id: cardId,
                    nonce: nonceResult.nonce,
                }),
            });

            if (!response.ok) {
                throw new Error('This reveal link has expired. Close this view and reveal again.');
            }

            const payload = await response.json();
            if (!payload.ephemeralKeySecret) {
                throw new Error('Secure card details are unavailable.');
            }

            return {
                nonce: nonceResult.nonce,
                ephemeralKeySecret: payload.ephemeralKeySecret,
            };
        }

        async function mountCardDetails() {
            if (!stripePublishableKey) {
                showError('Stripe publishable key is not configured.');
                return;
            }

            const stripe = Stripe(stripePublishableKey);
            const elements = stripe.elements();
            const credentials = await fetchEphemeralKey(stripe, stripeCardId);

            await stripe.retrieveIssuingCard(stripeCardId, {
                ephemeralKeySecret: credentials.ephemeralKeySecret,
                nonce: credentials.nonce,
            });

            const options = {
                issuingCard: stripeCardId,
                nonce: credentials.nonce,
                ephemeralKeySecret: credentials.ephemeralKeySecret,
                style: elementStyle,
            };

            elements.create('issuingCardNumberDisplay', options).mount('#card-number');
            elements.create('issuingCardExpiryDisplay', options).mount('#card-expiry');
            elements.create('issuingCardCvcDisplay', options).mount('#card-cvc');

            statusElement.hidden = true;
            detailsElement.hidden = false;
        }

        mountCardDetails().catch((error) => {
            showError(error instanceof Error ? error.message : 'Secure card details are unavailable.');
        });
    </script>
</body>
</html>
