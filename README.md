# MaphaPay Backoffice

Laravel backend and operations backoffice for MaphaPay. This repository is a FinAegis-based fork, but in day-to-day use it serves two concrete roles:

- the API consumed by the mobile app in `/Users/Lihle/Development/Coding/maphapayrn`
- the internal admin/backoffice surface built on Filament

This README is focused on the code that is active in this repo now, not the broader upstream FinAegis marketing surface.

## Stack

- PHP `8.4+`
- Laravel `12`
- Filament admin panel
- Sanctum-based authenticated API flows
- Spatie event sourcing
- Horizon-compatible queue setup
- Vite for frontend assets
- Pest for tests

Selected packages currently installed:

- `filament/filament`
- `laravel/sanctum`
- `laravel/horizon`
- `spatie/laravel-event-sourcing`
- `nuwave/lighthouse`
- `pusher/pusher-php-server`
- `laravel/passport`
- `stancl/tenancy`

## What this repo currently owns

For MaphaPay specifically, the current backend includes:

- Mobile and standard auth routes
- Mobile profile completion
- Device token registration
- Transaction PIN toggle
- Mobile compatibility API routes loaded from `routes/api-compat.php`
- Dashboard and transaction history endpoints used by the mobile app
- Send money and request money flows with idempotency and verification enforcement
- Payment-link lookup endpoint
- Savings pockets endpoints
- Push-notification list/read/sync endpoints
- Wallet-linking endpoint
- Social money threads, messages, friend requests, groups, bill-split, and thread payments
- MTN MoMo initiation and status endpoints
- Mobile-device management and biometric-auth APIs
- Filament admin pages for operations and inspection

## Routing model

Routing is configured in `bootstrap/app.php`.

Current behavior:

- Main web host:
  - web routes from `routes/web.php`
  - API routes under `/api/...`
  - compatibility routes under `/api/...`
- `api.*` subdomain:
  - API routes loaded without the `/api` prefix
- `x402.*` and `mpp.*` protocol subdomains:
  - API routes loaded without the `/api` prefix
  - protocol middleware automatically applied

Important files:

- `routes/api.php` -> core API routes
- `routes/api-compat.php` -> mobile compatibility surface
- `routes/web.php` -> browser/admin routes
- `routes/channels.php` -> broadcast channels
- `routes/console.php` -> scheduled tasks

## Mobile-facing API areas

The mobile app depends primarily on the compatibility and mobile endpoints in this repo.

Current compatibility areas in `routes/api-compat.php`:

- `verification-process/*`
- `send-money/store`
- `request-money/*`
- `scheduled-send/*`
- `mtn/*`
- `transactions`, `transactions/sync`
- `dashboard`
- `social-money/*`
- `pockets*`
- `push-notifications*`
- `wallet-linking`
- rewards, budget, notification settings, group pockets, virtual cards

Current mobile endpoints in `routes/api.php` and `app/Http/Controllers/Api/MobileController.php` include:

- `/api/mobile/config`
- `/api/mobile/devices`
- `/api/mobile/auth/biometric/*`
- `/api/mobile/notifications`
- `/api/mobile/notifications/preferences`

## Money-movement contract notes

The codebase currently enforces stricter mobile compatibility behavior for money movement:

- send money uses idempotency middleware
- request money create/accept flows are migration-flagged and idempotent
- verification endpoints are separated into PIN, OTP, and biometric flows
- compatibility responses are expected to fail closed unless the backend returns terminal success

Those rules live in and around:

- `routes/api-compat.php`
- compatibility controllers under `app/Http/Controllers/Api/Compatibility`
- money-movement tests under `tests/Feature/Http/Controllers/Api/Compatibility`

## Local setup

Prerequisites:

- PHP 8.4+
- Composer
- Node.js 18+
- MySQL, MariaDB, or PostgreSQL
- Redis if you want the full queue/cache/broadcast stack

Install:

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Configure your database and queue settings in `.env`, then run:

```bash
php artisan migrate
php artisan serve
```

For frontend assets during local work:

```bash
npm run dev
```

For production-style built assets:

```bash
npm run build
```

## Queues and background work

This repo includes scheduled jobs and queue-backed flows for notifications, events, and financial processing.

At minimum, for local development that touches async behavior, run a worker:

```bash
php artisan queue:work
```

If you are using the fuller queue setup in this project, Horizon is also available:

```bash
php artisan horizon
```

Scheduled tasks are defined in `routes/console.php`, including mobile-notification processing and stale-device cleanup.

## Environment notes

The shipped `.env.example` is still FinAegis-branded in places, but the code in this repo is what matters.

Important defaults and expectations visible in the current config:

- `APP_TIMEZONE=Africa/Mbabane`
- default DB connection in the sample file is `mariadb`
- default queue connection in the sample file is `database`
- registration is disabled by default in the sample env
- mobile feature flags and version settings are exposed through `/api/mobile/config`

## Admin and operations

This repo includes a substantial Filament admin surface under `app/Filament/Admin`.

Examples of current admin/ops pages:

- Dashboard
- Money Movement Inspector
- Projector Health Dashboard
- Event Store Dashboard
- Exceptions Dashboard
- Broadcast Notification Page
- Modules
- Settings
- Fund management pages

There is also an authenticated API admin dashboard route at:

- `/api/admin/dashboard`

That route requires:

- `auth:sanctum`
- `require.2fa.admin`

## Testing and quality

Useful commands already defined in `composer.json`:

```bash
composer test
composer phpstan
composer phpcs
composer quality
```

The test suite includes coverage for:

- mobile controller response shapes
- compatibility route behavior
- send money and request money policy enforcement
- money-movement transaction inspection
- Filament admin pages

## Repo relationship

- Backoffice/API: `/Users/Lihle/Development/Coding/maphapay-backoffice`
- Mobile client: `/Users/Lihle/Development/Coding/maphapayrn`

If you update request/response shapes here, update the mobile app README and callers alongside the controller or route changes.

## License

Apache-2.0, inherited from the FinAegis base unless your team applies a different internal policy for deployment and operations.
