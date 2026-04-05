# AGENTS.md

Welcome AI coding agents! This file provides essential information to help you work effectively with the MaphaPay Backoffice platform.

## Project Overview

MaphaPay is a modern digital payment and core banking back-office, built on the FinAegis framework. It leverages Laravel 12, event sourcing, domain-driven design (DDD), and advanced financial features to manage peer-to-peer payments, request-money lifecycles, and stablecoin reserves.

**Companion Mobile Repo**: `/Users/Lihle/Development/Coding/maphapayrn`

## Quick Start

```bash
# Setup
cp .env.example .env
composer install
npm install && npm run dev

# Database setup (using local disposable instance if 3306 is taken)
php artisan migrate:fresh --seed
```

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