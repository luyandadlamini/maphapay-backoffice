# Next Agent Prompt

**Read first:** `docs/maphapay-backend-replacement-progress.md`

## Current state

Branch `main` is 17 commits ahead of `origin/main`. Latest commit: `chore: remove duplicate Phase 10 files` (2026-03-29).

### What was just completed

**Phase 10 backend controllers — actually created:**
- `MobileAuthController` — login (auto-register + OTP), verifyOtp, resendOtp, completeProfile, forgotPin, verifyResetCode, resetPin
- `AuthorizationController` — GET /api/auth/authorization (pending steps: mobile/email/kyc), POST resend
- `CountriesController` — GET /api/countries (active countries list)
- `DeviceTokenController` — POST /api/device-tokens (stores in mobile_preferences)
- User model `@property` annotations added for `mobile`, `dial_code`, `username`, `kyc_approved_at`, `kyc_submitted_at`, `kyc_rejected_at`, `mobile_preferences`

**Cleanup done:**
- Removed redundant migrations `2026_03_29_140000` (mobile_verified_at), `2026_03_29_150000` (username) — columns already exist via earlier migrations
- Removed outdated docs `docs/phase-10-auth-implementation-plan.md`, `docs/laravel-cloud-envars.md`
- Removed `.env copy` file

**PHP binary:** `/Users/Lihle/Library/Application Support/Herd/bin/php85`

---

## Remaining work (priority order)

### 1. Phase 17 — Stage 1 Backfill (highest complexity)

Read Phase 17 in `docs/maphapay-backend-replacement-plan.md` (line 1924). Key decisions:

- **Option A — Rolling snapshot with delta reconciliation (recommended)**
- `migration_delta_log` table in **legacy** DB to record all transactions during backfill
- Before enabling FinAegis writes for any user cohort, verify balance parity (Phase 17.3 parity check query)
- Implement `MigrateLegacyBalances` command

This is the most operationally dangerous part of the migration due to the "moving target" problem.

### 2. Phase 19 — Performance & Load Testing

Read Phase 19 in `docs/maphapay-backend-replacement-plan.md` (line 2008). Implement:

- k6 or Pest-based load tests for:
  - send-money throughput (100 concurrent users, no deadlocks)
  - MTN initiation burst (50 concurrent RTP with different idempotency keys)
  - MTN callback flood (20 concurrent callbacks, wallet credited exactly once)
  - balance read under write load (eventual consistency < 1s)
- Define P95 latency SLAs per endpoint (see table at line 2041)
- Run with at least 100 concurrent users in staging

### 3. Full suite MySQL smoke test

```bash
PHP85="/Users/Lihle/Library/Application Support/Herd/bin/php85"
XDEBUG_MODE=off "$PHP85" vendor/bin/pest --configuration=phpunit.ci.xml --parallel
```

SQLite may timeout for the full domain suite. MySQL recommended.

### 4. Manual QA — Login/OTP flows

Test login, OTP verification, profile completion, and forgot PIN flows end-to-end with a real device or Postman.

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
