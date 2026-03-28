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

> **CI is currently green** (2026-03-28). PHP binary: use `php85` via Herd (`/Users/Lihle/Library/Application Support/Herd/bin/php85`) — `php84` has a dyld error on this machine.

```bash
PHP85="/Users/Lihle/Library/Application Support/Herd/bin/php85"
XDEBUG_MODE=off "$PHP85" vendor/bin/phpstan analyse --memory-limit=2G
"$PHP85" vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php
"$PHP85" vendor/bin/pest tests/Feature/Http/Controllers/Api/TransferControllerTest.php \
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
| **E** | `app/Http/Controllers/API/MobilePayment/PaymentIntentController.php`, `app/Http/Requests/MobilePayment/CreatePaymentIntentRequest.php` | Idempotency header naming §Phase 1 / 5 |
| **G** | `config/machinepay.php`, `config/agent_protocol.php` (SZL default — **risky**; verify GCU domains first) | Phase 3.2 |

**`app/Http/Controllers/Api/Compatibility/` directory is now live** — do not scaffold it again (VerifyOtp/VerifyPin already exist there).

**Do not parallelize:** MTN controllers, social-money domain, auth compatibility controllers — all depend on Phase 11 (`AuthorizedTransactionManager`) being merged first.

---

## In flight / next (serial — implement in this order)

1. ~~**Phase 10 — Auth gap assessment**~~ **Documented** (2026-03-28): See **Phase 10 findings** above. No compat auth controllers until legacy contract is known.
2. ~~**Phase 18 — `routes/api-compat.php`**~~ **Done** (2026-03-28): `bootstrap/app.php` + `config/maphapay_migration.php` + gated verification/send/request store routes.
3. ~~**Phase 5 — `SendMoneyStoreController`**~~ **Done** (2026-03-28).
4. ~~**Phase 5 — `RequestMoneyStoreController`**~~ **Done** (2026-03-28).
5. ~~**Phase 5 — request-money accept / reject / history**~~ **Done** (2026-03-28). ~~**Phase 5 — scheduled send**~~ **Done** (2026-03-28): `ScheduledSendStoreController`, `ScheduledSendIndexController`, `ScheduledSendCancelController` + `scheduled_sends` + routes + handler `executed` update + tests.
6. ~~**Phase 15 — MTN config + controllers**~~ **Done** (2026-03-28): `config/mtn_momo.php`, `enable_mtn_momo`, `MtnMomoClient`, `mtn_momo_transactions`, four compat controllers, IPN callback token verification, feature tests.
7. **MTN reconciliation command** (legacy `ReconcileMtnMomoTransactions`) — not started.

## In flight / next (old — serial or after parallel merge)

- **Phase 5 compatibility controllers** — blocked on stabilizing Phase 3 + tests green.
- **Domain operation idempotency** (`operation record` table) — single owner; new migrations.
- **MTN / wallet-linking** — separate bounded context.

---

## How to update this doc

1. Append to **Completed** with date and file list.
2. Remove or shrink **Parallel workstreams** when merged.
3. Add **blockers** (e.g. “Transfer tests failing: …”) so the next agent reads one section.

---

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

## Last updated

- **2026-03-28 (Transaction History + Dashboard — canonical fields):** `GET /api/transactions` returns `type`/`subtype`/`reference`/`description` (no legacy `trx_type`/`remark` aliases). `GET /api/dashboard` returns user + balance. Mobile RN updated: `useTransactions`, `useTransactionDetail`, `walletDataSource`, `homeDataSource` all read canonical field names. 15 tests green (9 tx + 6 dashboard), PHPStan 0 errors, CS-Fixer clean.
- **2026-03-28 (Phase 15 MTN MoMo — hardening):** Post-review fixes: `wallet_refunded_at` column; no MTN error body in API responses; `Log::critical` on failed refund; callback 404→200 for unknown refs; idempotency race fix in disbursement; `withoutMiddleware` route fix (class + alias); `WalletOperationsService` mocked in tests; all 7 tests green. PHPStan 0 errors, CS-Fixer clean.
- **2026-03-28 (Phase 15 MTN MoMo):** Config `mtn_momo.php`, migration flag `enable_mtn_momo`, `MtnMomoClient` + collection settlement, four `/api/mtn/*` compat routes (callback unauthenticated + `X-Callback-Token`), `MtnMomoControllersTest`. PHPStan clean on touched paths. Local SQLite full-migration runs may still hit 10s statement timeouts — CI/MySQL per checklist.
- **2026-03-28 (scheduled send + Phase 10 notes):** `scheduled_sends` migration/model; three compat controllers; `ScheduledSendHandler` sets `executed` after transfer; `enable_scheduled_send` config + api-compat routes (idempotent store). Feature tests added. Phase 10 auth/schema findings appended. Local SQLite test runs may still hit migration timeouts — use MySQL/CI per checklist.
- **2026-03-28 (scheduled send post-review hardening):** Cancel-then-OTP exploit fixed (`lockForUpdate` pre-transfer guard + `STATUS_CANCELLED` propagation to `authorized_transaction`); transfer failure marks `scheduled_send` `failed`; explicit field projection in index; `before:+1 year` on `scheduled_for`; 5 new test cases. `AuthorizedTransaction::STATUS_CANCELLED` added.
- **2026-03-28 (request-money hardening):** Post-review fixes — `STATUS_FULFILLED` closes double-accept; handler marks request fulfilled + null guard; `DB::transaction` in `ReceivedStoreController`; self-acceptance guard; `idempotency` on `received-store` route; `RejectController` error helpers. 3 new tests (14 total). PHPStan 0 errors.
- **2026-03-28 (request-money flow):** Phase 5 `received-store`, `reject`, `history`, `received-history` compat controllers; `STATUS_REJECTED`; grouped `enable_request_money` routes; feature tests. PHP CS Fixer on touched files.
- **2026-03-28 (later):** Phase 18 `api-compat` routes (verification + send-money + request-money store), `MoneyRequest` + `RequestMoneyHandler`, compat controllers, feature tests. Registration via `bootstrap/app.php` (Laravel 12).
- **2026-03-28:** CI green (PHPStan + CS Fixer + tests). Built Phase 12 (`MoneyConverter` + `MajorUnitAmountString` rule). Built Phase 11 (`AuthorizedTransaction` domain: migration, model, 3 handlers, `AuthorizedTransactionManager`, `VerifyOtpController`, `VerifyPinController`). Updated plan with Phases 10–19 (gaps review). Updated `docs/maphapay-backend-replacement-plan.md`.
- **2026-03-27:** Documented parallel streams A–D as completed; added merge checklist and next exclusive streams E–G.
