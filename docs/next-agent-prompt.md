# Next Agent Prompt

**Read first:** `docs/maphapay-backend-replacement-progress.md`

## Current state

Branch `main` is 15 commits ahead of `origin/main`. Latest commit: `feat: Phase 10 — mobile auth endpoints` (2026-03-29).

### What was just completed (Phase 10)

**Backend (`maphapay-backoffice`):**
- `MobileAuthController` — mobile+pin login (auto-registers if not found, sends OTP), OTP verify/resend, profile completion, forgot-pin / verify-reset-code / reset-pin
- `AuthorizationController` — GET `/api/auth/authorization` (pending steps: sms/email/kyc), POST resend
- `CountriesController` — GET `/api/countries`
- `DeviceTokenController` — POST `/api/device-tokens`
- `OtpService` — generates 6-digit OTPs, sends via SMS, verifies, 10-min TTL, 120s resend cooldown
- `user_otps` table + `UserOtp` model (types: `mobile_verification | pin_reset | login`)
- `countries` table + `CountrySeeder` + `Country` model
- `mobile` + `dial_code` columns on `users` (via migration `2026_03_29_110000`)
- `mobile_verified_at` + `username` on User (via separate migrations `2026_03_29_140000` and `150000`)
- PHP 8.5 PDO deprecation fix (`Pdo\Mysql::ATTR_SSL_CA`)
- PHPStan: User model `@property Carbon` for datetime fields; `Country::users()` and `UserOtp::user()` generic return types

**Mobile (`maphapayrn`):**
- `apiClient.ts`: refresh → `POST /api/auth/refresh`, reads `data.data.access_token`
- `authStore.ts`: login → `/api/auth/mobile/login` (dial_code: `+268`), logout → `POST /api/auth/logout`, refreshUser → `/api/auth/user`, countries → `/api/countries`
- `register.tsx`: all 3 steps now call FinAegis endpoints
- `useProfileSettings.ts`: device token → `/api/device-tokens` with FinAegis payload shape

### What's remaining

#### 1. CRITICAL: Remove duplicate files

A previous agent created duplicate files in `app/Http/Controllers/API/` (uppercase `API`). These are wrong and must be deleted:

```
app/Http/Controllers/API/Auth/AuthorizationController.php  ← DELETE
app/Http/Controllers/API/Auth/MobileAuthController.php     ← DELETE
app/Http/Controllers/API/DeviceTokenController.php       ← DELETE
app/Http/Controllers/API/General/CountriesController.php ← DELETE
```

The correct files are in `app/Http/Controllers/Api/` (lowercase `Api`). Also remove:
```
database/migrations/2026_03_29_140000_add_mobile_verified_at_to_users_table.php   ← DELETE (column already exists)
database/migrations/2026_03_29_150000_add_username_to_users_table.php              ← DELETE (column already exists)
docs/phase-10-auth-implementation-plan.md                                          ← DELETE (outdated plan doc)
docs/laravel-cloud-envars.md                                                       ← DELETE (unrelated)
.env copy                                                                           ← DELETE
```

#### 2. Phase 17 — Stage 1 Backfill (moving-target problem)

This is the hardest part. Read Phase 17 in `docs/maphapay-backend-replacement-plan.md` (line 1924). Key decisions to make:
- Rolling snapshot with delta reconciliation (Option A) is recommended
- You need a `migration_delta_log` table in the **legacy** DB to record all transactions that happen during backfill
- Before enabling FinAegis writes for any user, verify balance parity (Phase 17.3 parity check query)
- Implement the `MigrateLegacyBalances` command

#### 3. Phase 19 — Performance & Load Testing

Read Phase 19 in `docs/maphapay-backend-replacement-plan.md` (line 2008). Implement:
- k6 or Pest-based load tests for send-money throughput, MTN initiation burst, MTN callback flood, balance read under write load
- Define P95 latency SLAs per endpoint
- Run with at least 100 concurrent users in staging

#### 4. Full suite MySQL smoke test

Run the full compat test suite against MySQL (not just SQLite):
```bash
PHP85="/Users/Lihle/Library/Application Support/Herd/bin/php85"
XDEBUG_MODE=off "$PHP85" vendor/bin/pest --configuration=phpunit.ci.xml --parallel
```

SQLite timeout did NOT occur for compat sub-suite in prior runs, but the full suite with all domains may need MySQL.

#### 5. Legacy auth endpoint audit (low priority)

Confirm all legacy auth endpoints the mobile still calls have FinAegis equivalents:
- `POST /api/authentication` → `/api/auth/mobile/login` ✅ (done)
- `POST /api/verify-mobile` → `/api/auth/mobile/verify-otp` ✅ (done)
- `POST /api/user-data-submit` → `/api/auth/mobile/complete-profile` ✅ (done)
- `POST /api/auth/token/refresh/` → `/api/auth/refresh` ✅ (already existed, response shape verified)
- `GET /api/user-info` → `/api/auth/user` ✅ (done)
- `POST /api/auth/logout` (was GET) → `POST /api/auth/logout` ✅ (done)
- `POST /api/password/mobile` → `/api/auth/mobile/forgot-pin` ✅ (done)
- `POST /api/password/verify-code` → `/api/auth/mobile/verify-reset-code` ✅ (done)
- `POST /api/password/reset` → `/api/auth/mobile/reset-pin` ✅ (done)
- `GET /api/get-countries` → `/api/countries` ✅ (done)
- `GET /api/authorization` → `/api/auth/authorization` ✅ (done)
- `POST /api/add-device-token` → `/api/device-tokens` ✅ (done)

Also check if there are **forgot PIN UI flows** in the mobile app — they need to call the new endpoints. Search `maphapayrn` for `forgotPin`, `resetPin`, `password` to find if there are screens calling those legacy endpoints.

#### 6. Commit after cleanup

After removing the duplicate files:
```bash
git add -u && git commit -m "chore: remove duplicate Phase 10 files (wrong API/ dir, spurious migrations)

Duplicate controllers were created in app/Http/Controllers/API/ (uppercase)
instead of app/Http/Controllers/Api/. Remove the wrong copies.
Also remove redundant migrations whose columns (mobile_verified_at,
username) are already present in the User model.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
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

PHP binary: `/Users/Lihle/Library/Application Support/Herd/bin/php85` (php84 has dyld error on this machine).
