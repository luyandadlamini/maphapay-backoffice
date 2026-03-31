# Mobile API Compatibility -- v2.10.0

Handover document for the mobile team (Expo/React Native, separate repo).

---

## 1. Overview

v2.10.0 adds approximately 30 mobile-facing API endpoints across 4 PRs. Key conventions:

- **Response envelope**: All endpoints return `{ "success": true, "data": {...} }` consistently.
- **Authentication**: Sanctum token-based. Login returns the token in `data.access_token`.
- **Base URL**: All endpoints are prefixed with `/api` unless otherwise noted.

---

## 2. Authentication

| Endpoint | Method | Auth Required | Description |
|----------|--------|---------------|-------------|
| `/auth/login` | POST | No | Returns `{ success: true, data: { user, access_token, token_type } }` |
| `/auth/register` | POST | No | Register a new user account |
| `/auth/me` | GET | Yes | Returns user profile (alias of `/auth/user`) |
| `/auth/delete-account` | POST | Yes | Soft deletes the user account |
| `/auth/passkey/register` | POST | Yes | Register a passkey for the authenticated user |
| `/auth/passkey/challenge` | POST | No | Request a passkey authentication challenge |
| `/auth/passkey/verify` | POST | No | Verify a passkey authentication response |
| `/v1/auth/passkey/challenge` | POST | No | v1 prefix alias for passkey challenge |
| `/v1/auth/passkey/authenticate` | POST | No | v1 prefix alias for passkey verify |

---

## 3. Wallet API

**Prefix**: `/api/v1/wallet/`
**Auth**: All endpoints require `auth:sanctum`.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/tokens` | GET | List supported tokens (USDC, USDT, WETH, WBTC) with network and decimals info |
| `/balances` | GET | ERC-20 balances across the user's smart accounts |
| `/state` | GET | Aggregate of balances, addresses, and sync info |
| `/addresses` | GET | List user's smart account addresses per network |
| `/transactions` | GET | Cursor-based transaction history. Query params: `?cursor=X&limit=Y` |
| `/transactions/{id}` | GET | Single transaction detail |
| `/transactions/send` | POST | Create and auto-submit a payment intent. Body: `{ to, amount, asset, network }` |

---

## 4. TrustCert API

**Prefix**: `/api/v1/trustcert/`
**Auth**: All endpoints require `auth:sanctum`.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/current` | GET | User's current trust level and certificate info |
| `/requirements` | GET | All trust levels with their requirements |
| `/requirements/{level}` | GET | Requirements for a specific trust level |
| `/limits` | GET | Transaction limits per trust level |
| `/check-limit` | POST | Check if an amount is within the user's limits. Body: `{ amount, transaction_type }` |
| `/applications` | POST | Create a certificate application. Body: `{ target_level }` |
| `/applications/current` | GET | Current active application |
| `/applications/{id}` | GET | Application by ID |
| `/applications/{id}/documents` | POST | Upload a document. Body: `{ document_type, file_name }` |
| `/applications/{id}/submit` | POST | Submit application for review |
| `/applications/{id}/cancel` | POST | Cancel a pending application |

**Trust levels**: `unknown`, `basic`, `verified`, `high`, `ultimate`

---

## 5. Commerce API

**Prefix**: `/api/v1/commerce/`
**Auth**: All endpoints require `auth:sanctum`.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/merchants` | GET | List available merchants |
| `/parse-qr` | POST | Parse a merchant QR code. Body: `{ qr_data }` |
| `/payment-requests` | POST | Create a payment request. Body: `{ merchant_id, amount, asset, network }` |
| `/payments` | POST | Process a payment. Body: `{ payment_request_id }` |
| `/generate-qr` | POST | Generate a payment QR code. Body: `{ amount, asset, network }` |

**QR format**: `finaegis://pay?merchant=X&amount=Y&asset=Z&network=N`

---

## 6. Relayer API

**Prefix**: `/api/v1/relayer/`
**Auth**: All endpoints require `auth:sanctum`.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/status` | GET | Relayer health and gas prices per network |
| `/estimate-gas` | POST | Estimate gas. Body: `{ network, to, data? }` |
| `/build-userop` | POST | Build a UserOperation. Body: `{ network, to, value?, data? }` |
| `/submit` | POST | Submit a signed UserOp. Body: `{ network, user_op, signature }` |
| `/userop/{hash}` | GET | UserOp status by hash |
| `/supported-tokens` | GET | Tokens accepted for gas payment |
| `/paymaster-data` | GET | Paymaster configuration per network |

**Supported networks**: `polygon`, `arbitrum`, `optimism`, `base`, `ethereum`

---

## 7. Existing Endpoints (unchanged, already working)

These endpoints were available before v2.10.0 and remain unchanged:

- **Mobile device management**: `/api/mobile/devices/*`
- **Biometric auth**: `/api/mobile/auth/biometric/*`
- **Push notifications**: `/api/mobile/notifications/*`
- **Payment intents**: `/api/v1/payments/intents/*`
- **Activity feed**: `/api/v1/activity`
- **Transaction details**: `/api/v1/transactions/{txId}`
- **Receipt**: GET/POST `/api/v1/transactions/{txId}/receipt`
- **Network status**: `/api/v1/networks/status`, `/api/v1/networks/{network}/status`
- **Wallet receive**: `/api/v1/wallet/receive`
- **Wallet transfer helpers**: `/api/v1/wallet/validate-address`, `/api/v1/wallet/resolve-name`, `/api/v1/wallet/quote`
- **Cards**: `/api/v1/cards/*`
- **Relayer (existing)**: `/api/v1/relayer/networks`, `/api/v1/relayer/sponsor`, `/api/v1/relayer/estimate`
- **Smart accounts**: `/api/v1/relayer/account`, `/api/v1/relayer/accounts`
- **TrustCert presentations**: `/api/v1/trustcert/{certId}/*`
- **Privacy**: `/api/v1/privacy/*`
- **RegTech**: `/api/regtech/*`

---

## 8. Error Format

All errors follow a consistent envelope:

```json
{
  "success": false,
  "error": {
    "code": "ERROR_CODE",
    "message": "Human-readable message."
  }
}
```

**HTTP status codes**:
- `404` -- Not found
- `409` -- Conflict
- `422` -- Validation error

---

## 9. CORS

The following custom headers are allowed in CORS configuration:

- `X-Client-Platform`
- `X-Client-Version`

---

## 10. Known Issues

- **Cache permissions**: Run `sudo chown -R www-data:www-data storage/framework/cache/data/` if you encounter permission errors on the server.
- **Demo mode**: Commerce merchants and some wallet data are demo/placeholder values.

---

## 11. MaphaPay Compat Layer Endpoints (Phase 18+)

These endpoints live in `routes/api-compat.php` and are gated by feature flags (`MAPHAPAY_MIGRATION_ENABLE_*`). They replace the equivalent legacy MaphaPay backend routes one-for-one at the URL level.

### Design principle

> **The backend is the single source of truth for field names and data shapes.**
>
> The compat layer translates *URL paths* only — it does not translate field names. Mobile clients must read the backend's domain vocabulary directly. If a screen was reading a legacy field name (e.g. `trx_type`, `remark`, `trx`), update the mobile hook to read the canonical name instead.

### Standard response envelope

```json
{ "status": "success", "remark": "<endpoint-name>", "data": { ... } }
```

Error:
```json
{ "status": "error", "message": "Human-readable message.", "data": {} }
```

---

### GET /api/transactions

Returns paginated transaction history for the authenticated user.

**Query params**

| Param | Type | Description |
|-------|------|-------------|
| `page` | int | Page number (default 1) |
| `type` | string | Filter by `deposit`, `withdrawal`, or `transfer` |
| `subtype` | string | Filter by subtype e.g. `send_money`, `request_money` |
| `search` | string | Full-text match on `description` or `reference` |

**Response**

```json
{
  "status": "success",
  "remark": "transactions",
  "data": {
    "transactions": {
      "data": [
        {
          "id": "uuid",
          "reference": "REF-001",
          "description": "Payment from Alice",
          "amount": "10.50",
          "type": "deposit",
          "subtype": "send_money",
          "asset_code": "SZL",
          "created_at": "2026-03-28T10:00:00+00:00"
        }
      ],
      "current_page": 1,
      "last_page": 3,
      "next_page_url": "...",
      "total": 42
    },
    "subtypes": ["send_money", "request_money"]
  }
}
```

**Field name mapping (legacy → canonical)**

| Legacy (do not use) | Canonical |
|---------------------|-----------|
| `trx` | `reference` |
| `trx_type` (`+`/`-`) | `type` (`deposit`/`withdrawal`/`transfer`) |
| `remark` | `subtype` |
| `details` | `description` |
| `remarks` (filter list) | `subtypes` |
| `?remark=` (filter param) | `?subtype=` |

---

### GET /api/dashboard

Returns the user's profile and wallet balance. Cached 30 s per user.

**Response**

```json
{
  "status": "success",
  "remark": "dashboard",
  "data": {
    "user": {
      "id": 1,
      "email": "user@example.com",
      "mobile": null,
      "balance": "250.00"
    },
    "balance": "250.00",
    "offers": []
  }
}
```

`balance` is the SZL wallet balance as a major-unit decimal string. `mobile` is `null` until a mobile-phone column is added to the users table.

---

### POST /api/send-money/store

Initiates an OTP-gated peer send. Returns an `authorized_transaction` reference and dispatches OTP.

**Body**: `{ recipient_id, amount, note? }` — `amount` is a major-unit decimal string (e.g. `"25.00"`).

---

### POST /api/request-money/store

Creates a money request. Does not move funds; awaits recipient acceptance.

---

### POST /api/request-money/received-store

Accepts a pending money request (recipient initiates transfer). Requires OTP/PIN.

---

### POST /api/request-money/reject/{moneyRequest}

Rejects a pending money request.

---

### GET /api/request-money/history

Paginated list of requests the authenticated user *sent*.

### GET /api/request-money/received-history

Paginated list of requests the authenticated user *received*.

---

### POST /api/verification-process/verify/otp

Verifies the OTP for a pending `authorized_transaction`. For scheduled sends, sets `verification_confirmed_at` without executing the transfer immediately.

### POST /api/verification-process/verify/pin

Same as OTP verify but uses the user's transaction PIN.

---

### MTN MoMo (`/api/mtn/*`)

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/api/mtn/request-to-pay` | POST | Required | Initiate MoMo collection (credits wallet on success) |
| `/api/mtn/disbursement` | POST | Required | Debit wallet and push funds to MoMo number |
| `/api/mtn/transaction/{referenceId}/status` | GET | Required | Poll transaction status |
| `/api/mtn/callback` | POST | None (token header) | IPN from MTN; verified via `X-Callback-Token` |

---

### KYC (MaphaPay compat — multi-step form)

These endpoints use the same **standard compat envelope** as section 11 (`status`, **`remark`**, `data`). The mobile client should treat a missing or unexpected `remark` as a failed/unknown response when using shared compat parsing.

| Endpoint | Method | Auth | `remark` (success) | Description |
|----------|--------|------|--------------------|-------------|
| `/api/kyc-form` | GET | Sanctum | `kyc_form` | Returns `data.form_available`, `data.message`, `data.progress`, `data.steps`, and when applicable `data.current_step_form` (fields for the active step). |
| `/api/kyc-submit` | POST | Sanctum | `kyc_submit` | Submits the current step (multipart for uploads). Success and error bodies both include `remark: kyc_submit`. |

**FinAegis-native KYC (separate from MaphaPay compat):**

- **Ondato SDK flow** (documented for mobile): `docs/ondato/mobile-integration-guide.md` — `POST /api/compliance/kyc/ondato/start`, status polling, webhooks.
- **Compliance API v2** (OpenAPI): `/api/v2/compliance/kyc/status`, `POST /api/v2/compliance/kyc/start`, document/selfie uploads on a verification id — see `ComplianceController` and domain compliance routes.

The MaphaPay app should use **either** the compat multi-step form (`/api/kyc-form` + `/api/kyc-submit`) **or** the Ondato/v2 flows — not mix envelope parsers between them.

---

## 12. What Mobile Should Update

The following are breaking or notable changes that the mobile app should account for:

- **Auth envelope change**: Auth endpoints now return `{ success, data }` envelope. Previously `/auth/login` and `/auth/user` returned flat objects.
- **Passkey path aliases**: Passkey endpoints now work at both `/api/auth/passkey/*` and `/api/v1/auth/passkey/*`.
- **Receipt GET endpoint**: `GET /api/v1/transactions/{txId}/receipt` is now available. Previously only POST was supported.
- **Parameterized network status**: Network status now supports a parameterized path: `/api/v1/networks/{network}/status`.
