<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Services\AccountMembershipService;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

class AccountMembershipServiceTest extends TestCase
{
    protected User $user;

    protected Tenant $tenant;

    protected Account $account;

    protected AccountMembershipService $service;

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection('central')->getPdo();
        } catch (\Throwable $exception) {
            $this->markTestSkipped('Central database connection not available: ' . $exception->getMessage());
        }

        $this->user = User::factory()->create();
        $team = Team::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Owner Team',
        ]);

        $this->tenant = Tenant::createFromTeam($team);
        tenancy()->initialize($this->tenant);

        $this->account = Account::factory()->forUser($this->user)->create([
            'type' => 'personal',
            'display_name' => 'Personal Wallet',
        ]);

        app(Tenancy::class)->end();

        $this->service = app(AccountMembershipService::class);
    }

    protected function tearDown(): void
    {
        $tenancy = app(Tenancy::class);

        if ($tenancy->initialized) {
            $tenancy->end();
        }

        parent::tearDown();
    }

    public function test_create_owner_membership_creates_active_owner_membership(): void
    {
        $membership = $this->service->createOwnerMembership($this->user, $this->tenant->id, $this->account);

        $this->assertSame($this->user->uuid, $membership->user_uuid);
        $this->assertSame($this->tenant->id, $membership->tenant_id);
        $this->assertSame($this->account->uuid, $membership->account_uuid);
        $this->assertSame('personal', $membership->account_type);
        $this->assertSame('owner', $membership->role);
        $this->assertSame('active', $membership->status);
        $this->assertNotNull($membership->joined_at);

        $this->assertDatabaseHas('account_memberships', [
            'id' => $membership->id,
            'user_uuid' => $this->user->uuid,
            'tenant_id' => $this->tenant->id,
            'account_uuid' => $this->account->uuid,
            'role' => 'owner',
            'status' => 'active',
        ], 'central');
    }

    public function test_user_has_access_to_account_only_for_active_memberships(): void
    {
        AccountMembership::create([
            'user_uuid' => $this->user->uuid,
            'tenant_id' => $this->tenant->id,
            'account_uuid' => $this->account->uuid,
            'account_type' => 'personal',
            'role' => 'viewer',
            'status' => 'suspended',
        ]);

        $this->assertFalse($this->service->userHasAccessToAccount($this->user, $this->account->uuid));

        AccountMembership::query()->delete();

        AccountMembership::create([
            'user_uuid' => $this->user->uuid,
            'tenant_id' => $this->tenant->id,
            'account_uuid' => $this->account->uuid,
            'account_type' => 'personal',
            'role' => 'viewer',
            'status' => 'active',
        ]);

        $this->assertTrue($this->service->userHasAccessToAccount($this->user, $this->account->uuid));
    }

    public function test_get_active_memberships_returns_only_active_memberships_for_user(): void
    {
        $otherUser = User::factory()->create();

        $activeMembership = AccountMembership::create([
            'user_uuid' => $this->user->uuid,
            'tenant_id' => $this->tenant->id,
            'account_uuid' => $this->account->uuid,
            'account_type' => 'personal',
            'role' => 'owner',
            'status' => 'active',
        ]);

        AccountMembership::create([
            'user_uuid' => $this->user->uuid,
            'tenant_id' => $this->tenant->id,
            'account_uuid' => (string) fake()->uuid(),
            'account_type' => 'merchant',
            'role' => 'admin',
            'status' => 'removed',
        ]);

        AccountMembership::create([
            'user_uuid' => $otherUser->uuid,
            'tenant_id' => $this->tenant->id,
            'account_uuid' => (string) fake()->uuid(),
            'account_type' => 'personal',
            'role' => 'owner',
            'status' => 'active',
        ]);

        $memberships = $this->service->getActiveMemberships($this->user);

        $this->assertCount(1, $memberships);
        $this->assertSame($activeMembership->id, $memberships->first()?->id);
    }

    public function test_get_membership_for_account_returns_membership_for_requested_account(): void
    {
        $firstMembership = AccountMembership::create([
            'user_uuid' => $this->user->uuid,
            'tenant_id' => $this->tenant->id,
            'account_uuid' => (string) fake()->uuid(),
            'account_type' => 'merchant',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $expectedMembership = AccountMembership::create([
            'user_uuid' => $this->user->uuid,
            'tenant_id' => $this->tenant->id,
            'account_uuid' => $this->account->uuid,
            'account_type' => 'personal',
            'role' => 'owner',
            'status' => 'active',
        ]);

        $membership = $this->service->getMembershipForAccount($this->user, $this->account->uuid);

        $this->assertNotNull($membership);
        $this->assertNotSame($firstMembership->id, $membership->id);
        $this->assertSame($expectedMembership->id, $membership->id);
    }
}
