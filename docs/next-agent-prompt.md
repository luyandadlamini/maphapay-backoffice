# MaphaPay → FinAegis — Next Agent Handoff Prompt

You are continuing the MaphaPay → FinAegis backend migration.

**Working directory:** `/Users/Lihle/Development/Coding/maphapay-backoffice`
**Current branch:** `feat/phase-16-rate-limiting` (CI green, PHPStan 0 errors, CS-Fixer clean)
**PHP binary:** `/Users/Lihle/Library/Application\ Support/Herd/bin/php85` (use this — php84 has a dyld error)

Read `docs/maphapay-backend-replacement-progress.md` before touching anything — it is the authoritative
progress handoff. The immediately relevant context is summarised below.

---

## What has been built (do not re-implement)

| Phase | Status | Key files |
|-------|--------|-----------|
| Phase 3.1 | ✅ | `database/seeders/AssetSeeder.php` — SZL seeded |
| Phase 3.3 | ✅ | `TransactionProjection::formatted_amount` uses `Asset.precision` |
| Phase 11 | ✅ | `AuthorizedTransaction` domain: migration, model, 3 handlers, `AuthorizedTransactionManager`, `VerifyOtpController`, `VerifyPinController` |
| Phase 12 | ✅ | `MoneyConverter` (bcmath), `MajorUnitAmountString` validation rule |
| Phase 5 | ✅ | `SendMoneyStoreController`, `RequestMoneyStoreController`, received-store, reject, history, scheduled send — all with tests |
| Phase 15 | ✅ | MTN MoMo: `MtnMomoClient`, 4 compat controllers, reconciliation cron, callback IPN, 17 tests |
| Phase 16 | ✅ | Per-user throttle on `send-money/store` (10/min) and `mtn/disbursement` (5/min); 429 compat envelope; 4 tests |
| Phase 18 | ✅ | `routes/api-compat.php` — all money-moving routes, migration-flag gated |
| Transactions / Dashboard | ✅ | `GET /api/transactions`, `GET /api/dashboard` compat endpoints |
| Phase 13 | ✅ already existed | `ExecuteScheduledSends` command |
| Phase 14 | ✅ already existed | `MigrateLegacySocialGraph` command |
| Idempotency | ✅ | `IdempotencyMiddleware` accepts both `Idempotency-Key` and `X-Idempotency-Key` |
| Stream G | ✅ audited & skipped | `config/machinepay.php` + `config/agent_protocol.php` — do **not** change these |
| Domain idempotency | ✅ | `OperationRecord` + `OperationRecordService::guardAndRun()` wired into `AuthorizedTransactionManager`; `operation_records` migration |
| MTN disbursement refund | ✅ | `CallbackController` auto-refunds on `FAILED` disbursement with `lockForUpdate` guard |
| Idempotency key wiring | ✅ | `SendMoneyStoreController`, `RequestMoneyStoreController`, `RequestMoneyReceivedStoreController` all extract `Idempotency-Key` / `X-Idempotency-Key` and pass as 5th arg to `AuthorizedTransactionManager::initiate()` |

---

## What still needs to be done

### 1. Phase 19 — End-to-end smoke tests on MySQL

**Blocked by:** SQLite `:memory:` migration timeout (10 s statement limit kills full-suite runs locally).

**What to do:**
- Run the full Pest suite against MySQL using `phpunit.ci.xml` or a local MySQL instance.
- Fix any failures found (likely migration ordering, nullable FK constraints, or timestamp precision).
- If you cannot set up MySQL locally, document blockers and move to the next item.

---

### 2. Phase 10 — Auth compatibility controllers (blocked)

**Blocked until:** The legacy MaphaPay mobile contract for login/register is known.

**What to do when unblocked:**
1. Diff against old MaphaPay OpenAPI spec or captured production requests.
2. If the existing `routes/api.php` auth matches the mobile contract — no compat shims needed.
3. If they diverge — add thin compat controllers in `app/Http/Controllers/Api/Compatibility/Auth/`.
4. Do NOT add aliases — backend is source of truth; mobile adapts.

---

### 3. Merge and ship `feat/phase-16-rate-limiting`

The branch is feature-complete and CI-clean. Open a PR, get it merged to `main`, then continue
the remaining items on a fresh branch.

**Merge checklist:**
- PHPStan 0 errors ✅
- CS-Fixer clean ✅
- Key test suites pass (see CI commands below)
- No uncommitted changes

---

## CI commands

```bash
PHP85="/Users/Lihle/Library/Application Support/Herd/bin/php85"

# Static analysis
XDEBUG_MODE=off "$PHP85" vendor/bin/phpstan analyse --memory-limit=2G

# Code style
"$PHP85" vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php

# Money-moving compat + OperationRecord tests
"$PHP85" vendor/bin/pest \
  tests/Feature/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreControllerTest.php \
  tests/Feature/Http/Controllers/Api/Compatibility/RequestMoney/RequestMoneyStoreControllerTest.php \
  tests/Feature/Http/Controllers/Api/Compatibility/RequestMoney/RequestMoneyReceivedStoreControllerTest.php \
  tests/Feature/Http/Controllers/Api/Compatibility/Mtn/MtnMomoControllersTest.php \
  tests/Unit/Domain/Shared/OperationRecord/OperationRecordServiceTest.php

# Full compat test suite (may hit SQLite timeout — use MySQL if it aborts)
"$PHP85" vendor/bin/pest tests/Feature/Http/Controllers/Api/Compatibility/ --stop-on-failure
```

---

## Key conventions (from CLAUDE.md — do not deviate)

- `declare(strict_types=1)` at top of every PHP file.
- Import order: `App\Domain` → `App\Http` → `App\Models` → `Illuminate` → Third-party.
- Compat controllers return **canonical domain field names** — never legacy aliases.
- Error envelope shape: `{ "status": "error", "remark": "<remark>", "message": ["..."] }`.
- Tests: always pass `['read', 'write', 'delete']` abilities to `Sanctum::actingAs()`.
- **Compat test users must have `kyc_status = 'approved'`** for money-moving routes.
- **Idempotency keys** passed to HTTP endpoints must be UUID format or alphanumeric 16–64 chars — the `IdempotencyMiddleware` validates this and returns 400 otherwise.
- Commits: `feat:` / `fix:` / `test:` prefix + `Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>`.
- Work in feature branches — never commit directly to `main`.
- Update `docs/maphapay-backend-replacement-progress.md` after every completed slice.

---

## Architecture reminders

- **`AuthorizedTransactionManager::initiate()`** — 5th param `string $idempotencyKey = ''`. When
  provided, stores as `'_idempotency_key'` in `payload`; `finalizeAtomically()` calls
  `OperationRecordService::guardAndRun()` via `executeWithIdempotencyGuard()`.
- **`OperationRecordService::guardAndRun(int $userId, string $type, string $key, string $payloadHash, Closure $fn): array`**
  — cache hit returns `result_payload`; hash mismatch throws `OperationPayloadMismatchException`;
  unique-constraint race retried; marks completed/failed around `$fn()`.
- **`MoneyConverter`** — all amount conversions (bcmath, half-up rounding).
- **`WalletOperationsService`** — mock in HTTP-layer tests to avoid workflow dispatch mismatches.
- **`config/machinepay.php` and `config/agent_protocol.php`** — do NOT change these for MaphaPay.
- **SQLite `:memory:` timeouts** — intermittent 10 s limit hit during full migration set; run on
  MySQL via `phpunit.ci.xml` for Phase 19 smoke tests.
