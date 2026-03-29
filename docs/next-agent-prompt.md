# MaphaPay → FinAegis — Next Agent Handoff Prompt

You are continuing the MaphaPay → FinAegis backend migration.

**Working directory:** `/Users/Lihle/Development/Coding/maphapay-backoffice`
**Current branch:** `main` — 13 commits ahead of `origin/main` (not pushed yet — user will push manually)
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
| Domain idempotency | ✅ | `OperationRecord` + `OperationRecordService::guardAndRun()` wired into `AuthorizedTransactionManager`; `operation_records` migration |
| MTN disbursement refund | ✅ | `CallbackController` auto-refunds on `FAILED` disbursement with `lockForUpdate` guard |
| Idempotency key wiring | ✅ | `SendMoneyStoreController`, `RequestMoneyStoreController`, `RequestMoneyReceivedStoreController` all extract `Idempotency-Key` / `X-Idempotency-Key` and pass as 5th arg to `AuthorizedTransactionManager::initiate()` |
| Phase 19 | ✅ | Full compat suite passes on SQLite (281 assertions). Fixed 3 tests missing `kyc_status='approved'` in request-money history and reject controllers. |
| Stream G | ✅ audited & skipped | `config/machinepay.php` + `config/agent_protocol.php` — do **not** change these |

---

## What still needs to be done

### 1. Phase 10 — Auth compatibility controllers (blocked)

**Blocked until:** The legacy MaphaPay mobile contract for login/register is known.

**What to do when unblocked:**
1. Diff against old MaphaPay OpenAPI spec or captured production requests.
2. If the existing `routes/api.php` auth matches the mobile contract — no compat shims needed.
3. If they diverge — add thin compat controllers in `app/Http/Controllers/Api/Compatibility/Auth/`.
4. Do NOT add aliases — backend is source of truth; mobile adapts.

**Key findings already documented (2026-03-28):**
- `personal_access_tokens` — standard Sanctum schema; mobile Bearer auth works if token creation matches.
- `transaction_pin` column — confirm it exists in the real DB; `AuthorizedTransactionManager::verifyPin()` requires it. Not visible in tracked migrations (may be in an untracked migration).
- Login/Register shims — only needed if URLs, field names, or error JSON diverge from current Fortify/Jetstream.

---

### 2. Phase 19 (follow-up) — Full-domain MySQL smoke test

The compat sub-suite (`tests/Feature/Http/Controllers/Api/Compatibility/`) is fully green on SQLite.

For a **full-domain** MySQL smoke test (recommended before production cutover):
- Use `phpunit.ci.xml` or a local MySQL instance.
- Ensure all migrations run in order (no FK ordering issues).
- Fix any failures (likely nullable FK constraints or timestamp precision).

This is **not blocking** for further compat work — only needed before live traffic.

---

### 3. PDO::MYSQL_ATTR_SSL_CA deprecation (PHP 8.5 noise)

Every test run logs: `Constant PDO::MYSQL_ATTR_SSL_CA is deprecated since PHP 8.4`

**Root cause:** `config/database.php:71` unconditionally references `PDO::MYSQL_ATTR_SSL_CA` even on SQLite test runs.

**Fix (optional but clean):**
```php
// config/database.php — inside the mysql connection options array:
'options' => extension_loaded('pdo_mysql') ? array_filter([
    PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
]) : [],
```
This already exists — but `PDO::MYSQL_ATTR_SSL_CA` is referenced even when evaluating the condition. Wrap the constant reference:
```php
'options' => (extension_loaded('pdo_mysql') && defined('PDO::MYSQL_ATTR_SSL_CA'))
    ? array_filter([PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA')])
    : [],
```
This eliminates the 73 deprecation warnings per test run without changing runtime behaviour.

---

## CI commands

```bash
PHP85="/Users/Lihle/Library/Application Support/Herd/bin/php85"

# Static analysis
XDEBUG_MODE=off "$PHP85" vendor/bin/phpstan analyse --memory-limit=2G

# Code style
"$PHP85" vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php

# Full compat suite (green on SQLite)
"$PHP85" vendor/bin/pest tests/Feature/Http/Controllers/Api/Compatibility/ --stop-on-failure

# Key unit tests
"$PHP85" vendor/bin/pest \
  tests/Unit/Domain/Shared/Money/MoneyConverterTest.php \
  tests/Unit/Domain/Shared/OperationRecord/OperationRecordServiceTest.php
```

---

## Key conventions (from CLAUDE.md — do not deviate)

- `declare(strict_types=1)` at top of every PHP file.
- Import order: `App\Domain` → `App\Http` → `App\Models` → `Illuminate` → Third-party.
- Compat controllers return **canonical domain field names** — never legacy aliases.
- Error envelope shape: `{ "status": "error", "remark": "<remark>", "message": ["..."] }`.
- Tests: always pass `['read', 'write', 'delete']` abilities to `Sanctum::actingAs()`.
- **Compat test users must have `kyc_status = 'approved'`** for money-moving routes (routes in `kyc_approved` middleware groups: send-money, request-money, MTN MoMo).
- **Idempotency keys** passed to HTTP endpoints must be UUID format or alphanumeric 16–64 chars.
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
- **`kyc_approved` middleware** — blocks requests with 403 if user lacks `kyc_status = 'approved'`.
  Always set this on test users in compat tests for routes inside that middleware group.
