# Next Agent Prompt

**Read first:** `docs/maphapay-backend-replacement-progress.md`

## Current state

Branch `main` is **20 commits ahead of `origin/main`**. Latest commits:
- `feat: add Phase 17 migration_delta_log + MigrateLegacyBalances command + Phase 19 load test files`
- `feat: implement Phase 17 backfill + Phase 19 load test infrastructure`

### What was just completed

**Phase 17 — Stage 1 Backfill (Option A — Rolling snapshot with delta reconciliation):**
- `migration_delta_log` table migration — `legacy_user_id`, `currency`, `amount_major`, `direction`, `legacy_trx_id`, `legacy_table`, `legacy_created_at`, `captured_at`. Uses `try/catch` so it skips gracefully when legacy DB is unavailable (SQLite test environments).
- `MigrateLegacyBalances` command — `legacy:migrate-balances [--dry-run][--snapshot][--chunk=500][--threshold=0.01][--cohort=ids]`. Loads identity map, reads legacy `users.balance` + `migration_balance_snapshots`, applies `migration_delta_log` deltas, parity-checks each user before enabling FinAegis writes. Exits FAILURE if any user exceeds threshold.
- `database.connections.legacy` config — `LEGACY_DB_*` env vars (url, host, port, database, username, password, socket, charset, collation).

**Phase 19 — Performance & Load Testing:**
- `Phase19LoadTest` — 4 `#[Large]` tests: send-money idempotency (same key replays, unique keys create separate rows, no deadlock on `authorized_transactions`), balance consistency after verification.
- `docs/phase-19-load-test-k6.md` — k6 scripts for staging: send-money 100 VU throughput, MTN burst 50 VU, MTN callback flood 20 VU, balance-read under write load. P95 SLA targets from plan line 2041.
- MTN HTTP-level tests deferred to k6 staging (PHP single-threaded test environment cannot exercise true concurrent HTTP).

**Tests:** 73 compat tests pass, 4 Phase19LoadTest pass (SQLite).

**PHP binary:** `/Users/Lihle/Library/Application Support/Herd/bin/php85`

---

## Remaining work (priority order)

### 1. Full suite MySQL smoke test (highest operational safety)

Run against MySQL (not SQLite) before any production cutover:

```bash
PHP85="/Users/Lihle/Library/Application Support/Herd/bin/php85"
XDEBUG_MODE=off "$PHP85" vendor/bin/pest --configuration=phpunit.ci.xml --parallel
```

SQLite may timeout for the full domain suite. MySQL required for this run.

### 2. Manual QA — Login/OTP flows

Test login, OTP verification, profile completion, and forgot PIN flows end-to-end with Postman or a real device:
- `POST /api/auth/mobile/login` — auto-register + OTP send
- `POST /api/auth/mobile/verify-otp` — OTP verification
- `POST /api/auth/mobile/resend-otp` — OTP resend
- `POST /api/auth/mobile/complete-profile` — profile completion
- `POST /api/auth/mobile/forgot-pin` — forgot PIN
- `POST /api/auth/mobile/verify-reset-code` — verify reset code
- `POST /api/auth/mobile/reset-pin` — reset PIN
- `GET /api/auth/authorization` — pending auth steps
- `GET /api/countries` — active countries list
- `POST /api/device-tokens` — device token storage

### 3. Phase 17 prerequisites before running `legacy:migrate-balances`

Ensure these are in place before the command can succeed:
1. Set `LEGACY_DB_*` env vars in `.env` pointing to legacy MaphaPay MySQL
2. Run `php artisan legacy:migrate-social-graph --table=identity_map` to populate `migration_identity_map`
3. Implement a migration observer on the legacy DB that writes to `migration_delta_log` (not yet built — requires a separate observer process or DB triggers)
4. Seed the `SZL` asset in FinAegis: `Asset::firstOrCreate(['code' => 'SZL'], [...])`
5. Take initial balance snapshot in `migration_balance_snapshots` table (legacy side)

### 4. Push to remote when ready

```bash
git push origin main
```

---

## Running tests

```bash
# Quick validation
PHP85="/Users/Lihle/Library/Application Support/Herd/bin/php85"
XDEBUG_MODE=off "$PHP85" vendor/bin/php-cs-fixer fix
XDEBUG_MODE=off "$PHP85" vendor/bin/phpstan analyse --memory-limit=2G
XDEBUG_MODE=off "$PHP85" vendor/bin/pest --parallel

# Mobile app TypeScript check
cd maphapayrn && npx tsc --noEmit
```

---

## Commit after completing any slice

```bash
git add -u && git commit -m "chore: [description of what was done]

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
```

(End of file)
