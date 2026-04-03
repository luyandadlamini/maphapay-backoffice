# MaphaPay Backoffice Memory

Purpose: quick working context for future sessions without re-scanning the whole repository.

## Project Snapshot

- Repo: `maphapay-backoffice` (fork/adaptation of FinAegis core banking platform).
- Stack: Laravel 12, PHP 8.4, Filament v3 admin, Jetstream/Fortify web auth, Sanctum APIs.
- Deployment target currently used: Laravel Cloud.

## Branding Decisions

- Demo branding aligned to MaphaPay in `.env.demo`.
- `package.json` name/description updated to MaphaPay backoffice wording.
- Keep app behavior in demo mode while infra is being stabilized.

## Critical Environment Rules (Cloud)

- Use custom env vars to override injected vars when needed.
- Current safe mode for boot:
  - `APP_ENV=demo`
  - `HSM_PROVIDER=demo`
  - `KEY_MANAGEMENT_DEMO_MODE=true`
- Reason: `DemoHsmProvider` throws in production context by design.

## Redis/Cache Lessons

- If cache is misconfigured, Artisan commands can fail at boot (`Connection refused` from `PhpRedisConnector`).
- Fast unblock env values:
  - `CACHE_STORE=array`
  - `CACHE_DRIVER=array`
  - `QUEUE_CONNECTION=sync`
  - `SESSION_DRIVER=file`
- If using Laravel Cloud cache resource, avoid conflicting manual `REDIS_*` overrides unless intentional.

## Auth/Admin Status

- Filament admin panel path: `/admin`.
- Admin creation command that works non-interactively:
  - `php artisan user:create --admin --name='Admin User' --email='admin@maphapay.com' --password='StrongPassword123'`
- User portal/login exists; not built from scratch.
- `/dashboard` depends on authenticated/verified flow and team context.

## Frontend/Nav Learnings

- Dashboard welcome modal buttons were broken due to invalid JS string syntax in `dashboard.blade.php` (fixed).
- Banking/Web3/Profile dropdowns + logout depend on Alpine.
- Alpine is now bundled via Vite (`resources/js/app.js`) and no longer loaded from CDN in `layouts/app.blade.php`.

## Cloud Command Constraints

- Prefer single-line commands (interactive prompts are unreliable in cloud command runners).
- Useful checks:
  - `php artisan about`
  - `php artisan route:list --path=login`
  - `php artisan migrate --force`
  - `php artisan optimize:clear`

## Recent Fix Commits

- `aad6afb1` - demo branding + dashboard onboarding JS fix
- `bf94f3de` - initial Alpine restore in layout
- `2c6a848b` - Alpine bundled through Vite for reliable nav/logout behavior

## Money Movement Hardening Snapshot (2026-04-03)

- Verification policy for send-money, request-money create, and request-money accept is backend-owned through `MoneyMovementVerificationPolicyResolver`.
- `InternalP2pTransferService` is the unified execution path for internal P2P movement.
- `AuthorizedTransactionManager::finalizeAtomically()` is the atomic completion guard for authorization finalization.
- `MoneyMovementTransactionInspector` joins `authorized_transactions`, `asset_transfers`, `transaction_projections`, and `money_requests`.
- Current accept-flow coverage now includes:
  - same-key replay after HTTP idempotency cache loss
  - same-key replay when client hint changes but the resolved policy still stays OTP
  - rejection of a second accept initiation when a different idempotency key targets the same pending request
- Verification controller contract coverage now runs cleanly against the disposable MySQL harness:
  - `VerifyPinControllerTest`
  - `VerifyOtpControllerTest`
- OTP validation failures are now normalized to the same compat envelope as PIN:
  - `status = error`
  - `message = [<reason>]`
  - `data = null`
- The database-backed money-movement risk provider now also steps up low-risk initiations on:
  - anomalously large amounts versus the user's recent completed money-movement baseline
  - new-counterparty churn after high recent distinct recipient/counterparty spread
- Replay coverage now also includes hint-drift replays for:
  - send-money after HTTP idempotency cache loss when the resolved policy still stays `none`
  - request-money create after HTTP idempotency cache loss when the resolved policy still stays `otp`
- Current inspector coverage now includes:
  - failed verification timeline entries
  - projection-count mismatch warnings
  - transfer-without-projections warnings
  - request-money accept lifecycle lookup by `trx`
- The disposable `3307` harness is still only partially repaired for the broader money-movement integration suite:
  - compat verification and resolver tests now self-heal the minimum schema they need
  - broader send/request/inspector integration suites still depend on missing legacy test-schema pieces such as full `accounts`, `money_requests`, `asset_transfers`, and related projection tables/columns unless the database is rebuilt from a clean full migration bootstrap

## Local MySQL Verification Recipe

- The machine-wide MySQL daemon on `127.0.0.1:3306` is not currently assumed usable for blank-password `root`.
- Verified local suite runs used a disposable MySQL instance on `127.0.0.1:3307` with:
  - `DB_CONNECTION=mysql`
  - `DB_HOST=127.0.0.1`
  - `DB_PORT=3307`
  - `DB_DATABASE=maphapay_backoffice_test`
  - `DB_USERNAME=root`
  - `DB_PASSWORD=`
- `phpunit.xml` now uses `defaultTimeLimit="300"`.
- On the disposable MySQL instance, `max_execution_time` had to be set to `0` before first-run migrations.

## Remaining Money Movement Work

- Extend the risk provider beyond velocity and recent verification failures to the remaining anomaly/fraud signals.
- Wire the pre-execution risk hook into `AuthorizedTransactionManager::finalizeAtomically()` if hard-block behavior is still required before transfer execution.
- Decide whether to formalize the disposable MySQL harness or restore a usable shared `3306` test account.
- Expose the inspector in a real operator-facing Filament/admin surface if still required.
- Confirm production dashboards, alerts, and rollout posture with infra owners.

## Known Practical Workflow

1. Set env for demo-safe boot.
2. Ensure DB + cache strategy are coherent (array cache or properly attached Redis).
3. Run migrations.
4. Create admin/user with explicit options.
5. Validate `/login`, `/dashboard`, `/admin`.
6. If UI controls fail, redeploy and hard-refresh after frontend changes.

---

## MaphaPay Compat Layer — Phase Tracker

> Goal: expose a `routes/api-compat.php` surface that the React Native mobile app can call
> unchanged while the backend is FinAegis. Each phase adds or hardens one slice.

### Completed

| Phase | Feature | Key commit |
|---|---|---|
| AuthorizedTransaction domain | Core domain, MoneyConverter, migrations | `4e337760` |
| Verification (OTP / PIN) | `/api/verification-process/verify/{otp,pin}` | `32667819` → `ecb7fba2` |
| Send Money | `/api/send-money/store` + idempotency + KYC guard | `32667819` |
| Request Money | store, received-store, reject, history, received-history | `32667819` → `ecb7fba2` |
| Scheduled Send | store, index, cancel + `ExecuteScheduledSends` cron | `fce57a7f` |
| Scheduled Send Deferral | `verification_confirmed_at` gate; OTP/PIN sets flag only | `be72835e` |
| MTN MoMo (Phase 15) | request-to-pay, disbursement, status, callback | `fe87dd59` → `a539dac7` |
| Financial safety tests | DoubleSpend, Idempotency, WalletBalance feature tests | `be72835e` |
| Legacy social graph migration | `legacy:migrate-social-graph` Artisan command | `be72835e` |
| Post-review hardening | `declare(strict_types=1)` on AppServiceProvider + User, help text, test clarity | `be72835e` |

### Known Open Bug (separate ticket)

- `WalletOperationsService::transfer` accepts `string $amount` but `WalletTransferWorkflow::execute`
  declares `int $amount` — TypeError at runtime on any live transfer. `WalletBalanceConsistencyTest`
  stubs around it. Must be fixed before go-live.

### Likely Next Phases

- **Transaction history feed** — `/api/transactions/history` (paginated ledger for the mobile home feed)
- **Social graph endpoints** — friendships + friend-requests CRUD (tables migrated; no HTTP layer yet)
- **Dashboard aggregation** — balance summary, recent activity, pending items (one endpoint or several)
- **Notifications** — push device registration, in-app notification list
- **Bill split** — group expense splitting flow
- **Wallet linking** — external wallet / bank account attachment (MTN wallet already covered via MoMo)
