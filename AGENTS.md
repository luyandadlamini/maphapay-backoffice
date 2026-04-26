# AGENTS.md

Welcome AI coding agents! This file provides essential information to help you work effectively with the MaphaPay Backoffice platform.

## Project Overview

MaphaPay is a modern digital payment and core banking back-office, built on the FinAegis framework. It leverages Laravel 12, event sourcing, domain-driven design (DDD), and advanced financial features to manage peer-to-peer payments, request-money lifecycles, and stablecoin reserves.

**Companion Mobile Repo**: `/Users/Lihle/Development/Coding/maphapayrn`

## Quick Start

Filament runs on **Laravel**, not on the Vite port alone. Set `APP_URL` to match how you serve PHP (default below uses `http://127.0.0.1:8000`). For local DB credentials and the disposable MySQL test user, see **`CLAUDE.md`** (Local Money-Movement Test Harness) and `./scripts/bootstrap-local-test-db.sh` / `./scripts/reset-local-mysql-test-access.sh`.

```bash
# Setup
cp .env.example .env
# Edit .env: APP_ENV=local, APP_DEBUG=true, APP_URL=http://127.0.0.1:8000, and DB_* for your MySQL
composer install
npm install

# Database (after DB_* is valid)
php artisan migrate:fresh --seed

# Run Laravel + Vite together (recommended)
npm run dev:full
# Or two terminals: `php artisan serve --host=127.0.0.1 --port=8000` and `npm run dev`

# First-time admin user (pick one)
php artisan make:filament-user
# php artisan user:create --admin   # see CLAUDE.md
```

**Admin panel**: open **`http://127.0.0.1:8000/admin`** (same host/port as `APP_URL`). `http://localhost:5173/` is only the Vite dev server for assets.

## Architecture & Tech Stack

- **Filament v3**: Admin panel for all back-office operations.
- **Domain-Driven Design (DDD)**: Logic resides in `app/Domain/` (49 domain contexts).
- **Event Sourcing**: Spatie Event Sourcing (v7.7+) manages state transitions.
- **GraphQL**: Lighthouse PHP handles external API requests.
- **Post-Quantum Crypto**: PQC-hardened encryption (ML-KEM-768, ML-DSA-65).
- **Laravel 12**: Running on PHP 8.4 with strict types.

## AI Implementation Guidelines (Filament Blueprint style)

When planning new Filament features, ALWAYS follow the structured "Blueprint Plan" format:

1. **Describe the User Flows**: Primary end-to-end interactions.
2. **Map Primitives**: Link domain concepts to concrete Filament classes:
   - **Resources**: In `app/Filament/Admin/Resources/`
   - **Pages**: List, View, Create, Edit, or Custom.
   - **Relation Managers**: For many-to-many or complex one-to-many.
   - **Actions**: Modals, bulk actions, and state-triggering logic.
3. **State Transitions**: Identify how domain state (e.g., `pending` -> `completed`) is triggered by specific UI Actions.

## Dev Environment Tips

### Essential Commands

```bash
# Code quality checks (MANDATORY before commits)
./vendor/bin/pest --parallel && XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=2G && ./vendor/bin/php-cs-fixer fix
```

## Security Considerations

- **Verification Policy**: Backend-owned for `send-money` and `request-money`. Client `verification_type` is a hint only.
- **Idempotency**: Strictly enforced for all money-movement operations.

## Testing Strategy

- **Pest PHP**: All unit and feature tests.
- **Harness**: Use the `MoneyMovementTransactionInspector` for transaction lifecycle verification.
- **Sanctum**: Always pass `['read', 'write', 'delete']` abilities to `Sanctum::actingAs()`.

---

Remember: MaphaPay prioritizes transaction integrity and auditability. Always ensure state changes are backed by persisted events.