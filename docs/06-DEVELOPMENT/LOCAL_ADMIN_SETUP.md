# Local Filament Admin Setup

This repo is set up to run the Filament admin panel locally. The admin panel is mounted at `/admin`, and the codebase includes the standard local Laravel workflows:

- `php artisan serve`
- `npm run dev`
- `php artisan queue:work`
- `php artisan make:filament-user`

## What Already Works In This Repo

- Filament panel provider exists in `app/Providers/Filament/AdminPanelProvider.php`
- Admin routes are registered under `/admin`
- Local-friendly sample env exists in `.env.example`
- Required tables for common local drivers are covered by migrations:
  - `sessions`
  - `jobs`
  - `failed_jobs`
  - `cache`
  - `cache_locks`
- Users can access Filament when they have an admin-capable role via `App\Models\User::canAccessPanel()`

## Important Local Constraint

Laravel defaults to `production` behavior when `APP_ENV` is missing. If your local `.env` does not set `APP_ENV=local`, you can end up running with production-style defaults.

For lightweight local admin testing, set at minimum:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
```

## Recommended Local Modes

### Option 1: Fastest Local Admin Setup

Use this when you mainly want to inspect Filament resources and back-office flows locally.

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=maphapay_backoffice
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=file
MAIL_MAILER=log
```

Run:

```bash
composer install
npm install
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan user:create --admin
php artisan serve
npm run dev
```

Open:

- `http://127.0.0.1:8000/admin`

If you trigger queued behavior, also run:

```bash
php artisan queue:work
```

### Option 2: Closer To Production

Use this when you want to exercise Redis-backed queues and Horizon locally.

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=maphapay_backoffice
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=database
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
MAIL_MAILER=log
```

Run:

```bash
php artisan migrate
php artisan user:create --admin
php artisan serve
npm run dev
php artisan horizon
```

## Admin User Access

Filament access is role-gated. A user must have an admin-capable role.

Recommended command:

```bash
php artisan user:create --admin
```

Alternative:

```bash
php artisan make:filament-user
```

## Tenancy Notes

This repo includes `stancl/tenancy`, but the central app supports `localhost` and `127.0.0.1` in `config/tenancy.php`. Basic Filament admin testing on the central app does not require tenant-domain setup.

Only set up tenant databases if the specific feature you are testing depends on tenant-scoped data.

## Current Failure Pattern To Avoid

If you see connection errors like:

- `SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost'`

then your local `.env` is pointing at a database that is not actually available from your machine yet. Fix the database settings first, then rerun migrations.

## Practical Recommendation

For most local Filament work on this repo:

1. Set `APP_ENV=local`
2. Use a local MariaDB/MySQL database
3. Use `MAIL_MAILER=log`
4. Start with `QUEUE_CONNECTION=database`
5. Run `php artisan user:create --admin`
6. Use `php artisan serve` and `npm run dev`

Move to Redis/Horizon only if the flow you are testing actually depends on async processing.
