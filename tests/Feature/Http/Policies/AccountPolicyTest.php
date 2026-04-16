<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Policies;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Models\User;
use App\Policies\AccountPolicy;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\CreatesApplication;

class AccountPolicyTest extends BaseTestCase
{
    use CreatesApplication;

    private AccountPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new AccountPolicy();
    }

    #[Test]
    public function test_viewMinor_allows_child_viewing_own_account(): void
    {
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid'    => $child->uuid,
            'account_type' => 'minor',
        ]);

        $this->assertTrue($this->policy->viewMinor($child, $childAccount));
    }

    #[Test]
    public function test_viewMinor_allows_guardian_viewing_minor_account(): void
    {
        $guardian = User::factory()->create();
        $childAccount = Account::factory()->create(['account_type' => 'minor']);

        $this->createMembership($guardian, $childAccount, 'guardian');

        $this->assertTrue($this->policy->viewMinor($guardian, $childAccount));
    }

    #[Test]
    public function test_viewMinor_allows_co_guardian_viewing_minor_account(): void
    {
        $coGuardian = User::factory()->create();
        $childAccount = Account::factory()->create(['account_type' => 'minor']);

        $this->createMembership($coGuardian, $childAccount, 'co_guardian');

        $this->assertTrue($this->policy->viewMinor($coGuardian, $childAccount));
    }

    #[Test]
    public function test_viewMinor_denies_non_guardian(): void
    {
        $nonGuardian = User::factory()->create();
        $childAccount = Account::factory()->create(['account_type' => 'minor']);

        $this->assertFalse($this->policy->viewMinor($nonGuardian, $childAccount));
    }

    #[Test]
    public function test_viewMinor_denies_inactive_membership(): void
    {
        $guardian = User::factory()->create();
        $childAccount = Account::factory()->create(['account_type' => 'minor']);

        $this->createMembership($guardian, $childAccount, 'guardian', 'inactive');

        $this->assertFalse($this->policy->viewMinor($guardian, $childAccount));
    }

    #[Test]
    public function test_viewMinor_denies_random_user(): void
    {
        $randomUser = User::factory()->create();
        $childAccount = Account::factory()->create(['account_type' => 'minor']);

        $this->assertFalse($this->policy->viewMinor($randomUser, $childAccount));
    }

    #[Test]
    public function test_viewAnyMinor_allows_user_with_active_guardian_membership(): void
    {
        $guardian = User::factory()->create();
        $childAccount = Account::factory()->create(['account_type' => 'minor']);

        $this->createMembership($guardian, $childAccount, 'guardian');

        $this->assertTrue($this->policy->viewAnyMinor($guardian));
    }

    #[Test]
    public function test_viewAnyMinor_allows_user_with_active_co_guardian_membership(): void
    {
        $coGuardian = User::factory()->create();
        $childAccount = Account::factory()->create(['account_type' => 'minor']);

        $this->createMembership($coGuardian, $childAccount, 'co_guardian');

        $this->assertTrue($this->policy->viewAnyMinor($coGuardian));
    }

    #[Test]
    public function test_viewAnyMinor_denies_user_without_guardian_membership(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->policy->viewAnyMinor($user));
    }

    #[Test]
    public function test_viewAnyMinor_denies_user_with_inactive_membership(): void
    {
        $guardian = User::factory()->create();
        $childAccount = Account::factory()->create(['account_type' => 'minor']);

        $this->createMembership($guardian, $childAccount, 'guardian', 'inactive');

        $this->assertFalse($this->policy->viewAnyMinor($guardian));
    }

    #[Test]
    public function test_createMinor_allows_user_with_owner_role_on_personal_account(): void
    {
        $parent = User::factory()->create();
        $personalAccount = Account::factory()->create([
            'user_uuid'    => $parent->uuid,
            'account_type' => 'personal',
        ]);

        $this->createMembership($parent, $personalAccount, 'owner', 'active', 'personal');

        $this->assertTrue($this->policy->createMinor($parent));
    }

    #[Test]
    public function test_createMinor_denies_minor_account_user(): void
    {
        $child = User::factory()->create();
        $minorAccount = Account::factory()->create([
            'user_uuid'    => $child->uuid,
            'account_type' => 'minor',
        ]);

        $this->createMembership($child, $minorAccount, 'owner', 'active', 'minor');

        $this->assertFalse($this->policy->createMinor($child));
    }

    #[Test]
    public function test_createMinor_denies_user_without_personal_account(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->policy->createMinor($user));
    }

    #[Test]
    public function test_updateMinor_allows_primary_guardian(): void
    {
        $guardian = User::factory()->create();
        $childAccount = Account::factory()->create(['account_type' => 'minor']);

        $this->createMembership($guardian, $childAccount, 'guardian');

        $this->assertTrue($this->policy->updateMinor($guardian, $childAccount));
    }

    #[Test]
    public function test_updateMinor_denies_co_guardian(): void
    {
        $coGuardian = User::factory()->create();
        $childAccount = Account::factory()->create(['account_type' => 'minor']);

        $this->createMembership($coGuardian, $childAccount, 'co_guardian');

        $this->assertFalse($this->policy->updateMinor($coGuardian, $childAccount));
    }

    #[Test]
    public function test_updateMinor_denies_child(): void
    {
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid'    => $child->uuid,
            'account_type' => 'minor',
        ]);

        $this->assertFalse($this->policy->updateMinor($child, $childAccount));
    }

    #[Test]
    public function test_updateMinor_denies_non_guardian(): void
    {
        $nonGuardian = User::factory()->create();
        $childAccount = Account::factory()->create(['account_type' => 'minor']);

        $this->assertFalse($this->policy->updateMinor($nonGuardian, $childAccount));
    }

    #[Test]
    public function test_deleteMinor_allows_primary_guardian(): void
    {
        $guardian = User::factory()->create();
        $childAccount = Account::factory()->create(['account_type' => 'minor']);

        $this->createMembership($guardian, $childAccount, 'guardian');

        $this->assertTrue($this->policy->deleteMinor($guardian, $childAccount));
    }

    #[Test]
    public function test_deleteMinor_denies_co_guardian(): void
    {
        $coGuardian = User::factory()->create();
        $childAccount = Account::factory()->create(['account_type' => 'minor']);

        $this->createMembership($coGuardian, $childAccount, 'co_guardian');

        $this->assertFalse($this->policy->deleteMinor($coGuardian, $childAccount));
    }

    #[Test]
    public function test_deleteMinor_denies_child(): void
    {
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid'    => $child->uuid,
            'account_type' => 'minor',
        ]);

        $this->assertFalse($this->policy->deleteMinor($child, $childAccount));
    }

    #[Test]
    public function test_deleteMinor_denies_non_guardian(): void
    {
        $nonGuardian = User::factory()->create();
        $childAccount = Account::factory()->create(['account_type' => 'minor']);

        $this->assertFalse($this->policy->deleteMinor($nonGuardian, $childAccount));
    }

    #[Test]
    public function test_manageChildren_allows_user_with_owner_role_on_personal_account(): void
    {
        $parent = User::factory()->create();
        $personalAccount = Account::factory()->create([
            'user_uuid'    => $parent->uuid,
            'account_type' => 'personal',
        ]);

        $this->createMembership($parent, $personalAccount, 'owner', 'active', 'personal');

        $this->assertTrue($this->policy->manageChildren($parent));
    }

    #[Test]
    public function test_manageChildren_denies_minor_account_user(): void
    {
        $child = User::factory()->create();
        $minorAccount = Account::factory()->create([
            'user_uuid'    => $child->uuid,
            'account_type' => 'minor',
        ]);

        $this->createMembership($child, $minorAccount, 'owner', 'active', 'minor');

        $this->assertFalse($this->policy->manageChildren($child));
    }

    #[Test]
    public function test_manageChildren_denies_user_without_personal_account(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->policy->manageChildren($user));
    }

    private function createMembership(
        User $user,
        Account $account,
        string $role,
        string $status = 'active',
        ?string $accountType = null,
    ): AccountMembership {
        return AccountMembership::create([
            'account_uuid' => $account->uuid,
            'user_uuid'    => $user->uuid,
            'tenant_id'    => $this->createCentralTenant(),
            'role'         => $role,
            'status'       => $status,
            'account_type' => $accountType ?? (string) $account->account_type,
        ]);
    }

    private function createCentralTenant(): string
    {
        $tenantId = (string) Str::uuid();

        DB::connection('central')->table('tenants')->insert([
            'id'            => $tenantId,
            'name'          => 'Policy Test Tenant',
            'plan'          => 'default',
            'team_id'       => null,
            'trial_ends_at' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
            'data'          => json_encode([]),
        ]);

        return $tenantId;
    }
}
