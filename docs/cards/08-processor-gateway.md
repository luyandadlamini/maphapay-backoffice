# 08 — Processor Gateway and PCI Scope

How CardSubscriptions integrates with the existing `CardIssuance` processor abstraction, and the rules for handling card data securely.

References:
- Existing interface: `app/Domain/CardIssuance/Contracts/CardIssuerInterface.php`
- Existing adapters: `app/Domain/CardIssuance/Adapters/DemoCardIssuerAdapter.php`, `RainCardIssuerAdapter.php`
- Webhook routes: `app/Domain/CardIssuance/Routes/api.php`

---

## 1. PCI scope statement

**MaphaPay is NOT a PCI Level 1 merchant.** PAN and CVV NEVER touch our servers. Every card-data flow that involves PAN/CVV is delegated to the issuer (Rain, Marqeta) via:

1. **Card creation** — issuer returns only `issuer_card_token`, `last4`, `expiry_month`, `expiry_year`, `card_brand`. Full PAN stays in issuer's PCI environment.
2. **Reveal** — issuer hosts a reveal page; we mint a signed URL with TTL ≤ 60s; mobile loads it in a sandboxed webview. The PAN appears in the issuer's iframe, not in our HTML or our JS.
3. **Provisioning to wallets (Apple Pay / Google Pay)** — uses the issuer's push-provisioning API; PAN is encrypted by the issuer, sent directly to the wallet provider, never touches our backend in clear.
4. **Authorization decisions** — we receive merchant + amount + MCC, never PAN.

This stance is a hard rule. Adding a code path that:

- Stores PAN in any DB column
- Logs PAN in any log line, error report, or breadcrumb
- Returns PAN in any API response (other than the reveal URL itself)
- Sends PAN to any third party (analytics, monitoring, support tools)

…is a **CRITICAL security defect** and must be reverted. CI checks (see §11) enforce this.

---

## 2. CardIssuerInterface contract

The existing interface stays. One new method is added:

```php
namespace App\Domain\CardIssuance\Contracts;

interface CardIssuerInterface
{
    // ... existing methods ...
    public function createVirtualCard(User $user, array $payload): ProcessorCardResult;
    public function createPhysicalCard(User $user, array $payload): ProcessorCardResult;
    public function freezeCard(string $issuerCardToken): void;
    public function unfreezeCard(string $issuerCardToken): void;
    public function cancelCard(string $issuerCardToken): void;

    // NEW: mint a short-TTL signed URL for the issuer's reveal page
    public function generateRevealUrl(string $issuerCardToken, int $ttlSeconds): RevealUrlResult;

    // NEW: open a chargeback/dispute with the issuer
    public function openDispute(string $processorTransactionId, DisputePayload $payload): ProcessorDisputeResult;

    // Webhooks (called from controllers; here for adapter helpers)
    public function verifyWebhookSignature(string $rawBody, string $signature): bool;
}
```

`RevealUrlResult`:

```php
final class RevealUrlResult
{
    public function __construct(
        public readonly string $url,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly int $ttlSeconds,
    ) {}
}
```

---

## 3. Demo adapter (development)

`app/Domain/CardIssuance/Adapters/DemoCardIssuerAdapter.php` — already exists. Extend with:

```php
public function generateRevealUrl(string $issuerCardToken, int $ttlSeconds): RevealUrlResult
{
    // The demo "reveal page" is a small Blade view served at /demo-cards/reveal
    // It accepts a HMAC-signed token in the query string, validates expiry, and renders.
    $expiresAt = now()->addSeconds($ttlSeconds);
    $payload = json_encode([
        'token' => $issuerCardToken,
        'exp' => $expiresAt->timestamp,
    ]);
    $sig = hash_hmac('sha256', $payload, config('cards.processors.demo.reveal_secret'));
    $b64 = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    $url = url("/demo-cards/reveal?p={$b64}&sig={$sig}");

    return new RevealUrlResult($url, $expiresAt->toDateTimeImmutable(), $ttlSeconds);
}
```

The demo reveal page (Blade view at `resources/views/demo-cards/reveal.blade.php`) renders:

```blade
{{-- intentionally minimal; this is what the mobile webview loads --}}
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Card details</title>
    <style>/* basic styles */</style>
</head>
<body>
    @if ($expired)
        <p>Reveal expired. Close this window and request again.</p>
    @else
        {{-- Demo PANs are stored in the demo adapter's in-memory cache, never in the DB. --}}
        <h1>**** **** **** {{ $card->last4 }}</h1>
        <p>Demo full PAN: {{ $demoFullPan }}</p>
        <p>CVV: {{ $demoCvv }}</p>
        <p>Expires: {{ $card->expiry_month }}/{{ $card->expiry_year }}</p>
    @endif
</body>
</html>
```

The view does NOT require auth — the HMAC + TTL is the security boundary. The view is served from the same domain as the mobile webview (whitelisted in `originWhitelist`).

---

## 4. Rain adapter (production)

`app/Domain/CardIssuance/Adapters/RainCardIssuerAdapter.php` — already exists. Extend with:

```php
public function generateRevealUrl(string $issuerCardToken, int $ttlSeconds): RevealUrlResult
{
    // Rain provides POST /v1/cards/{token}/reveal-link returning {url, expires_at}
    $response = $this->client()->post("/v1/cards/{$issuerCardToken}/reveal-link", [
        'json' => ['ttl_seconds' => $ttlSeconds],
    ]);
    $body = json_decode((string) $response->getBody(), true, JSON_THROW_ON_ERROR);

    return new RevealUrlResult(
        url: $body['url'],
        expiresAt: new \DateTimeImmutable($body['expires_at']),
        ttlSeconds: $ttlSeconds,
    );
}
```

The actual API path / payload depend on the chosen processor's docs — the Rain example above is illustrative. The contract (return shape, TTL semantics) is what's locked.

Webhook signature verification:

```php
public function verifyWebhookSignature(string $rawBody, string $signature): bool
{
    $expected = hash_hmac('sha256', $rawBody, config('cards.processors.rain.webhook_secret'));
    return hash_equals($expected, $signature);
}
```

Use `hash_equals` (constant-time) to prevent timing attacks.

---

## 5. Webhook flow

**Endpoints:**

```
POST /webhooks/cards/{processor}/authorisation
POST /webhooks/cards/{processor}/clearing
POST /webhooks/cards/{processor}/reversal
POST /webhooks/cards/{processor}/refund
```

Routes registered in `app/Domain/CardIssuance/Routes/api.php` (existing file). Handler is `CardWebhookController` (existing).

**Required ordering inside the controller:**

1. Read raw body BEFORE Laravel parses JSON (`$request->getContent()`).
2. Read signature header (per processor: Rain uses `X-Rain-Signature`, etc.).
3. `$adapter->verifyWebhookSignature($rawBody, $sig)` — return `401 Unauthorized` on mismatch. NO parsing, NO logging the body.
4. Parse JSON.
5. `processor_event_id = $payload['event_id']`. Lookup in `card_audit_logs` for `action = 'processor.webhook_received' AND metadata->event_id = $processor_event_id`. If exists → return `200 OK` (idempotent replay).
6. Persist raw body in `card_audit_logs.metadata` (BEFORE state mutation):

   ```php
   CardAuditService::record(
       actorType: 'processor',
       actorId: null,
       action: 'processor.webhook_received',
       entityType: 'processor_event',
       entityId: $processorEventId,
       beforeState: null,
       afterState: null,
       metadata: ['raw_body' => $rawBody, 'processor' => $processor, 'event_type' => $eventType],
   );
   ```

7. Dispatch the corresponding job (`HandleAuthorisationWebhookJob`, etc.) with `($processor, $payload)`. Return `200 OK`.

The job applies state mutations in a transaction. If the job fails, the webhook is replayable by the processor (most processors retry on non-200). The audit row is written before mutation, so we always have evidence of receipt even if processing fails.

---

## 6. Authorization webhook handling

Pseudo-code for `HandleAuthorisationWebhookJob::handle()`:

```php
DB::transaction(function () use ($payload) {
    $card = Card::where('issuer_card_token', $payload['card_token'])->lockForUpdate()->firstOrFail();

    $authReq = AuthorizationRequest::fromProcessorPayload($payload);

    // 1. Entitlement check (subscription, limits, controls)
    $entitlement = app(CardEntitlementService::class)->canAuthorize($card, $authReq);
    if (!$entitlement->allowed) {
        $this->respondToProcessor($payload, decline: $entitlement->code->value);
        $this->persistDeclined($card, $authReq, $entitlement->code->value);
        return;
    }

    // 2. Risk check
    $risk = app(CardRiskService::class)->evaluateAuthorization($card, $authReq);
    if (!$risk->allowed) {
        $this->respondToProcessor($payload, decline: $risk->code->value);
        $this->persistDeclined($card, $authReq, $risk->code->value);
        return;
    }

    // 3. Calculate fees (FX, ATM)
    $fxFee = app(CardFeeService::class)->calculateFxFee($card->plan(), $authReq->currency, $authReq->billingAmount);
    $atmFee = $authReq->isAtm() ? app(CardFeeService::class)->calculateAtmFee($card->plan(), $authReq->amount) : Money::zero('SZL');
    $totalDebit = $authReq->billingAmount->add($fxFee)->add($atmFee);

    // 4. Wallet balance check
    $wallet = WalletService::getWallet($card->user_id);
    if ($wallet->available_balance->lt($totalDebit)) {
        $this->respondToProcessor($payload, decline: 'INSUFFICIENT_FUNDS');
        $this->persistDeclined($card, $authReq, 'INSUFFICIENT_FUNDS');
        return;
    }

    // 5. Place hold (debit available_balance, do NOT debit ledger_balance until clearing)
    WalletService::placeHold($wallet, $totalDebit, "card_auth:{$payload['authorization_id']}");

    // 6. Approve
    $this->respondToProcessor($payload, approve: true);
    $this->persistApproved($card, $authReq, $totalDebit, $fxFee, $atmFee);
});
```

Clearing webhook applies the actual ledger debit (with potential adjustment if the cleared amount differs from auth amount). Reversal/refund credit back to the wallet.

The `respondToProcessor` is a synchronous response if the processor uses request/response auth (most do), or a webhook callback if async — depends on processor.

---

## 7. Idempotency on processor calls

All outbound calls to the processor include an `Idempotency-Key` header. For card creation:

```
Idempotency-Key: card-create:{user_id}:{nickname}:{ts_minute_bucket}
```

This prevents a retry of the same logical create from creating two cards. The processor returns the same card on duplicate keys.

Card freeze/unfreeze: idempotency key is the action + card token + UTC second:

```
Idempotency-Key: freeze:{token}:{ts_second}
```

Reveal URL minting: NO idempotency. Each request must produce a fresh URL with fresh TTL.

---

## 8. Mobile webview rules (mirrors `02-architecture.md` mobile §8)

The reveal URL is loaded in `react-native-webview` with these constraints:

```ts
<WebView
  source={{ uri: revealUrl }}
  originWhitelist={[
    'https://reveal.demo-maphapay.dev',     // demo
    'https://reveal.rainbank.io',           // rain (placeholder)
  ]}
  javaScriptEnabled={true}
  injectedJavaScript=""
  onMessage={() => {}}                  // discard postMessage
  allowsBackForwardNavigationGestures={false}
  sharedCookiesEnabled={false}
  cacheEnabled={false}
  incognito
/>
```

The originWhitelist is the only place the issuer's reveal domain is hard-coded on the client. Update in lockstep with backend processor selection.

OS-level screenshot prevention:
- Android: `expo-screen-capture` `preventScreenCaptureAsync()` in the screen mount; release on unmount.
- iOS: subscribe to `UIScreen.capturedDidChangeNotification`; on capture-detected, overlay an opaque view over the webview.

---

## 9. Settlement matching

When a `clearing` webhook arrives, match by `processor_transaction_id` (set during authorisation). Update `card_transactions`:

```php
$tx = CardTransaction::where('processor_transaction_id', $payload['transaction_id'])
    ->lockForUpdate()
    ->firstOrFail();

$tx->status = 'settled';
$tx->settled_at = now();
$tx->billing_amount = $payload['settled_billing_amount'];   // may differ slightly from auth
$tx->save();

if ($tx->billing_amount->ne($tx->originallyAuthorisedAmount())) {
    // Adjust ledger: release hold, debit actual amount
    WalletService::releaseHoldAndDebit($wallet, $tx->held_amount, $tx->billing_amount);
    PushNotification::send($tx->user, 'card_amount_changed', [...]);
} else {
    WalletService::settleHold($wallet, $tx->held_amount);
}
```

If settlement arrives without a matching auth (orphaned settlement): persist with `status='settled'`, raise an alert. Processor errors should be rare; reconciliation runs nightly.

---

## 10. Provider config matrix

```php
// config/cards.php
return [
    'default_processor' => env('CARDS_DEFAULT_PROCESSOR', 'demo'),
    'processors' => [
        'demo' => [
            'driver' => 'demo',
            'webhook_secret' => env('CARDS_DEMO_WEBHOOK_SECRET'),
            'reveal_secret' => env('CARDS_DEMO_REVEAL_SECRET'),
            'reveal_origin' => env('CARDS_DEMO_REVEAL_ORIGIN', 'https://reveal.demo-maphapay.dev'),
        ],
        'rain' => [
            'driver' => 'rain',
            'api_base_url' => env('CARDS_RAIN_API_BASE_URL'),
            'api_key' => env('CARDS_RAIN_API_KEY'),
            'webhook_secret' => env('CARDS_RAIN_WEBHOOK_SECRET'),
            'reveal_origin' => env('CARDS_RAIN_REVEAL_ORIGIN'),
        ],
    ],
];
```

Switching from demo to rain in production requires:

1. Set env vars.
2. `CARDS_DEFAULT_PROCESSOR=rain`.
3. Update mobile `originWhitelist` (push a config update or a forced app update).
4. Run a dry-run reveal end-to-end on staging.

---

## 11. CI security checks

A CI step (e.g. GitHub Action) runs:

```bash
# Fail if any source file matches likely PAN-handling
! grep -rEn "[\"']?\b\d{12,19}\b[\"']?" --include="*.php" app/

# Fail if cardNumber/pan/cvv appear as non-DTO fields
! grep -rEn "(\\\$pan|->pan|cvv|cardNumber|card_number)" --include="*.php" app/Domain/CardSubscriptions/

# Fail if any logger receives PAN-shaped data (heuristic)
! grep -rEn "Log::.*card_number" --include="*.php" app/
```

Any hit fails the build. False positives can be allowlisted with `// pci-allow` comments (rare; reviewed by compliance).

---

## 12. Adding a new processor

To plug in a third processor (e.g. Marqeta):

1. Create `app/Domain/CardIssuance/Adapters/MarqetaCardIssuerAdapter.php` implementing `CardIssuerInterface`.
2. Add `marqeta` to `config/cards.php` `processors` array.
3. Update the service-provider switch in `CardSubscriptionsServiceProvider::register()`.
4. Add `marqeta` to mobile `originWhitelist`.
5. Add Pest tests covering each method (mock HTTP fixture).
6. Update this doc's §10 matrix.

The interface is the only place that knows about cards. Adapters never bypass it.

---

## 13. Failure modes

| Scenario | Behavior |
|---|---|
| Processor down on card creation | `PROCESSOR_UNAVAILABLE` error to mobile; retry button creates a new request with a fresh idempotency key (the failed one returns the failure on retry) |
| Processor down on freeze | Persist the user-requested status locally as `frozen_by_user`; queue a `RetryProcessorFreezeJob`; when processor recovers, sync state. The card is treated as frozen by the auth pipeline regardless. |
| Webhook signature mismatch | 401, no audit (signature verification IS the auth boundary; logging the unverified body is itself a risk) |
| Webhook for unknown card_token | Audit `processor.webhook_unknown_card`; alert ops; return 200 (don't make the processor retry forever) |
| Reveal URL TTL expired before user opens | Mobile shows "Reveal expired — try again". Backend has no state to clean. |

---

## 14. Pre-launch security audit checklist

Before any environment with real users:

- [ ] CI security grep passes.
- [ ] Production secrets (webhook secret, API key) are stored in the secrets manager, not env files.
- [ ] Reveal page domain is HTTPS, HSTS-preloaded, no JavaScript that calls back to MaphaPay.
- [ ] Mobile webview originWhitelist is exactly the issuer's reveal origin — no broader.
- [ ] HMAC verification uses `hash_equals` (constant time).
- [ ] At least one external pen test on the reveal flow.
- [ ] Failed-decryption / signature-failure metrics emit to Sentry; ops team has alert paging on >0/min sustained.
