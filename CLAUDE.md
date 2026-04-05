# CLAUDE.md

## Essential Commands

```bash
# Code quality (run before commit)
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php
XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=2G
./vendor/bin/pest --parallel --stop-on-failure

# Development
php artisan serve                    # Start server
npm run dev                          # Vite dev server
php artisan l5-swagger:generate      # API docs

# User & Admin management
php artisan user:create --admin      # Create user (--admin for admin role)
php artisan user:promote user@email  # Promote existing user to admin
php artisan user:demote user@email   # Remove admin role
php artisan user:admins              # List all admin users
```

## Filament AI Development (Blueprint Style)

When planning new features or modifying existing ones in the Admin panel:
1. **Define User Flows**: Describe the interaction from the admin's perspective.
2. **Map Primitives**: 
   - **Resources**: `app/Filament/Admin/Resources/`
   - **Relation Managers**: `app/Filament/Admin/Resources/<Resource>/RelationManagers`
   - **Actions**: Define where buttons/modals live (List, View, Edit).
3. **State Transitions**: Explicitly identify how domain state changes (e.g., `draft` -> `active`) are triggered in the UI.

## Current MaphaPay Focus

- Companion mobile repo: `/Users/Lihle/Development/Coding/maphapayrn`
- Current money-movement hardening docs: `/Users/Lihle/Development/Coding/maphapayrn/docs/send money PLAN.md`
- Verification policy is backend-owned for send-money and request-money. Client `verification_type` is a hint only.
- Compat verification failures are being normalized toward a shared envelope:
  - `status = error`
  - `message = [<reason>]`
  - `data = null`
- `RequestMoneyReceivedStoreController` replay coverage now includes:
  - same-key replay after HTTP idempotency cache loss when the policy still resolves to OTP
  - rejection when a different idempotency key hits an already-pending accept authorization
- `MoneyMovementTransactionInspector` coverage now includes request-money accept lifecycle lookup and missing-projection warnings.

## Local Money-Movement Test Harness

Use the disposable local MySQL instance when the machine-wide daemon on `3306` is not usable:

```bash
DB_CONNECTION=mysql \
DB_HOST=127.0.0.1 \
DB_PORT=3307 \
DB_DATABASE=maphapay_backoffice_test \
DB_USERNAME=root \
DB_PASSWORD='' \
php -d max_execution_time=300 ./vendor/bin/pest <tests...>
```

- `phpunit.xml` now uses `defaultTimeLimit="300"` because first-run migration bootstrap is heavy.
- On the disposable MySQL instance, `max_execution_time` must be `0` or large DDL can abort during bootstrap.

## Architecture

- **49 domains** in `app/Domain/` (DDD bounded contexts)
- **Event Sourcing**: Spatie v7.7+ with domain-specific tables
- **CQRS**: Command/Query Bus in `app/Infrastructure/`
- **GraphQL**: Lighthouse PHP, 39 domain schemas
- **Multi-Tenancy**: Team-based isolation (`UsesTenantConnection` trait)
- **Event Streaming**: Redis Streams publisher/consumer with DLQ + backpressure
- **Post-Quantum Crypto**: ML-KEM-768, ML-DSA-65, hybrid encryption
- **Stack**: PHP 8.4 / Laravel 12 / MySQL 8 / Redis / Pest / PHPStan Level 8

## Code Conventions

```php
<?php
declare(strict_types=1);
namespace App\Domain\Exchange\Services;
```

- Import order: `App\Domain` → `App\Http` → `App\Models` → `Illuminate` → Third-party
- Commits: `feat:` / `fix:` / `test:` / `refactor:` + `Co-Authored-By: Claude <noreply@anthropic.com>`
- Tests: Always pass `['read', 'write', 'delete']` abilities to `Sanctum::actingAs()`

## Compat Layer API Contract (api-compat.php)

**The backend is the single source of truth for field names and data shapes.**

- Compat controllers translate **route paths** (legacy URL → new handler) — not field names.
- Return domain model field names directly. **Never add legacy aliases** (`trx_type`, `remark`, `trx`, `details`, `remarks`).
- When the mobile client and backend disagree on a field name, **update the mobile**, not the backend.
- Canonical transaction fields: `id`, `reference`, `description`, `amount` (major-unit string), `type` (`deposit`/`withdrawal`/`transfer`), `subtype` (`send_money`/`request_money`/etc.), `asset_code`, `created_at`.
- Filter params must match domain vocabulary: `?type=deposit`, `?subtype=send_money`, `?search=` — not `?remark=`, `?trx_type=`.
- Response wrapper keys must be semantic: `subtypes` (not `remarks`), `transactions` (not `history`).
- Amount format: major-unit decimal string (e.g. `"10.50"`) via `TransactionProjection::formatted_amount` or `number_format($minor / $divisor, $precision)`. Never return raw minor-unit integers to mobile clients.

## CI/CD

| Issue | Fix |
|-------|-----|
| PHPStan type errors | Cast return types, add `@var` PHPDoc, null checks |
| Test scope 403s | Add abilities to `Sanctum::actingAs($user, ['read', 'write', 'delete'])` |
| Code style | `./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php` |
| PHPCS | `./vendor/bin/phpcbf --standard=PSR12 app/` |

```bash
gh pr checks <PR_NUMBER>              # Check PR status
gh run view <RUN_ID> --log-failed     # View failed logs
```

## Notes

- Always work in feature branches
- Ensure GitHub Actions pass before merging
- Never create docs files unless explicitly requested
- Prefer editing existing files over creating new ones
- Use Serena memories for deep architectural context when needed
