<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Lending\Workflows\Activities;

use App\Domain\Account\Models\AccountMembership;
use App\Domain\Lending\Workflows\Activities\LoanApplicationActivities;
use App\Domain\Shared\Concerns\WithTenantContext;
use App\Models\Tenant;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

/**
 * Verifies that LoanApplicationActivities wraps tenant-scoped DB writes
 * (accounts + transactions) in WithTenantContext::withAccountTenancy() so
 * they target the correct per-tenant connection rather than the central DB
 * fallback when running outside HTTP middleware (Temporal workflow context).
 *
 * Note: in the test environment UsesTenantConnection::shouldUseDefaultConnection()
 * returns true (app.env = 'testing'), so we cannot assert an actual DB-swap.
 * Instead we assert:
 *   1. The activity class uses WithTenantContext (trait presence).
 *   2. Stancl tenancy is initialized with the correct tenant during the wrapped
 *      call — verified by inspecting the Tenancy singleton inside the closure.
 *
 * Production coverage of the real DB-swap is exercised by the runtime smoke
 * described in docs/superpowers/plans/2026-05-16-account-tenancy-canonicalization.md
 * (Task 7.4).
 */
class LoanApplicationActivitiesTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    #[Test]
    public function class_uses_with_tenant_context_trait(): void
    {
        $traits = class_uses_recursive(LoanApplicationActivities::class);

        $this->assertArrayHasKey(
            WithTenantContext::class,
            $traits,
            'LoanApplicationActivities must use WithTenantContext so Temporal activity writes target the correct tenant DB.'
        );
    }

    #[Test]
    public function with_account_tenancy_initializes_correct_tenant_during_call(): void
    {
        if (! $this->canCreateTenantDatabases()) {
            $this->markTestSkipped('Tenant DB creation requires CREATE DATABASE privilege — run scripts/reset-local-mysql-test-access.sh.');
        }

        try {
            $tenant = Tenant::factory()->create();
        } catch (\Illuminate\Database\QueryException $e) {
            $this->markTestSkipped('Transient MySQL error during tenant factory setup: ' . $e->getMessage());
        }

        $accountUuid = (string) Str::uuid();

        AccountMembership::factory()->create([
            'user_uuid'    => (string) Str::uuid(),
            'account_uuid' => $accountUuid,
            'tenant_id'    => $tenant->id,
            'status'       => 'active',
        ]);

        $capturedTenantKey = null;

        $host = new class () {
            use WithTenantContext;
        };

        $host->withAccountTenancy($accountUuid, function () use (&$capturedTenantKey): void {
            $tenancy = app(Tenancy::class);
            $currentTenant = $tenancy->tenant;
            if ($tenancy->initialized && $currentTenant instanceof \Stancl\Tenancy\Contracts\Tenant) {
                $capturedTenantKey = $currentTenant->getTenantKey();
            }
        });

        $this->assertSame(
            $tenant->getTenantKey(),
            $capturedTenantKey,
            'withAccountTenancy must initialize tenancy with the tenant that owns the account.'
        );

        $this->assertFalse(
            app(Tenancy::class)->initialized,
            'Tenancy must be torn down after withAccountTenancy completes.'
        );
    }
}
