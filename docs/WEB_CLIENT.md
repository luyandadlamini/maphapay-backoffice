# MaphaPay Web Client (Browser)

The customer web wallet is the Expo/React Native Web app in **`maphapayrn`**, deployed on **Vercel** at `https://www.maphapay.com`. This Laravel repo hosts the **API** (`https://maphapay.com`), Filament admin, and webhooks on Laravel Cloud.

## API contract

- Same compat REST surface as mobile (`/api/*`, Bearer Sanctum tokens)
- Headers:
  - `Authorization: Bearer {token}`
  - `X-Client-Platform: web`
  - `X-Client-Version: {app version}`
  - `X-Account-Id` when an account context is active
  - `X-Device-ID` / `X-Mobile-Platform: web` for device registration telemetry

## CORS

Configure production web origin(s) in Laravel Cloud:

```env
FRONTEND_URL=https://www.maphapay.com
CORS_PRODUCTION_ORIGINS=https://www.maphapay.com,https://maphapay.com
SANCTUM_STATEFUL_DOMAINS=www.maphapay.com,maphapay.com
```

(`app.maphapay.com` may remain in defaults for transitional previews.)

Local dev origins are in `config/cors.php` (`localhost:8081`, etc.).

`supports_credentials: true` allows future Sanctum cookie sessions; v1 web uses Bearer tokens like mobile.

## Attestation / high-risk actions

Native clients may send App Attest / Play Integrity payloads when `mobile.attestation.enabled` is true.

**Web clients** send `device_type: web` and `attestation_capability_reason: web_client_step_up_only`. `HighRiskActionTrustPolicy` allows these requests without native attestation; **PIN/OTP step-up** remains required at the product layer.

## Card reveal

Mobile loads issuer `reveal_url` in a sandboxed WebView. Web opens the same URL in a **new browser tab** (issuer-hosted). PAN/CVV never pass through MaphaPay JSON APIs.

Ensure issuer reveal pages permit top-level navigation from the web app origin if iframe/CSP restrictions apply.

## Realtime

Browser clients use the same Reverb/Pusher settings as mobile (`EXPO_PUBLIC_REVERB_*`). Verify websocket connectivity from the web origin.

## Fineract / Payment Hub EE

Web must **not** call Fineract or Payment Hub directly. All financial operations go through this product-layer API; adapters handle downstream systems (see `docs/maphapay-product-layer-reset-plan-2026-05-04.md`).

## Related docs

- Mobile repo: `maphapayrn/docs/WEB_APP.md`
- `docs/MOBILE_API_COMPATIBILITY.md`
- `docs/mifos-fineract-adoption-plan-2026-05-04.md`
