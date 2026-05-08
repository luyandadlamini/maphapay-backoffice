# 03 — Database Schema

Every migration to be created. Filenames are time-ordered. UUIDs are PKs. Money is stored in major-unit string form (`numeric(18,2)`) consistent with existing project convention; conversion to integer minor units happens in service layer via `MoneyConverter`.

All migrations except `card_plans` use the `tenant/` migration folder so they apply per-tenant. `card_plans` is a global table.

Run order: alterations to existing `cards` table FIRST (because new tables FK into it), then new tables, then seeders.

---

## 1. ALTER cards table — `2026_05_08_000001_alter_cards_add_monetisation_fields.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('cards', function (Blueprint $table) {
            $table->string('tier', 16)->default('standard')->after('network');
            $table->string('kind', 16)->default('virtual')->after('tier');
            $table->string('lifecycle', 24)->default('standard')->after('kind');
            $table->json('lifecycle_config')->nullable()->after('lifecycle');
            $table->boolean('is_default')->default(false)->after('lifecycle_config');

            // Per-category limits replace the single spend_limit_cents
            $table->decimal('per_transaction_limit', 18, 2)->nullable()->after('is_default');
            $table->decimal('daily_limit', 18, 2)->nullable()->after('per_transaction_limit');
            $table->decimal('monthly_limit', 18, 2)->nullable()->after('daily_limit');
            $table->decimal('atm_daily_limit', 18, 2)->nullable()->after('monthly_limit');
            $table->decimal('atm_monthly_limit', 18, 2)->nullable()->after('atm_daily_limit');
            $table->decimal('contactless_per_transaction_limit', 18, 2)->nullable()->after('atm_monthly_limit');

            // Booleans for per-card toggles (overlay onto plan defaults)
            $table->boolean('online_enabled')->default(true)->after('contactless_per_transaction_limit');
            $table->boolean('international_enabled')->default(true)->after('online_enabled');
            $table->boolean('atm_enabled')->default(false)->after('international_enabled');
            $table->boolean('contactless_enabled')->default(true)->after('atm_enabled');

            // Blocked MCC group keys (e.g. ['gambling','crypto']) — resolved via config('cards.mcc_groups')
            $table->json('blocked_mcc_groups')->nullable()->after('contactless_enabled');

            // Subscription this card belongs to (nullable for legacy / direct-issued cards)
            $table->uuid('card_subscription_id')->nullable()->after('blocked_mcc_groups');

            $table->index(['user_id', 'is_default']);
            $table->index('card_subscription_id');
        });
    }

    public function down(): void {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn([
                'tier', 'kind', 'lifecycle', 'lifecycle_config', 'is_default',
                'per_transaction_limit', 'daily_limit', 'monthly_limit',
                'atm_daily_limit', 'atm_monthly_limit', 'contactless_per_transaction_limit',
                'online_enabled', 'international_enabled', 'atm_enabled', 'contactless_enabled',
                'blocked_mcc_groups', 'card_subscription_id',
            ]);
        });
    }
};
```

The legacy `spend_limit_cents` and `spend_limit_interval` columns are kept for one phase (read-only) so the legacy code can keep compiling, then dropped in a follow-up migration after phase 4.

---

## 2. card_plans (global) — `2026_05_08_000002_create_card_plans_table.php`

Path: `database/migrations/` (NOT `tenant/`).

```php
Schema::create('card_plans', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('code', 32)->unique();
    $table->string('name', 64);
    $table->decimal('monthly_fee', 18, 2)->default(0);
    $table->unsignedInteger('max_virtual_cards')->default(0);
    $table->unsignedInteger('max_physical_cards')->default(0);
    $table->unsignedInteger('monthly_card_creation_limit')->default(0);
    $table->unsignedInteger('free_virtual_reissues_per_month')->default(0);
    $table->decimal('virtual_card_replacement_fee', 18, 2)->default(0);
    $table->decimal('monthly_card_spend_limit', 18, 2)->default(0);
    $table->decimal('daily_card_spend_limit', 18, 2)->default(0);
    $table->decimal('single_transaction_limit', 18, 2)->default(0);
    $table->boolean('atm_enabled')->default(false);
    $table->decimal('atm_daily_limit', 18, 2)->default(0);
    $table->decimal('atm_monthly_limit', 18, 2)->default(0);
    $table->decimal('atm_fixed_fee', 18, 2)->default(0);
    $table->unsignedInteger('atm_percentage_fee_bps')->default(0);
    $table->unsignedInteger('fx_markup_bps')->default(0);
    $table->decimal('physical_card_issuance_fee', 18, 2)->default(0);
    $table->decimal('physical_card_replacement_fee', 18, 2)->default(0);
    $table->string('eligibility', 16)->default('adult'); // adult | minor
    $table->boolean('active')->default(true);
    $table->timestamps();

    $table->index(['eligibility', 'active']);
});
```

---

## 3. card_subscriptions (tenant) — `tenant/2026_05_08_000003_create_card_subscriptions_table.php`

```php
Schema::create('card_subscriptions', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id')->nullable()->index();

    // Subscriber: the user whose cards are billed by this subscription
    $table->foreignUuid('subscriber_user_id')->constrained('users')->cascadeOnDelete();

    // Payer: the wallet that gets debited (= subscriber for adults; = guardian for minors)
    $table->foreignUuid('payer_user_id')->constrained('users')->cascadeOnDelete();

    // Plan
    $table->foreignUuid('card_plan_id')->constrained('card_plans');

    // Status
    $table->string('status', 32)->default('active');
    // Allowed: active, past_due, suspended, cancelled, pending_guardian_approval

    $table->timestamp('current_period_start')->nullable();
    $table->timestamp('current_period_end')->nullable();
    $table->timestamp('next_billing_date')->nullable();

    $table->unsignedInteger('failed_payment_count')->default(0);
    $table->timestamp('grace_period_ends_at')->nullable();
    $table->timestamp('suspended_at')->nullable();
    $table->timestamp('cancelled_at')->nullable();

    // Minor metadata
    $table->boolean('is_minor_subscription')->default(false);
    $table->uuid('guardian_user_id')->nullable();
    $table->uuid('minor_account_uuid')->nullable();
    $table->uuid('minor_card_request_id')->nullable(); // links back to approval request

    $table->timestamps();

    $table->unique(['subscriber_user_id', 'status'], 'card_subscriptions_unique_active')
          ->where('status', '!=', 'cancelled');
    $table->index(['subscriber_user_id', 'status']);
    $table->index(['payer_user_id', 'status']);
    $table->index(['next_billing_date', 'status']);
    $table->index('minor_account_uuid');
});
```

The unique partial index ensures a user can have only one non-cancelled subscription at a time. (PostgreSQL syntax via `whereRaw`. If on MySQL, replace with an application-level guard in `CardSubscriptionService::create()`.)

---

## 4. card_subscription_billing_attempts (tenant) — `tenant/2026_05_08_000004_create_card_subscription_billing_attempts_table.php`

```php
Schema::create('card_subscription_billing_attempts', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id')->nullable()->index();
    $table->foreignUuid('card_subscription_id')->constrained('card_subscriptions')->cascadeOnDelete();
    $table->string('result', 16); // success | failed
    $table->string('failure_reason', 64)->nullable();
    $table->decimal('amount', 18, 2);
    $table->string('currency', 3)->default('SZL');
    $table->uuid('idempotency_key')->nullable();
    $table->uuid('ledger_posting_id')->nullable();
    $table->timestamp('attempted_at');
    $table->timestamps();

    $table->index(['card_subscription_id', 'attempted_at']);
});
```

---

## 5. card_fees (tenant) — `tenant/2026_05_08_000005_create_card_fees_table.php`

```php
Schema::create('card_fees', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id')->nullable()->index();
    $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete(); // payer
    $table->uuid('related_entity_id')->nullable()->index();
    $table->string('related_entity_type', 64)->nullable();

    $table->string('fee_type', 32);
    // Allowed: subscription, fx_markup, atm, virtual_card_replacement,
    //          physical_card_issuance, physical_card_replacement,
    //          chargeback_abuse, manual_adjustment

    $table->decimal('amount', 18, 2);
    $table->string('currency', 3)->default('SZL');

    $table->string('status', 16)->default('pending');
    // Allowed: pending, charged, waived, refunded, failed

    $table->uuid('ledger_posting_id')->nullable();
    $table->timestamp('charged_at')->nullable();
    $table->timestamp('waived_at')->nullable();
    $table->timestamp('refunded_at')->nullable();
    $table->text('notes')->nullable();

    $table->timestamps();

    $table->index(['user_id', 'fee_type', 'status']);
    $table->index(['related_entity_type', 'related_entity_id']);
});
```

---

## 6. card_audit_logs (tenant) — `tenant/2026_05_08_000006_create_card_audit_logs_table.php`

```php
Schema::create('card_audit_logs', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id')->nullable()->index();

    $table->string('actor_type', 16);
    // Allowed: user, admin, system, processor

    $table->uuid('actor_id')->nullable();

    $table->string('action', 96);
    // Examples: subscription.created, subscription.cancelled, card.frozen_by_user,
    //           card.frozen_by_admin, card.reveal_requested, fee.waived, dispute.opened,
    //           minor_request.approved, processor.webhook_received

    $table->string('entity_type', 64);
    $table->uuid('entity_id')->nullable();

    $table->json('before_state')->nullable();
    $table->json('after_state')->nullable();
    $table->json('metadata')->nullable();        // arbitrary context

    $table->string('ip_address', 64)->nullable();
    $table->string('device_id', 64)->nullable();
    $table->string('user_agent', 256)->nullable();

    $table->timestamp('created_at')->useCurrent();

    $table->index(['entity_type', 'entity_id']);
    $table->index(['actor_type', 'actor_id']);
    $table->index(['action', 'created_at']);
});
```

**Append-only.** Application code MUST NOT issue UPDATE or DELETE on this table. Filament admin role permissions enforce the same at the policy layer (only `super_admin` can soft-export; nobody can edit or delete).

---

## 7. card_risk_events (tenant) — `tenant/2026_05_08_000007_create_card_risk_events_table.php`

```php
Schema::create('card_risk_events', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id')->nullable()->index();
    $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
    $table->foreignUuid('card_id')->nullable()->constrained('cards')->nullOnDelete();
    $table->string('event_type', 64);
    $table->string('severity', 16);
    // Allowed: low, medium, high, critical
    $table->text('description')->nullable();
    $table->json('metadata')->nullable();
    $table->string('status', 16)->default('open');
    // Allowed: open, in_review, resolved, dismissed
    $table->uuid('assigned_to_admin_id')->nullable();
    $table->timestamp('resolved_at')->nullable();
    $table->text('resolution_notes')->nullable();
    $table->timestamps();

    $table->index(['user_id', 'status']);
    $table->index(['severity', 'status']);
    $table->index(['card_id', 'created_at']);
});
```

---

## 8. card_disputes (tenant) — `tenant/2026_05_08_000008_create_card_disputes_table.php`

```php
Schema::create('card_disputes', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id')->nullable()->index();
    $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
    $table->foreignUuid('card_transaction_id')->constrained('card_transactions')->cascadeOnDelete();
    $table->string('reason', 32);
    // Allowed: unrecognised, duplicate, wrong_amount, service_not_received, other
    $table->string('status', 16)->default('submitted');
    // Allowed: submitted, in_review, evidence_required, won, lost, withdrawn
    $table->text('user_description')->nullable();
    $table->json('evidence')->nullable();        // file refs, transaction context
    $table->decimal('disputed_amount', 18, 2)->nullable();
    $table->string('currency', 3)->default('SZL');
    $table->string('processor_dispute_id', 128)->nullable()->unique();
    $table->timestamp('submitted_at')->nullable();
    $table->timestamp('processor_acknowledged_at')->nullable();
    $table->timestamp('resolved_at')->nullable();
    $table->text('resolution_notes')->nullable();
    $table->timestamps();

    $table->index(['user_id', 'status']);
    $table->index('card_transaction_id');
});
```

---

## 9. physical_card_orders (tenant) — `tenant/2026_05_08_000009_create_physical_card_orders_table.php`

```php
Schema::create('physical_card_orders', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id')->nullable()->index();
    $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
    $table->foreignUuid('card_subscription_id')->constrained('card_subscriptions')->cascadeOnDelete();
    $table->foreignUuid('card_id')->nullable()->constrained('cards')->nullOnDelete();
    $table->string('order_status', 32)->default('requested');
    // Allowed: requested, paid, approved, production, dispatched, ready_for_collection, delivered, activated, cancelled
    $table->string('delivery_method', 32);
    // Allowed: branch_collection, courier
    $table->json('delivery_address')->nullable();
    $table->uuid('collection_point_id')->nullable();
    $table->decimal('issuance_fee', 18, 2)->default(0);
    $table->decimal('delivery_fee', 18, 2)->default(0);
    $table->string('tracking_reference', 64)->nullable();
    $table->timestamp('requested_at')->useCurrent();
    $table->timestamp('paid_at')->nullable();
    $table->timestamp('approved_at')->nullable();
    $table->timestamp('production_at')->nullable();
    $table->timestamp('dispatched_at')->nullable();
    $table->timestamp('ready_for_collection_at')->nullable();
    $table->timestamp('delivered_at')->nullable();
    $table->timestamp('activated_at')->nullable();
    $table->timestamp('cancelled_at')->nullable();
    $table->text('cancellation_reason')->nullable();
    $table->timestamps();

    $table->index(['user_id', 'order_status']);
    $table->index(['card_subscription_id', 'order_status']);
});
```

---

## 10. idempotency_keys (tenant) — `tenant/2026_05_08_000010_create_idempotency_keys_table.php`

Skip this migration if the existing project already has an `idempotency_keys` or `operation_records` table that the `idempotency` middleware uses. Verify by reading `app/Http/Middleware/IdempotencyMiddleware.php` and the existing repo. If found, the new card endpoints reuse it.

If absent:

```php
Schema::create('idempotency_keys', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id')->nullable()->index();
    $table->string('key', 128)->unique();
    $table->string('request_hash', 64);
    $table->string('endpoint', 96);
    $table->json('response_body')->nullable();
    $table->unsignedSmallInteger('response_status')->nullable();
    $table->string('status', 16)->default('processing');
    // Allowed: processing, completed, failed
    $table->timestamp('expires_at');
    $table->timestamps();

    $table->index('expires_at');
});
```

---

## 11. card_plan_seeder

`database/seeders/CardPlanSeeder.php`. Idempotent; upserts by `code`.

```php
namespace Database\Seeders;

use App\Domain\CardSubscriptions\Models\CardPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CardPlanSeeder extends Seeder
{
    public function run(): void
    {
        // Source of truth: docs/cards/01-product-config.md §1
        $plans = [
            ['code' => 'FREE_WALLET',     'name' => 'Free Wallet',         'monthly_fee' => '0.00',   'max_virtual_cards' => 0, 'max_physical_cards' => 0, 'monthly_card_creation_limit' => 0, 'free_virtual_reissues_per_month' => 0, 'virtual_card_replacement_fee' => '0.00',  'monthly_card_spend_limit' => '0.00',     'daily_card_spend_limit' => '0.00',     'single_transaction_limit' => '0.00',     'atm_enabled' => false, 'atm_daily_limit' => '0.00',    'atm_monthly_limit' => '0.00',    'atm_fixed_fee' => '0.00',  'atm_percentage_fee_bps' => 0,   'fx_markup_bps' => 0,   'physical_card_issuance_fee' => '0.00',   'physical_card_replacement_fee' => '0.00',  'eligibility' => 'adult', 'active' => true],
            ['code' => 'VIRTUAL_LITE',    'name' => 'Virtual Card Lite',   'monthly_fee' => '25.00',  'max_virtual_cards' => 1, 'max_physical_cards' => 0, 'monthly_card_creation_limit' => 1, 'free_virtual_reissues_per_month' => 0, 'virtual_card_replacement_fee' => '15.00', 'monthly_card_spend_limit' => '3000.00',  'daily_card_spend_limit' => '1500.00',  'single_transaction_limit' => '1500.00',  'atm_enabled' => false, 'atm_daily_limit' => '0.00',    'atm_monthly_limit' => '0.00',    'atm_fixed_fee' => '0.00',  'atm_percentage_fee_bps' => 0,   'fx_markup_bps' => 350, 'physical_card_issuance_fee' => '0.00',   'physical_card_replacement_fee' => '0.00',  'eligibility' => 'adult', 'active' => true],
            ['code' => 'VIRTUAL_PLUS',    'name' => 'Virtual Card Plus',   'monthly_fee' => '50.00',  'max_virtual_cards' => 3, 'max_physical_cards' => 0, 'monthly_card_creation_limit' => 2, 'free_virtual_reissues_per_month' => 1, 'virtual_card_replacement_fee' => '20.00', 'monthly_card_spend_limit' => '15000.00', 'daily_card_spend_limit' => '7500.00',  'single_transaction_limit' => '5000.00',  'atm_enabled' => false, 'atm_daily_limit' => '0.00',    'atm_monthly_limit' => '0.00',    'atm_fixed_fee' => '0.00',  'atm_percentage_fee_bps' => 0,   'fx_markup_bps' => 300, 'physical_card_issuance_fee' => '0.00',   'physical_card_replacement_fee' => '0.00',  'eligibility' => 'adult', 'active' => true],
            ['code' => 'PHYSICAL_CARD',   'name' => 'Physical Card',       'monthly_fee' => '65.00',  'max_virtual_cards' => 3, 'max_physical_cards' => 1, 'monthly_card_creation_limit' => 2, 'free_virtual_reissues_per_month' => 1, 'virtual_card_replacement_fee' => '20.00', 'monthly_card_spend_limit' => '25000.00', 'daily_card_spend_limit' => '10000.00', 'single_transaction_limit' => '7500.00',  'atm_enabled' => true,  'atm_daily_limit' => '1500.00', 'atm_monthly_limit' => '5000.00', 'atm_fixed_fee' => '12.00', 'atm_percentage_fee_bps' => 150, 'fx_markup_bps' => 275, 'physical_card_issuance_fee' => '120.00', 'physical_card_replacement_fee' => '90.00', 'eligibility' => 'adult', 'active' => true],
            ['code' => 'PREMIUM_CARD',    'name' => 'Premium Card',        'monthly_fee' => '120.00', 'max_virtual_cards' => 5, 'max_physical_cards' => 1, 'monthly_card_creation_limit' => 4, 'free_virtual_reissues_per_month' => 2, 'virtual_card_replacement_fee' => '20.00', 'monthly_card_spend_limit' => '60000.00', 'daily_card_spend_limit' => '25000.00', 'single_transaction_limit' => '15000.00', 'atm_enabled' => true,  'atm_daily_limit' => '3000.00', 'atm_monthly_limit' => '10000.00','atm_fixed_fee' => '8.00',  'atm_percentage_fee_bps' => 100, 'fx_markup_bps' => 175, 'physical_card_issuance_fee' => '0.00',   'physical_card_replacement_fee' => '60.00', 'eligibility' => 'adult', 'active' => true],
            ['code' => 'MINOR_KHULA_CARD', 'name' => 'Khula',                'monthly_fee' => '20.00',  'max_virtual_cards' => 1, 'max_physical_cards' => 0, 'monthly_card_creation_limit' => 1, 'free_virtual_reissues_per_month' => 0, 'virtual_card_replacement_fee' => '15.00', 'monthly_card_spend_limit' => '2000.00',  'daily_card_spend_limit' => '500.00',   'single_transaction_limit' => '500.00',   'atm_enabled' => false, 'atm_daily_limit' => '0.00',    'atm_monthly_limit' => '0.00',    'atm_fixed_fee' => '0.00',  'atm_percentage_fee_bps' => 0,   'fx_markup_bps' => 350, 'physical_card_issuance_fee' => '0.00',   'physical_card_replacement_fee' => '0.00',  'eligibility' => 'minor', 'active' => true],
        ];

        foreach ($plans as $plan) {
            CardPlan::updateOrCreate(
                ['code' => $plan['code']],
                array_merge(['id' => (string) Str::uuid()], $plan),
            );
        }
    }
}
```

Add to `DatabaseSeeder` (or whatever the project's main seeder is). Run via:

```bash
php artisan db:seed --class=Database\\Seeders\\CardPlanSeeder --force
```

---

## 12. Deploy commands

In order, on the dev environment first, then staging, then production:

```bash
# Run the alter on cards
php artisan migrate --path=database/migrations/2026_05_08_000001_alter_cards_add_monetisation_fields.php --force

# Create card_plans (global)
php artisan migrate --path=database/migrations/2026_05_08_000002_create_card_plans_table.php --force

# Create tenant tables (per-tenant runner)
php artisan tenants:migrate --path=database/migrations/tenant/2026_05_08_000003_create_card_subscriptions_table.php --force
php artisan tenants:migrate --path=database/migrations/tenant/2026_05_08_000004_create_card_subscription_billing_attempts_table.php --force
php artisan tenants:migrate --path=database/migrations/tenant/2026_05_08_000005_create_card_fees_table.php --force
php artisan tenants:migrate --path=database/migrations/tenant/2026_05_08_000006_create_card_audit_logs_table.php --force
php artisan tenants:migrate --path=database/migrations/tenant/2026_05_08_000007_create_card_risk_events_table.php --force
php artisan tenants:migrate --path=database/migrations/tenant/2026_05_08_000008_create_card_disputes_table.php --force
php artisan tenants:migrate --path=database/migrations/tenant/2026_05_08_000009_create_physical_card_orders_table.php --force
php artisan tenants:migrate --path=database/migrations/tenant/2026_05_08_000010_create_idempotency_keys_table.php --force   # only if not present

# Seed plans
php artisan db:seed --class=Database\\Seeders\\CardPlanSeeder --force
```

Verify the project's tenant migration command name (e.g. `tenants:migrate` vs `tenant:migrate`) before running. The tenant runner automatically iterates each tenant connection.

---

## 13. Indexes and query plan checklist

After running migrations, validate query performance for these hot paths:

| Query | Index used |
|---|---|
| Find user's active subscription | `card_subscriptions(subscriber_user_id, status)` |
| Find subscriptions due for billing | `card_subscriptions(next_billing_date, status)` |
| Find user's pending fees | `card_fees(user_id, fee_type, status)` |
| Find audit trail for an entity | `card_audit_logs(entity_type, entity_id)` |
| Find open risk events by severity | `card_risk_events(severity, status)` |
| Find user's recent risk events | `card_risk_events(user_id, status)` |
| Find pending physical orders | `physical_card_orders(user_id, order_status)` |

Add `EXPLAIN ANALYZE` checks to the Pest billing-test setup; fail the suite if any of these scan more than ~1000 rows on seeded fixtures.

---

## 14. Down migrations

Every migration has a `down()` method that reverses cleanly. Tested by running:

```bash
php artisan migrate:rollback --step=10 --pretend
```

…and inspecting the generated SQL. NEVER ship a migration without a working `down()`.
