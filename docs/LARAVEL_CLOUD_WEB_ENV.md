# Laravel Cloud — environment for www web client

## Applied (2026-05-19)

| Variable | Status |
|----------|--------|
| `CORS_PRODUCTION_ORIGINS` | **Updated** to `https://www.maphapay.com,https://maphapay.com,https://app.maphapay.com` |
| `WEBAUTHN_ORIGIN` | **Updated** to `https://www.maphapay.com` |
| `FRONTEND_URL` | **Not set** — production environment is at the 200-variable limit |
| `SANCTUM_STATEFUL_DOMAINS` | **Not set** — same limit; web client uses Bearer tokens |

Production was redeployed after the CORS change. Remove an unused variable in Laravel Cloud if you need `FRONTEND_URL` or `SANCTUM_STATEFUL_DOMAINS` explicitly.

## Target values

```env
FRONTEND_URL=https://www.maphapay.com
CORS_PRODUCTION_ORIGINS=https://www.maphapay.com,https://maphapay.com,https://app.maphapay.com
SANCTUM_STATEFUL_DOMAINS=www.maphapay.com,maphapay.com
```

## Verify

- Browser `OPTIONS` preflight from `https://www.maphapay.com` to `https://maphapay.com/api/...` returns `Access-Control-Allow-Origin: https://www.maphapay.com`
- Web login receives Bearer token and subsequent API calls succeed

## Apex marketing redirect

`routes/web.php` redirects `GET https://maphapay.com/` → `https://www.maphapay.com` in production. API (`/api/*`), admin (`/admin`), and `pay.*` are unchanged.
