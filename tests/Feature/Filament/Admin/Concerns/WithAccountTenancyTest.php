<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Concerns;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Filament\Admin\Concerns\WithAccountTenancy;
use App\Models\Tenant;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

class WithAccountTenancyTest extends TestCase
{
    #[Test]
    public function it_initializes_stancl_tenancy_from_an_account_membership(): void
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

        $account = (new Account())->forceFill(['uuid' => $accountUuid, 'user_uuid' => $userUuid]);

        $host = new class () {
            use WithAccountTenancy;
        };

        $host->initializeTenancyForRecord($account);

        $initializedTenant = app(Tenancy::class)->tenant;

        $this->assertTrue(app(Tenancy::class)->initialized);
        $this->assertInstanceOf(Tenant::class, $initializedTenant);
        $this->assertSame($tenant->getTenantKey(), $initializedTenant->getTenantKey());
    }

    #[Test]
    public function it_throws_when_no_membership_exists_for_the_account(): void
    {
        $account = (new Account())->forceFill([
            'uuid'      => (string) Str::uuid(),
            'user_uuid' => (string) Str::uuid(),
        ]);

        $host = new class () {
            use WithAccountTenancy;
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no active membership/i');

        $host->initializeTenancyForRecord($account);
    }

    #[Test]
    public function it_ends_tenancy_when_release_is_called(): void
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

        $account = (new Account())->forceFill(['uuid' => $accountUuid, 'user_uuid' => $userUuid]);

        $host = new class () {
            use WithAccountTenancy;
        };

        $host->initializeTenancyForRecord($account);
        $host->releaseAccountTenancy();

        $this->assertFalse(app(Tenancy::class)->initialized);
    }
}
