<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Shared\Concerns;

use App\Domain\Account\Models\AccountMembership;
use App\Domain\Shared\Concerns\WithTenantContext;
use App\Models\Tenant;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

class WithTenantContextTest extends TestCase
{
    #[Test]
    public function it_runs_callback_within_tenant_context_and_restores_after(): void
    {
        $tenant = Tenant::factory()->create();
        $userUuid = (string) Str::uuid();
        $accountUuid = (string) Str::uuid();

        AccountMembership::factory()->create([
            'user_uuid'    => $userUuid,
            'account_uuid' => $accountUuid,
            'tenant_id'    => $tenant->id,
            'status'       => 'active',
        ]);

        $host = new class () {
            use WithTenantContext;
        };

        $insideId = null;
        $host->withAccountTenancy($accountUuid, function () use (&$insideId): void {
            $currentTenant = app(Tenancy::class)->tenant;
            $insideId = $currentTenant instanceof \Stancl\Tenancy\Contracts\Tenant
                ? $currentTenant->getTenantKey()
                : null;
        });

        $this->assertSame($tenant->getTenantKey(), $insideId);
        $this->assertFalse(app(Tenancy::class)->initialized, 'tenancy must be torn down after callback');
    }

    #[Test]
    public function it_returns_the_callback_return_value(): void
    {
        $tenant = Tenant::factory()->create();
        $accountUuid = (string) Str::uuid();

        AccountMembership::factory()->create([
            'user_uuid'    => (string) Str::uuid(),
            'account_uuid' => $accountUuid,
            'tenant_id'    => $tenant->id,
            'status'       => 'active',
        ]);

        $host = new class () {
            use WithTenantContext;
        };

        $result = $host->withAccountTenancy($accountUuid, fn (): int => 42);

        $this->assertSame(42, $result);
    }

    #[Test]
    public function it_restores_tenancy_even_when_callback_throws(): void
    {
        $tenant = Tenant::factory()->create();
        $accountUuid = (string) Str::uuid();

        AccountMembership::factory()->create([
            'user_uuid'    => (string) Str::uuid(),
            'account_uuid' => $accountUuid,
            'tenant_id'    => $tenant->id,
            'status'       => 'active',
        ]);

        $host = new class () {
            use WithTenantContext;
        };

        try {
            $host->withAccountTenancy($accountUuid, function (): void {
                throw new LogicException('boom');
            });
        } catch (LogicException $e) {
            // expected
        }

        $this->assertFalse(app(Tenancy::class)->initialized);
    }
}
