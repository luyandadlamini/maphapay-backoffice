# Why `POST /api/send-money/store` Returns HTTP 428

The 428 is always produced by `HighRiskActionTrustPolicy::evaluate()` inside the
controller — never by middleware. There is exactly **one cause**: the trust policy
returns `decision = 'step_up'` or `decision = 'degrade'`, meaning the device has
not proven trustworthiness to the backend's satisfaction.

---

## Route Definition

`routes/api-compat.php` lines 111–113:

```php
Route::middleware(['migration_flag:enable_send_money', 'kyc_approved', 'idempotency', 'throttle:maphapay-send-money'])
    ->post('send-money/store', SendMoneyStoreController::class)
    ->name('maphapay.compat.send-money.store');
```

None of the four middleware classes return 428. Their exit codes:

| Middleware | Returns |
|---|---|
| `migration_flag:enable_send_money` | 404 if flag is off |
| `kyc_approved` | 403 if KYC not approved |
| `idempotency` | 400 (bad key format) or 409 (conflict/in-progress) |
| `throttle` | 429 |

---

## The Two 428 Sites in the Controller

### Site 1 — Minor-account guardian-approval path
`SendMoneyStoreController.php` lines 229–239:

```php
if (in_array(($trust['decision'] ?? ''), ['step_up', 'degrade'], true)) {
    return response()->json([
        'success' => false,
        'error'   => [
            'code'            => 'TRUST_POLICY_STEP_UP',
            'message'         => 'Additional verification is required by mobile trust policy.',
            'trust_decision'  => $trust['decision'],
            'trust_reason'    => $trust['reason'] ?? 'policy',
            'trust_record_id' => $trust['record_id'] ?? null,
        ],
    ], 428);
}
```

### Site 2 — Main path (all non-minor accounts)
`SendMoneyStoreController.php` lines 356–366:

```php
if (in_array(($trust['decision'] ?? ''), ['step_up', 'degrade'], true)) {
    return response()->json([
        'success' => false,
        'error'   => [
            'code'            => 'TRUST_POLICY_STEP_UP',
            'message'         => 'Additional verification is required by mobile trust policy.',
            'trust_decision'  => $trust['decision'],
            'trust_reason'    => $trust['reason'] ?? 'policy',
            'trust_record_id' => $trust['record_id'] ?? null,
        ],
    ], 428);
}
```

Both sites share the same JSON shape. `trust_record_id` is a UUID pointing to the
`MobileAttestationRecord` row created for this evaluation.

---

## What Triggers `step_up` / `degrade`

Full file: `app/Domain/Mobile/Services/HighRiskActionTrustPolicy.php`

Core decision logic (lines 43–72):

```php
$decision = 'allow';
$reason   = 'attestation_disabled';

if ($attestationEnabled) {                      // config('mobile.attestation.enabled')
    if ($attestation === '') {
        $decision = 'deny';                     // → 403
        $reason   = 'attestation_required';
    } elseif (! in_array($deviceType, ['ios', 'android'], true)) {
        $decision = 'deny';                     // → 403
        $reason   = 'unsupported_device_type';
    } else {
        $attestationVerified = $this->biometricJwtService->verifyDeviceAttestation($attestation, $deviceType);
        if (! $attestationVerified) {
            $decision = 'deny';                 // → 403
            $reason   = 'attestation_failed';
        } else {
            $decision = 'allow';                // → proceeds normally
            $reason   = 'attestation_verified';
        }
    }
} elseif (! $attestationCountsAsTrustProof && ! $deviceTrusted) {
    // Attestation is DISABLED in config, and device is also untrusted
    $decision = 'degrade';                      // → 428
    $reason   = $devicePostureStatus === 'simulator_or_emulator'
        ? 'device_posture_untrusted'
        : 'attestation_disabled_device_untrusted';
}
```

### The 428 path specifically

`degrade` (→ 428) is returned when **all three** of these are true simultaneously:

1. `config('mobile.attestation.enabled')` is **`false`**
2. `attestationCountsAsTrustProof` is **`false`** — either no `attestation` token was
   sent, or `attestation_capability_mode` is `"none"` or `"runtime-posture"`
3. `deviceTrusted` is **`false`** — the `device_id` / `X-Device-ID` was not found in
   the `mobile_devices` table with `is_trusted = true`

> **Note:** `step_up` is checked in the controller but never actually set by the
> policy. In practice every 428 has `trust_decision: "degrade"`.

---

## Decision Matrix

| Condition | `decision` | HTTP |
|---|---|---|
| Attestation enabled, no token sent | `deny` | 403 |
| Attestation enabled, bad device type | `deny` | 403 |
| Attestation enabled, JWT verification fails | `deny` | 403 |
| Attestation enabled, JWT verification passes | `allow` | — (proceeds) |
| Attestation **disabled**, device trusted in DB | `allow` | — (proceeds) |
| Attestation **disabled**, device **untrusted**, no valid attestation | **`degrade`** | **428** |

---

## 428 Response Body

```json
{
  "success": false,
  "error": {
    "code": "TRUST_POLICY_STEP_UP",
    "message": "Additional verification is required by mobile trust policy.",
    "trust_decision": "degrade",
    "trust_reason": "attestation_disabled_device_untrusted",
    "trust_record_id": "<uuid of MobileAttestationRecord>"
  }
}
```

---

## Request Fields Consumed by the Trust Policy

These are **not** in the route's validation rules — they are optional but
behaviorally significant:

| Request field | Header fallback | Effect |
|---|---|---|
| `attestation` | — | The device-attestation JWT token |
| `device_type` | `X-Mobile-Platform` | Must be `"ios"` or `"android"` for attestation path |
| `device_id` | `X-Device-ID` | Used to look up `mobile_devices.is_trusted` |
| `attestation_capability_mode` | — | `"none"` or `"runtime-posture"` disables trust proof |
| `device_posture_status` | — | `"simulator_or_emulator"` changes the reason string |

---

## Root Cause in One Sentence

When `mobile.attestation.enabled` is `false` **and** the device has never been
registered as trusted in the `mobile_devices` table (or sends no `device_id`),
every send-money attempt receives a `degrade` decision and a **428**.
