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
  tests/Unit/Domain/Shared/Money/MoneyConverterTest.php
```

1. **ExchangeRateService:** Confirm `AccountBalanceController` injection/calls match real `ExchangeRateService` API (method names, return types) — carried over from stream A.
2. **`authorized_transactions` migration:** Run `php artisan migrate` in a local/staging env before wiring routes to confirm no schema conflicts.

---

## Parallel workstreams still **safe** (exclusive files — pick up next)

These do **not** touch files already changed above; assign one agent per row.

| Next stream | Exclusive files | Plan ref |
|-------------|-----------------|----------|
| **E** | `app/Http/Controllers/Api/MobilePayment/PaymentIntentController.php`, `app/Http/Requests/MobilePayment/CreatePaymentIntentRequest.php` | Idempotency header naming §Phase 1 / 5 |
| **G** | `config/machinepay.php`, `config/agent_protocol.php` (SZL default — **risky**; verify GCU domains first) | Phase 3.2 |
| **H** | `routes/api-compat.php` (new file) + `app/Providers/RouteServiceProvider.php` registration | Phase 18 — **single owner only** |

**`app/Http/Controllers/Api/Compatibility/` directory is now live** — do not scaffold it again (VerifyOtp/VerifyPin already exist there).

**Do not parallelize:** MTN controllers, social-money domain, auth compatibility controllers — all depend on Phase 11 (`AuthorizedTransactionManager`) being merged first.

---

## In flight / next (serial — implement in this order)

1. **Phase 10 — Auth gap assessment** (quick, ~1h): Check if legacy and FinAegis `users` table schemas are compatible for the shared-table approach. Check `personal_access_tokens` table. Determine if compatibility auth controllers are needed for login/register.
2. **Phase 18 — `routes/api-compat.php`** (new file, single owner): Register all Phase 5 compatibility routes behind `MAPHAPAY_MIGRATION_ENABLE_*` env flags. Register the two already-built controllers (`VerifyOtp`, `VerifyPin`).
3. **Phase 5 — `SendMoneyStoreController`** (first full compatibility controller): Uses `AuthorizedTransactionManager::initiate()` + `dispatchOtp()`. Proves the full two-step flow end-to-end.
4. **Phase 5 — `RequestMoneyStoreController`** + `RequestMoneyReceivedStoreController`
5. **Phase 15 — MTN config file** (`config/mtn_momo.php`) + env vars — before any MTN controller work.
6. **MTN controllers** (request-to-pay, disbursement, status, IPN callback)

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

## Last updated

- **2026-03-28:** CI green (PHPStan + CS Fixer + tests). Built Phase 12 (`MoneyConverter` + `MajorUnitAmountString` rule). Built Phase 11 (`AuthorizedTransaction` domain: migration, model, 3 handlers, `AuthorizedTransactionManager`, `VerifyOtpController`, `VerifyPinController`). Updated plan with Phases 10–19 (gaps review). Updated `docs/maphapay-backend-replacement-plan.md`.
- **2026-03-27:** Documented parallel streams A–D as completed; added merge checklist and next exclusive streams E–G.
