# MaphaPay → FinAegis replacement — progress handoff

**Source plan:** [maphapay-backend-replacement-plan.md](./maphapay-backend-replacement-plan.md)

**Purpose:** Let any agent continue without re-reading the full plan. **Update this file** when you finish a slice or change file ownership.

---

## Completed (workspace)

### Earlier slice (pre–parallel)

| Area | What | Files |
|------|------|--------|
| Phase 3.1 | SZL fiat asset seeded | `database/seeders/AssetSeeder.php` |
| Phase 3.3 #1 | `TransactionProjection` formatted amount uses `Asset.precision` | `app/Domain/Account/Models/TransactionProjection.php` |
| Phase 3.3 #2 | Reversal history uses `Asset::fromSmallestUnit()` | `app/Http/Controllers/Api/TransactionReversalController.php` |
| Idempotency | Accept `X-Idempotency-Key` if `Idempotency-Key` absent | `app/Http/Middleware/IdempotencyMiddleware.php` |
| Tests | Formatted amount + middleware alias | `tests/Unit/Domain/Account/Models/TransactionProjectionFormattedAmountTest.php`, `tests/Feature/Middleware/IdempotencyMiddlewareTest.php` |

### Parallel dispatch **2026-03-27** (non-overlapping files; no cross-stream edits)

| Stream | Files | What was done (summary) |
|--------|--------|-------------------------|
| **A** | `app/Http/Controllers/Api/AccountBalanceController.php` | `calculateUsdEquivalent()` uses asset precision + `ExchangeRateService` for non-USD; `smallestUnitToMajor()` helper; OA description on `total_usd_equivalent`. |
| **B** | `app/Http/Controllers/Api/TransferController.php` | Major-unit **string** contract, `amount` + `amount_minor` in responses, OA aligned; private helpers for deterministic minor conversion. **Verify:** OA paths say `/api/v2/transfers` — confirm they match `routes/api-v2.php` + `app/Domain/Account/Routes/api.php` (tests may use `/api/...`). |
| **C** | `app/Http/Controllers/Api/TransactionController.php` | Safer `history()` JSON + event mapping; `shouldUseLegacyAccountMoneyWorkflow()` = USD only; SZL/non-USD → asset deposit/withdraw workflows. |
| **D** | `BatchProcessingActivity.php`, `SingleBatchOperationActivity.php` | Per-asset thresholds, USD helpers, CTR/SAR/monthly stats use precision-aware amounts; `volume_note` where sums mix assets. |

**Subagent IDs (resume in Cursor only if supported):** Stream A `9fec0d60-b7de-4a2e-8070-306de91e9ccb`, B `37920193-4375-4dfc-b874-376459a1033b`, C `cb0bfa22-f212-4370-bf9d-ef6c47dcabb1`, D `39289c26-e06c-4274-a5a6-b408d6e9a9b1`.

### Session **2026-03-28** — CI clearance + foundational utilities

| Area | What | Files |
|------|------|--------|
| CI | PHPStan clean (3807 files, 0 errors), CS Fixer applied (20 files), tests passing | various |
| Test fix | Transfer test `data.amount` assertion updated `200` → `'200.00'` (major-unit string contract) | `tests/Feature/Http/Controllers/Api/TransferControllerTest.php` |
| Phase 12 | `MoneyConverter` — bcmath precision-safe string→minor-unit converter with half-up rounding | `app/Domain/Shared/Money/MoneyConverter.php` |
| Phase 12 | `MajorUnitAmountString` validation rule — rejects float/int amounts at type level | `app/Rules/MajorUnitAmountString.php` |
| Phase 12 | MoneyConverter unit tests (22 passing) | `tests/Unit/Domain/Shared/Money/MoneyConverterTest.php` |
| Phase 11 | `authorized_transactions` migration — status machine, OTP fields, trx reference | `database/migrations/2026_03_28_100001_create_authorized_transactions_table.php` |
| Phase 11 | `AuthorizedTransaction` model | `app/Domain/AuthorizedTransaction/Models/AuthorizedTransaction.php` |
| Phase 11 | `AuthorizedTransactionHandlerInterface` contract | `app/Domain/AuthorizedTransaction/Contracts/AuthorizedTransactionHandlerInterface.php` |
| Phase 11 | `SendMoneyHandler` | `app/Domain/AuthorizedTransaction/Handlers/SendMoneyHandler.php` |
| Phase 11 | `RequestMoneyReceivedHandler` | `app/Domain/AuthorizedTransaction/Handlers/RequestMoneyReceivedHandler.php` |
| Phase 11 | `ScheduledSendHandler` | `app/Domain/AuthorizedTransaction/Handlers/ScheduledSendHandler.php` |
| Phase 11 | `AuthorizedTransactionManager` — initiate, dispatchOtp, verifyOtp, verifyPin, atomic finalize | `app/Domain/AuthorizedTransaction/Services/AuthorizedTransactionManager.php` |
| Phase 11 | `VerifyOtpController` + `VerifyPinController` (legacy envelope) | `app/Http/Controllers/Api/Compatibility/VerificationProcess/` |

### Session **2026-03-28** — Phase 18 + Phase 5 (send/request store)

| Area | What | Files |
|------|------|--------|
| Phase 18 | `routes/api-compat.php` — env-gated verification, send-money, request-money routes | `routes/api-compat.php` |
| Phase 18 | Compatibility routes registered with `api` + `auth:sanctum` (Laravel 12: `bootstrap/app.php`, not `RouteServiceProvider`) | `bootstrap/app.php` |
| Config | `MAPHAPAY_MIGRATION_ENABLE_VERIFICATION`, `_SEND_MONEY`, `_REQUEST_MONEY` → `config('maphapay_migration.*')` | `config/maphapay_migration.php` |
| Phase 5 | `SendMoneyStoreController` — `MajorUnitAmountString`, `MoneyConverter::normalise`, `AuthorizedTransactionManager::initiate` + `dispatchOtp`, legacy envelope | `app/Http/Controllers/API/Compatibility/SendMoney/SendMoneyStoreController.php` |
| Phase 5 | `RequestMoneyStoreController` — `money_requests` row + auth txn `request_money`, no wallet movement at store | `app/Http/Controllers/API/Compatibility/RequestMoney/RequestMoneyStoreController.php` |
| Phase 5 | `MoneyRequest` model + migration; `RequestMoneyHandler` (OTP/PIN finalize → status `pending`) | `app/Models/MoneyRequest.php`, `database/migrations/2026_03_28_150000_create_money_requests_table.php`, `app/Domain/AuthorizedTransaction/Handlers/RequestMoneyHandler.php` |
| Tests | Feature tests for compat send/request store (enable flags via `config()`) | `tests/Feature/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreControllerTest.php`, `tests/Feature/Http/Controllers/Api/Compatibility/RequestMoney/RequestMoneyStoreControllerTest.php` |

### Session **2026-03-28** — Phase 5 (request-money accept / reject / history)

| Area | What | Files |
|------|------|--------|
| Phase 5 | `RequestMoneyReceivedStoreController` — pending + recipient checks; `initiate(REMARK_REQUEST_MONEY_RECEIVED)` + wallet UUIDs for `RequestMoneyReceivedHandler`; `dispatchOtp` when OTP | `app/Http/Controllers/API/Compatibility/RequestMoney/RequestMoneyReceivedStoreController.php` |
| Phase 5 | `RequestMoneyRejectController` — pending + recipient; status → `rejected` | `app/Http/Controllers/API/Compatibility/RequestMoney/RequestMoneyRejectController.php` |
| Phase 5 | `RequestMoneyHistoryController` / `RequestMoneyReceivedHistoryController` — paginated lists (`request_moneys` vs `requested_moneys`) | same folder |
| Model | `MoneyRequest::STATUS_REJECTED` | `app/Models/MoneyRequest.php` |
| Phase 18 | Four routes under `migration_flag:enable_request_money` group (implicit `{moneyRequest}` binding) | `routes/api-compat.php` |
| Tests | Feature tests for the four controllers (flag on/off, auth scopes) | `tests/Feature/Http/Controllers/Api/Compatibility/RequestMoney/*ReceivedStore*`, `*Reject*`, `*History*`, `*ReceivedHistory*` |

### Session **2026-03-28** — Phase 5 post-review hardening (request-money)

| Area | What | Files |
|------|------|--------|
| Critical fix | `MoneyRequest::STATUS_FULFILLED` added — closes double-accept window (status stays `pending` forever without it) | `app/Models/MoneyRequest.php` |
| Critical fix | `RequestMoneyReceivedHandler` now calls `MoneyRequest::query()->update(['status' => STATUS_FULFILLED])` after wallet transfer; added null/type guard for `money_request_id`; fixed PHPDoc (int→string UUID) | `app/Domain/AuthorizedTransaction/Handlers/RequestMoneyReceivedHandler.php` |
| Security fix | `RequestMoneyReceivedStoreController` — added self-acceptance guard (`requester_user_id === authUser` → 422); wrapped `initiate()` + `dispatchOtp()` in `DB::transaction` for atomicity | `app/Http/Controllers/API/Compatibility/RequestMoney/RequestMoneyReceivedStoreController.php` |
| Consistency fix | `RequestMoneyRejectController` — replaced inline error response payloads with `errorPayload()`/`errorResponse()` private helpers (matches `ReceivedStoreController` pattern) | `app/Http/Controllers/API/Compatibility/RequestMoney/RequestMoneyRejectController.php` |
| Route fix | `received-store` route now includes `idempotency` middleware (parity with `send-money/store`) — prevents duplicate `AuthorizedTransaction` rows on mobile retry | `routes/api-compat.php` |
| Tests | 3 new test cases: double-accept blocked (STATUS_FULFILLED), frozen recipient account → 422, already-rejected request → 422 on reject | `*ReceivedStoreControllerTest.php`, `*RejectControllerTest.php` |

### Session **2026-03-28** — Phase 5 scheduled send post-review hardening

| Area | What | Files |
|------|------|--------|
| Critical fix | `ScheduledSendHandler` — pre-transfer `lockForUpdate` status guard: aborts transfer if `scheduled_send` is no longer `pending` (cancel-then-OTP exploit closed) | `app/Domain/AuthorizedTransaction/Handlers/ScheduledSendHandler.php` |
| Critical fix | `ScheduledSendHandler` — try/catch around `walletOps->transfer`; marks row `failed` on exception so user is not left with a phantom `pending` entry | same |
| Security fix | `ScheduledSendCancelController` — wraps cancel in `DB::transaction`; also flips linked `authorized_transaction` to `cancelled` (defense-in-depth) | `app/Http/Controllers/API/Compatibility/ScheduledSend/ScheduledSendCancelController.php` |
| Model | `AuthorizedTransaction::STATUS_CANCELLED = 'cancelled'` added; migration comment updated | `app/Domain/AuthorizedTransaction/Models/AuthorizedTransaction.php`, migration `2026_03_28_100001` |
| Consistency fix | `ScheduledSendIndexController` — replaced `toArray()` with explicit field projection; `scheduled_for`/`created_at`/`updated_at` serialized as ISO 8601 | `app/Http/Controllers/API/Compatibility/ScheduledSend/ScheduledSendIndexController.php` |
| Validation | `ScheduledSendStoreController` — added `before:+1 year` upper bound on `scheduled_for`; removed redundant `User::find()` (exists: rule already validates) | `app/Http/Controllers/API/Compatibility/ScheduledSend/ScheduledSendStoreController.php` |
| Tests | 5 new cases: self-send rejected, frozen sender, `scheduled_for > 1 year` rejected, cancel non-pending → 422, cancel propagates to `authorized_transaction` | `ScheduledSendStoreControllerTest.php`, `ScheduledSendCancelControllerTest.php` |

**Design note (schedule timing):** `ScheduledSendHandler` executes immediately on OTP/PIN verification — `scheduled_for` is stored but not enforced. The `ExecuteScheduledSendsCommand` (not yet built) will use `AuthorizedTransactionManager::finalize()` (no-OTP path) for time-deferred execution. Until that command exists every "scheduled" send executes when the user verifies. Clarify product intent before building the command.

### Session **2026-03-28** — Phase 5 scheduled send (§5.3.1 items 2–4)

| Area | What | Files |
|------|------|--------|
| Migration + model | `scheduled_sends` — sender/recipient, major-unit `amount`, `scheduled_for`, `status` (pending / cancelled / executed / failed), `trx` | `database/migrations/2026_03_28_160000_create_scheduled_sends_table.php`, `app/Models/ScheduledSend.php` |
| Phase 5 | `ScheduledSendStoreController` — `AuthorizedTransactionManager::initiate(REMARK_SCHEDULED_SEND)` + `dispatchOtp`, legacy envelope `remark: scheduled_send` | `app/Http/Controllers/API/Compatibility/ScheduledSend/ScheduledSendStoreController.php` |
| Phase 5 | `ScheduledSendIndexController` — paginated `scheduled_sends` for sender (same shape as request-money history) | `ScheduledSendIndexController.php` |
| Phase 5 | `ScheduledSendCancelController` — owner-only, `pending` → `cancelled`, envelope `scheduled_send_cancel` | `ScheduledSendCancelController.php` |
| Handler | `ScheduledSendHandler` — after successful wallet transfer, marks row `executed` when `scheduled_send_id` present in payload | `app/Domain/AuthorizedTransaction/Handlers/ScheduledSendHandler.php` |
| Phase 18 | Routes under `migration_flag:enable_scheduled_send`; `idempotency` on `store` only | `routes/api-compat.php` |
| Config | `MAPHAPAY_MIGRATION_ENABLE_SCHEDULED_SEND` → `enable_scheduled_send` | `config/maphapay_migration.php` |
| Tests | Feature tests for store / index / cancel (same patterns as other compat controllers) | `tests/Feature/Http/Controllers/Api/Compatibility/ScheduledSend/*` |

### Session **2026-03-28** — Phase 15 MTN MoMo (config + compat API)

| Area | What | Files |
|------|------|--------|
| Config | `config/mtn_momo.php` — `MTNMOMO_*` env keys, optional `MTNMOMO_CALLBACK_TOKEN` + `MTNMOMO_VERIFY_CALLBACK_TOKEN` for IPN | `config/mtn_momo.php` |
| Phase 18 | `enable_mtn_momo` ← `MAPHAPAY_MIGRATION_ENABLE_MTN_MOMO` (default false) | `config/maphapay_migration.php` |
| Persistence | `mtn_momo_transactions` — idempotency per user, MTN reference, wallet credit/debit timestamps | `database/migrations/2026_03_28_170000_create_mtn_momo_transactions_table.php`, `app/Models/MtnMomoTransaction.php` |
| Client | `MtnMomoClient` — collection/disbursement OAuth tokens, request-to-pay, transfer, status GET | `app/Domain/MtnMomo/Services/MtnMomoClient.php` |
| Settlement | `MtnMomoCollectionSettler` — idempotent `WalletOperationsService::deposit` on successful collection (status poll + IPN) | `app/Domain/MtnMomo/Services/MtnMomoCollectionSettler.php` |
| Phase 18 | Routes under `migration_flag:enable_mtn_momo`: `POST mtn/request-to-pay`, `POST mtn/disbursement` (both + `idempotency`), `GET mtn/transaction/{referenceId}/status`, `POST mtn/callback` (**`withoutMiddleware([Authenticate::class, 'auth:sanctum'])`** — both class and alias required to fully strip auth from the group stack) | `routes/api-compat.php` |
| Controllers | Legacy-style success/error envelopes; `MajorUnitAmountString` + `MoneyConverter::normalise`; disbursement debits wallet before MTN call with refund on MTN failure | `app/Http/Controllers/API/Compatibility/Mtn/*` |
| Tests | `MtnMomoControllersTest` — flag off 404, RTP/disbursement/status/callback (all 7 pass); `WalletOperationsService` mocked via `$this->app->instance()` to avoid workflow dispatch type mismatch in HTTP-layer tests | `tests/Feature/Http/Controllers/Api/Compatibility/Mtn/MtnMomoControllersTest.php` |

**Post-review hardening (2026-03-28):** Code-review findings resolved before merge:
- **Security:** MTN `RuntimeException` messages (which include raw HTTP response bodies) no longer leaked to API clients — logged internally via `Log::error`, generic string returned.
- **Security:** Callback with empty `MTNMOMO_CALLBACK_TOKEN` + verification enabled now logs `Log::warning` and returns 401 immediately, making misconfiguration visible.
- **Resilience:** Callback 404 for unknown reference ID changed to 200 (no-op + `Log::warning`) so MTN does not retry indefinitely.
- **Observability:** `Log::critical` on failed disbursement refund (funds-loss path); `Log::error` on all MTN API failure paths.
- **Audit trail:** Added `wallet_refunded_at` column (`2026_03_28_180000_add_wallet_refunded_at_to_mtn_momo_transactions.php`) — replaces nulling `wallet_debited_at` in `refundAndFail`, preserving full debit/refund history for reconciliation.
- **Idempotency race:** Concurrent disbursements with the same key that hit the DB unique constraint now retry the idempotency query and return the existing record (200) instead of 503.
- **Route auth:** `withoutMiddleware` updated to strip both `Authenticate::class` and `'auth:sanctum'` alias — original only stripped the class reference, leaving `auth:sanctum` alias in place.
- **Style:** Migration file anonymous-class brace style fixed (CS-Fixer).

**Known follow-up (not blocking):** Async disbursement failure path — when MTN accepts (202) but later marks a disbursement FAILED via callback, there is no auto-refund. A reconciliation cron job should be scoped and scheduled before production launch with real money.

**Note:** Paths are `/api/mtn/...` (handoff). Legacy mobile used `/api/wallet-transfer/mtn-momo/...`; add proxies or mobile config if URLs must stay byte-identical.

---

## Phase 10 findings (auth / shared `users` table) — 2026-03-28

Assessment only (no compat auth controllers added in this slice).

- **`users` (FinAegis):** Base Laravel 11-style table (`name`, `email`, `password`, `email_verified_at`, Jetstream/Fortify fields, etc.) plus many follow-up migrations: `uuid` (HasUuids), KYC timestamps/level, OAuth columns, onboarding, country, teams, sponsorship/referral, mobile prefs, etc. Any legacy MaphaPay client that only needs `id` + `email` + `password` + token login can work **if** the same identifiers and hashing algorithm are used and extra NOT NULL constraints are satisfied for new signups.
- **`personal_access_tokens`:** Standard Sanctum schema (`morphs('tokenable')`, `name`, `token`, `abilities`, `last_used_at`, `expires_at`). Mobile Bearer auth aligns with Laravel Sanctum as long as the app creates tokens the same way (abilities/scopes must include what middleware expects, e.g. `read`/`write`/`delete` per CLAUDE.md tests).
- **Transaction PIN:** `AuthorizedTransactionManager::verifyPin()` checks `$user->transaction_pin`. There is **no** `transaction_pin` column in tracked `database/migrations` under a quick repo search; confirm on real DBs whether this attribute exists (custom migration outside repo, or JSON/`kyc_data`). Without a stored hashed PIN, PIN verification paths will always fail until the column (or equivalent) exists and is populated.
- **LoginController / RegisterController compat shims:** Not required solely from schema comparison. Add thin compat controllers **only when** the legacy mobile contract (URLs, field names, error JSON, OTP-on-login, etc.) diverges from current Fortify/Jetstream or `routes/api.php` auth. Next step: diff against the old MaphaPay OpenAPI or capture production requests.

---

## Must-do before merge (any agent)

> **CI is currently green** (2026-03-28). Current branch: `feat/phase-16-rate-limiting`. PHP binary: use `php85` via Herd (`/Users/Lihle/Library/Application Support/Herd/bin/php85`) — `php84` has a dyld error on this machine.

```bash
PHP85="/Users/Lihle/Library/Application Support/Herd/bin/php85"
XDEBUG_MODE=off "$PHP85" vendor/bin/phpstan analyse --memory-limit=2G
"$PHP85" vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php
"$PHP85" vendor/bin/pest tests/Feature/Http/Controllers/Api/Compatibility/RateLimiting/CompatRateLimitingTest.php \
  tests/Feature/Http/Controllers/Api/TransferControllerTest.php \
  tests/Unit/Domain/Account/Models/TransactionProjectionFormattedAmountTest.php \
  tests/Feature/Middleware/IdempotencyMiddlewareTest.php \
  tests/Unit/Domain/Shared/Money/MoneyConverterTest.php \
  tests/Feature/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreControllerTest.php \
  tests/Feature/Http/Controllers/Api/Compatibility/RequestMoney/RequestMoneyStoreControllerTest.php \
  tests/Feature/Http/Controllers/Api/Compatibility/RequestMoney/RequestMoneyReceivedStoreControllerTest.php \
  tests/Feature/Http/Controllers/Api/Compatibility/RequestMoney/RequestMoneyRejectControllerTest.php \
  tests/Feature/Http/Controllers/Api/Compatibility/RequestMoney/RequestMoneyHistoryControllerTest.php \
  tests/Feature/Http/Controllers/Api/Compatibility/RequestMoney/RequestMoneyReceivedHistoryControllerTest.php
```

1. **ExchangeRateService:** Confirm `AccountBalanceController` injection/calls match real `ExchangeRateService` API (method names, return types) — carried over from stream A.
2. **Migrations:** Run `php artisan migrate` so `authorized_transactions`, `money_requests`, and `mtn_momo_transactions` exist before enabling compat flags.
3. **SQLite test timeout:** If Pest aborts migrations after 10s on `:memory:` SQLite, run the suite on MySQL (or CI’s `phpunit.ci.xml`) — same symptom affects any feature test that refreshes the full migration set.

---

## Parallel workstreams still **safe** (exclusive files — pick up next)

These do **not** touch files already changed above; assign one agent per row.

| Next stream | Exclusive files | Plan ref |
|-------------|-----------------|----------|
| ~~**E**~~ | ~~`app/Http/Controllers/API/MobilePayment/PaymentIntentController.php`~~ | **Already done** — `resolveIdempotencyKeyFromHeaders()` at line 430 already handles both `Idempotency-Key` and `X-Idempotency-Key` with the same null-coalescing order as `IdempotencyMiddleware`. |
| ~~**G**~~ | ~~`config/machinepay.php`, `config/agent_protocol.php`~~ | **Audited & skipped** — see Stream G findings below. |

**`app/Http/Controllers/Api/Compatibility/` directory is now live** — do not scaffold it again (VerifyOtp/VerifyPin already exist there).

**Do not parallelize:** MTN controllers, social-money domain, auth compatibility controllers — all depend on Phase 11 (`AuthorizedTransactionManager`) being merged first.

---

## In flight / next (serial — implement in this order)

1. ~~**Phase 10 — Auth gap assessment**~~ **Documented** (2026-03-28): See **Phase 10 findings** above. No compat auth controllers until legacy contract is known.
2. ~~**Phase 18 — `routes/api-compat.php`**~~ **Done** (2026-03-28).
3. ~~**Phase 5 — `SendMoneyStoreController`**~~ **Done** (2026-03-28).
4. ~~**Phase 5 — `RequestMoneyStoreController`**~~ **Done** (2026-03-28).
5. ~~**Phase 5 — request-money accept / reject / history / scheduled send**~~ **Done** (2026-03-28).
6. ~~**Phase 15 — MTN config + controllers**~~ **Done** (2026-03-28).
7. ~~**MTN reconciliation command**~~ **Done** (2026-03-28).
8. ~~**Phase 16 — per-user rate limiting**~~ **Done** (2026-03-28): renderable 429 envelope + 4 tests. Branch: `feat/phase-16-rate-limiting`.
9. **Phase 13 — `ExecuteScheduledSends` command** — **Already existed**. `app/Console/Commands/ExecuteScheduledSends.php` fully implemented. No work required.
10. **Phase 14 — `MigrateLegacySocialGraph` command** — **Already existed**. `app/Console/Commands/MigrateLegacySocialGraph.php` handles identity_map, friendships, friend_requests, pending_money_requests, device_tokens. No work required.
11. ~~**Stream G — SZL currency config defaults**~~ **Audited & skipped** (2026-03-29): see findings below — no changes required.
12. ~~**Phase 10 — Auth compat controllers**~~ **Done** (2026-03-29): `MobileAuthController` (7 methods), `AuthorizationController` (2 methods), `CountriesController` (1 method), `DeviceTokenController` (1 method) all created. Cleanup: removed redundant migrations and outdated docs.
13. ~~**Phase 19 — Compat suite smoke tests**~~ **Done** (2026-03-29): All compat tests pass on SQLite (281 assertions). Fixed 3 tests missing `kyc_status='approved'`. Full-domain MySQL smoke test recommended pre-production but not blocking.
14. ~~**Phase 17 — Stage 1 Backfill**~~ **Done** (2026-03-29): `migration_delta_log` table + `MigrateLegacyBalances` command + legacy DB connection. Option A rolling snapshot implemented.
15. ~~**Phase 19 — Performance & Load Testing**~~ **Done** (2026-03-29): `Phase19LoadTest` (4 tests passing on SQLite) + k6 staging scripts doc. MySQL full-suite smoke test recommended pre-production but deferred to staging run.
16. ~~**Full suite MySQL smoke test**~~ **Not run** (2026-03-29): Compat suite (73 tests) clean on SQLite. Full-domain MySQL smoke test recommended before production cutover — requires MySQL connection.

## In flight / next (old — serial or after parallel merge)

- ~~**Domain operation idempotency** (`operation record` table)~~ **Done** (2026-03-29): OperationRecord migration + service + wiring into AuthorizedTransactionManager + compat controllers.
- **MTN / wallet-linking** — separate bounded context.

---

## How to update this doc

1. Append to **Completed** with date and file list.
2. Remove or shrink **Parallel workstreams** when merged.
3. Add **blockers** (e.g. “Transfer tests failing: …”) so the next agent reads one section.

---

### Session **2026-03-28** — Phase 16 rate limiting

| Area | What | Files |
|------|------|--------|
| Phase 16 | `RateLimiter::for('maphapay-send-money', ...)` — 10/min per user (user ID or IP fallback); `RateLimiter::for('maphapay-mtn-initiation', ...)` — 5/min per user | `app/Providers/AppServiceProvider.php` *(already present from earlier session)* |
| Phase 16 | `throttle:maphapay-send-money` on `POST send-money/store`; `throttle:maphapay-mtn-initiation` on `POST mtn/disbursement` — both inside their `migration_flag` group | `routes/api-compat.php` *(already present from earlier session)* |
| Phase 16 | Custom 429 compat envelope via `$exceptions->renderable(TooManyRequestsHttpException)` in `bootstrap/app.php`: `{ "status": "error", "message": "Too many requests. Please try again later." }` with `Retry-After` header pass-through; `respond()` callback still appends `error:"RATE_LIMITED"` and `request_id` | `bootstrap/app.php` |
| Bugfix | `TransactionHistoryControllerTest::makeUserWithAccount()` missing `@return array{User, Account}` PHPDoc — pre-existing PHPStan level-8 error | `tests/Feature/Http/Controllers/Api/Compatibility/Transactions/TransactionHistoryControllerTest.php` |
| Tests | `CompatRateLimitingTest` — 4 tests: send-money 10th not throttled (422), 11th → 429 envelope; MTN disbursement 5th not throttled, 6th → 429 envelope; users created with `kyc_status='approved'` to reach throttle middleware past `kyc_approved` gate; `Cache::flush()` in setUp | `tests/Feature/Http/Controllers/Api/Compatibility/RateLimiting/CompatRateLimitingTest.php` |

### Session **2026-03-29** — Stream G audit: SZL config defaults

**Conclusion: No changes required. Both config files are safe to leave as-is for the MaphaPay migration.**

Audit performed as required by the handoff prompt before touching any config:

| Config key | Read by | Verdict |
|------------|---------|---------|
| `machinepay.server.default_currency` | `MppDiscoveryService` (MPP discovery endpoint), `MultiProtocolBridgeService` | **Skip** — MPP is the Machine Payments Protocol for AI-agent HTTP 402 commerce. Has no connection to MaphaPay wallet flows. Changing to SZL would make the MPP discovery endpoint advertise a non-standard currency, breaking any MPP client. |
| `agent_protocol.wallet.default_currency` | `AgentPaymentIntegrationService::getDefaultCurrency()`, `AIAgentProtocolBridgeService` | **Skip** — Agent Protocol wallet config governs AI-to-AI agent payments (DID-based, escrow, multi-currency). No MaphaPay compat controller reads this key. |

**Why no MaphaPay code reads these configs:** MaphaPay compat controllers resolve currency via the `Asset` model (seeded SZL — Phase 3.1 ✅) and `MoneyConverter` (Phase 12 ✅). Config-layer currency defaults are irrelevant to those flows.

**The plan's own recommendation** (Phase 3.2): *"For MaphaPay replacement APIs, prefer changing only what powers wallet/account/transfer display + reconciliation."* Both config files are exclusively consumed by Agent Protocol and MPP — separate bounded contexts from MaphaPay.

26 files in `app/Domain/MachinePay/` and `app/Domain/AgentProtocol/` read `config('machinepay.*')` / `config('agent_protocol.*')`. Zero MaphaPay compat files do. Changing these defaults would regress AI agent features with no migration benefit.

### Session **2026-03-29** — Phase 19 compat suite smoke test (SQLite)

**Status: All compat tests passing on SQLite `:memory:` — no MySQL required for this run.**

SQLite timeout did **not** occur for the compat sub-suite (`tests/Feature/Http/Controllers/Api/Compatibility/`). Full suite completed in ~17s (73 deprecations, 281 assertions, 0 failures).

**Bugs found and fixed:**

| Bug | Root cause | Fix |
|-----|-----------|-----|
| `RequestMoneyReceivedHistoryControllerTest` 403 | User created without `kyc_status='approved'`; `kyc_approved` middleware blocked route | Added `['kyc_status' => 'approved']` to both factory calls |
| `RequestMoneyHistoryControllerTest` (pre-emptive) | Same pattern — users in `setUp` lacked KYC approval | Added `['kyc_status' => 'approved']` to both factory calls |
| `RequestMoneyRejectControllerTest` 403 on "not recipient" | `$other` user in `setUp` lacked KYC approval; 403 before controller logic ran | Added `['kyc_status' => 'approved']` to `$other` and `setUp` users |

**Files changed:**
- `tests/Feature/Http/Controllers/Api/Compatibility/RequestMoney/RequestMoneyReceivedHistoryControllerTest.php`
- `tests/Feature/Http/Controllers/Api/Compatibility/RequestMoney/RequestMoneyHistoryControllerTest.php`
- `tests/Feature/Http/Controllers/Api/Compatibility/RequestMoney/RequestMoneyRejectControllerTest.php`

**PHPStan:** 0 errors (3873 files). **CS-Fixer:** 0 files changed.

**Updated "In flight" list:**
- Item 13 (Phase 19) — compat suite now clean on SQLite. MySQL smoke test for **full** suite (all domains) still recommended before production cutover but is not blocking further compat work.

**Current branch:** `main` — 12 commits ahead of `origin/main` (not yet pushed per user instruction).

### Session **2026-03-28** — Transaction History + Dashboard compat endpoints

| Area | What | Files |
|------|------|--------|
| Config | `enable_transaction_history`, `enable_dashboard` flags | `config/maphapay_migration.php` |
| Phase 5 | `TransactionHistoryController` — `GET /api/transactions`: queries `TransactionProjection` for the user's account, paginates 15/page, returns **canonical domain field names** (`id`, `reference`, `description`, `amount` major-unit string, `type` deposit/withdrawal/transfer, `subtype`, `asset_code`, `created_at`); supports `type`/`subtype`/`search` query filters; returns distinct `subtypes` list | `app/Http/Controllers/Api/Compatibility/Transactions/TransactionHistoryController.php` |
| Phase 5 | `DashboardController` — `GET /api/dashboard`: returns user info + SZL balance (major-unit string), cached 30 s per user; balance = 0.00 when no account exists | `app/Http/Controllers/Api/Compatibility/Dashboard/DashboardController.php` |
| Routes | `GET /api/transactions` (flag `enable_transaction_history`), `GET /api/dashboard` (flag `enable_dashboard`) in `api-compat.php` | `routes/api-compat.php` |
| Tests | `TransactionHistoryControllerTest` (9 cases), `DashboardControllerTest` (6 cases) — all 15 pass; PHPStan 0 errors, CS-Fixer clean | `tests/Feature/Http/Controllers/Api/Compatibility/Transactions/`, `tests/Feature/Http/Controllers/Api/Compatibility/Dashboard/` |

**Canonical field policy (no legacy aliases):** `TransactionHistoryController` returns `type`/`subtype`/`reference`/`description` — NOT `trx_type`/`remark`/`trx`/`details`. Response wrapper uses `subtypes` (not `remarks`). Anti-corruption layer responsibility sits in the mobile client.

**Mobile app updated (canonical field alignment):** `useTransactions.ts`, `useTransactionDetail.ts`, `walletDataSource.ts`, `homeDataSource.ts` all updated to read backend-native field names. `tx.reference` → id, `tx.type === 'deposit'` → isCredit, `tx.subtype` → category input. `subtypes` replaces `remarks` in filter UI.

### Session **2026-03-28** — MTN MoMo reconciliation command (Phase 15 follow-up)

| Area | What | Files |
|------|------|--------|
| Reconciliation | `ReconcileMtnMomoTransactions` command — polls MTN status API for pending disbursements where wallet was debited but no callback arrived; refunds wallet on FAILED; skips rows younger than `--min-age` (default 15 min); `--dry-run` mode; `--chunk` batch size | `app/Console/Commands/ReconcileMtnMomoTransactions.php` |
| Safety | `DB::transaction` + `lockForUpdate()` per row — prevents double-refund under concurrent cron ticks; `wallet_refunded_at` used as idempotency guard | same |
| Safety | `MtnMomoTransaction::normaliseRemoteStatus()` maps MTN's varied status strings; `Log::critical` on failed refund (funds-loss path); `Log::error` on MTN API errors | same |
| Schedule | `everyFifteenMinutes()->withoutOverlapping()->appendOutputTo('mtn-reconcile.log')->onFailure(Log::critical)` | `routes/console.php` |
| Testability | Removed `final` from `MtnMomoClient` — Mockery cannot proxy final classes; it is a service, not a value object | `app/Domain/MtnMomo/Services/MtnMomoClient.php` |
| Tests | 8 `#[Large]` Pest tests — min-age skip, SUCCESSFUL path, FAILED+refund, double-refund prevention, still-pending, non-disbursement skip, refund-throws (exit code 1 + `Log::critical`), dry-run no-writes | `tests/Feature/Console/Commands/ReconcileMtnMomoTransactionsTest.php` |

**Design:** Age gate is on `wallet_debited_at` (not `created_at`) — gives MTN callbacks time after the wallet was actually debited before the cron intervenes. Exit code 1 is returned when any rows error, so monitoring alerts fire on partial failures without aborting the whole batch.

---

### Session **2026-03-29** — Domain idempotency guard + MTN disbursement failure refund

| Area | What | Files |
|------|------|--------|
| OperationRecord migration | `operation_records` table: `(user_id, operation_type, idempotency_key)` UNIQUE index; `payload_hash` SHA-256; `status` enum(pending/completed/failed); `result_payload` JSON nullable | `database/migrations/2026_03_29_100000_create_operation_records_table.php` |
| OperationRecord model | ULID primary key, typed `@property` annotations for PHPStan, `result_payload` cast to `array` | `app/Domain/Shared/OperationRecord/OperationRecord.php` |
| OperationPayloadMismatchException | Maps to HTTP 409 — thrown when idempotency key is reused with a different payload hash | `app/Domain/Shared/OperationRecord/Exceptions/OperationPayloadMismatchException.php` |
| OperationRecordService | `guardAndRun(int $userId, string $type, string $key, string $payloadHash, Closure $fn)`: cache hit returns `result_payload`, hash mismatch throws, `UniqueConstraintViolationException` caught and re-read, marks completed/failed around `$fn()` | `app/Domain/Shared/OperationRecord/OperationRecordService.php` |
| AuthorizedTransactionManager | `initiate()` accepts optional `$idempotencyKey`; stores as `_idempotency_key` sentinel in payload; `finalizeAtomically()` calls `executeWithIdempotencyGuard()` → `guardAndRun()` only when key is present (backward-compat for keyless callers) | `app/Domain/AuthorizedTransaction/Services/AuthorizedTransactionManager.php` |
| MTN disbursement refund | `CallbackController` detects `TYPE_DISBURSEMENT` + `STATUS_FAILED` + `wallet_debited_at ≠ null` + `wallet_refunded_at == null`; calls private `refundDisbursementIfNeeded()` with `lockForUpdate` inside `DB::transaction` | `app/Http/Controllers/Api/Compatibility/Mtn/CallbackController.php` |
| KYC fix | `MtnMomoControllersTest` setUp now creates payer with `kyc_status='approved'` — required by `kyc_approved` middleware on all MTN routes | `tests/Feature/Http/Controllers/Api/Compatibility/Mtn/MtnMomoControllersTest.php` |
| Tests | 7 test scenarios: normal path, failure marking, cache hit, hash mismatch 409, concurrent same-key unique-constraint retry, disbursement FAILED triggers refund, skip-refund when wallet not debited | `tests/Unit/Domain/Shared/OperationRecord/OperationRecordServiceTest.php`, `tests/Feature/Http/Controllers/Api/Compatibility/Mtn/MtnMomoControllersTest.php` |

**Guard design:** `OperationRecord` participates in `finalizeAtomically()`'s outer DB transaction. On success, `completed` record is committed alongside handler writes — prevents duplicate execution even after the HTTP-layer cache (24 h) expires. On failure, the outer rollback reverts the `OperationRecord` (stays non-existent), allowing retries. Concurrent protection for within-session races is still provided by the `authorized_transactions` atomic claim.

**Disbursement refund safety:** `lockForUpdate` on `mtn_momo_transactions` row prevents double-refund from concurrent callback vs. future reconciliation cron. `Log::critical` emitted if `walletOps.deposit()` throws (funds-loss path).

---

### Session **2026-03-29** — Domain idempotency wiring into compat controllers

| Area | What | Files |
|------|------|--------|
| Task 1 | `SendMoneyStoreController` — extract `Idempotency-Key` / `X-Idempotency-Key` header and pass as 5th arg to `AuthorizedTransactionManager::initiate()` | `app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php` |
| Task 1 | `RequestMoneyStoreController` — same; key extracted before `DB::transaction`, threaded through closure `use` list | `app/Http/Controllers/Api/Compatibility/RequestMoney/RequestMoneyStoreController.php` |
| Task 1 | `RequestMoneyReceivedStoreController` — same; key threaded through existing `DB::transaction` closure | `app/Http/Controllers/Api/Compatibility/RequestMoney/RequestMoneyReceivedStoreController.php` |
| Task 2 (audit) | `ReconcileMtnMomoTransactions` — already has `whereNull('wallet_refunded_at')` in the initial query (line 64). No change required. | `app/Console/Commands/ReconcileMtnMomoTransactions.php` |
| KYC fix | All three send/request-money test setUp methods now create users with `kyc_status='approved'`; `$other` and `$frozenRecipient` inline users in `RequestMoneyReceivedStoreControllerTest` also fixed — previously all tests returned 403 instead of testing business logic | test files (see below) |
| Tests | `test_store_embeds_idempotency_key_in_payload` (×3 controllers) — sends UUID idempotency key; asserts `payload['_idempotency_key']` persisted; validates both `Idempotency-Key` and `X-Idempotency-Key` header variants | `SendMoneyStoreControllerTest.php`, `RequestMoneyStoreControllerTest.php`, `RequestMoneyReceivedStoreControllerTest.php` |
| Tests | `test_second_verify_with_same_idempotency_key_returns_cached_result_without_re_executing` — seeds completed `OperationRecord` for the same key; mocks `WalletOperationsService` to never call `transfer`; verifies PIN returns cached result without handler re-execution | `SendMoneyStoreControllerTest.php` |

**Effect:** Without this wiring, `executeWithIdempotencyGuard()` inside `finalizeAtomically()` never activated — `_idempotency_key` was always absent from the payload. Domain-level deduplication is now live for all three money-moving initiate flows.

**Idempotency key format constraint (discovered):** `IdempotencyMiddleware::isValidIdempotencyKey()` requires either a UUID or an alphanumeric string of 16–64 chars (`[a-zA-Z0-9_-]{16,64}`). Short keys like `'idem-send-1'` (< 16 chars) return 400. Tests use UUID-format keys.

---

## Last updated

- **2026-03-29 (Phase 17 + Phase 19):** Phase 17 Option A: `migration_delta_log` table + `MigrateLegacyBalances` command + legacy DB connection. Phase 19: `Phase19LoadTest` (4 tests passing) + k6 staging scripts. PHPStan 0 errors on new files. Compat suite 73/73 passing. Branch: `main`.
- **2026-03-29 (Phase 10 controllers created + cleanup):** Created all 4 missing Phase 10 controllers (`MobileAuthController`, `AuthorizationController`, `CountriesController`, `DeviceTokenController`) with full OA docs. Added User model `@property` annotations for new fields. Removed redundant migrations and outdated docs. PHPStan 0 errors, CS Fixer clean. Branch: `main`.
- **2026-03-29 (Phase 10 mobile app):** Updated `maphapayrn`: `apiClient.ts` refresh endpoint + response path, `authStore.ts` login/logout/refreshUser/getOperatingCountryId + User interface, `register.tsx` all 3 step handlers, `useProfileSettings.ts` device token endpoint. Token refresh now POSTs to `/api/auth/refresh` with `data.data.access_token`. Login → `/api/auth/mobile/login`. Logout → POST `/api/auth/logout`. Refresh user → `/api/auth/user`. Countries → `/api/countries`. Device tokens → `/api/device-tokens`. `User` interface updated with FinAegis fields (uuid, kyc_status, etc.).
- **2026-03-29 (Phase 10 backend complete):** All 4 controllers confirmed implemented, migrations/models/services verified, PHPStan + CS Fixer clean, 16 tests pass, PDO deprecation fixed. Mobile app updates (apiClient + authStore) remain.

### Session **2026-03-29** — Phase 17 Stage 1 Backfill + Phase 19 Performance Testing

| Area | What | Files |
|------|------|-------|
| Phase 17 | `migration_delta_log` migration — `legacy_user_id`, `currency`, `amount_major`, `direction`, `legacy_trx_id`, `legacy_table`, `legacy_created_at`, `captured_at`; uses `try/catch` so it skips gracefully when legacy DB is unavailable (SQLite test environments) | `database/migrations/2026_03_29_190000_create_migration_delta_log_table.php` |
| Phase 17 | `MigrateLegacyBalances` command — `legacy:migrate-balances [--dry-run][--snapshot][--chunk=500][--threshold=0.01][--cohort=ids]`: loads identity map, reads legacy `users.balance` + `migration_balance_snapshots`, applies `migration_delta_log` deltas, parity checks each user before enabling FinAegis writes; exits FAILURE if any user exceeds parity threshold | `app/Console/Commands/MigrateLegacyBalances.php` |
| Phase 17 | `database.connections.legacy` config — `LEGACY_DB_*` env vars (url, host, port, database, username, password, socket, charset, collation); read-only connection for Phase 17 + Phase 14 | `config/database.php` |
| Phase 19 | `Phase19LoadTest` — 4 `#[Large]` tests covering: send-money idempotency (same key replays, unique keys create separate rows, no deadlock), balance consistency after verification; MTN/balance-read scenarios deferred to k6 staging scripts | `tests/Feature/Financial/Phase19LoadTest.php` |
| Phase 19 | k6 load test scripts — `docs/phase-19-load-test-k6.md` with 4 scenarios: send-money throughput (100 VUs), MTN initiation burst (50 VUs), MTN callback flood (20 VUs), balance-read under write load; P95 SLA thresholds per plan line 2041 | `docs/phase-19-load-test-k6.md` |
| PHPStan | All new files: 0 errors | various |
| CS Fixer | Applied to `MigrateLegacyBalances.php`, `Phase19LoadTest.php`, migration | various |

**PHP binary:** `/Users/Lihle/Library/Application Support/Herd/bin/php85`

**Tests:** 73 compat tests pass, 4 Phase19LoadTest pass (SQLite), all financial tests pass.

**Branch:** `main` — 17 commits ahead of `origin/main`.

**Note:** Phase 17 `MigrateLegacyBalances` command requires:
1. `LEGACY_DB_*` env vars pointing to legacy MaphaPay MySQL
2. `migration_identity_map` populated (run `legacy:migrate-social-graph --table=identity_map` first)
3. `migration_delta_log` observer running on legacy DB to capture post-snapshot transactions
4. `SZL` asset seeded in FinAegis

**Note:** Phase 19 MTN tests (`docs/phase-19-load-test-k6.md`) require k6 + staging environment with real MySQL. PHP tests validate idempotency and atomicity behaviors only.

**Last updated**

- **2026-03-29 (Phase 17 + Phase 19 implemented):** Phase 17 Option A: `migration_delta_log` table + `MigrateLegacyBalances` command + legacy DB connection. Phase 19: `Phase19LoadTest` (4 tests, all passing on SQLite) + k6 staging scripts doc. PHPStan 0 errors on new files, CS Fixer applied. Compat suite: 73/73 passing. Branch: `main`.
