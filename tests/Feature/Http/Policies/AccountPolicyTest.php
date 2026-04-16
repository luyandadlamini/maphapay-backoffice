<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Policies;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Models\User;
use App\Policies\AccountPolicy;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountPolicyTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    private AccountPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new AccountPolicy();
    }

    // ============= ViewMinor Tests =============

    #[Test]
    public function allows_child_to_view_own_minor_account(): void
    {
        $parentUser = User::factory()->create();
        $childUser = User::factory()->create();

        $childAccount = Account::factory()
            ->for($childUser, 'user')
            ->create(['account_type' => 'minor']);

        $this->assertTrue($this->policy->viewMinor($childUser, $childAccount));
    }

    #[Test]
    public function allows_primary_guardian_to_view_child_account(): void
    {
        $childUser = User::factory()->create();
        $guardianUser = User::factory()->create();

        $childAccount = Account::factory()
            ->for($childUser, 'user')
            ->create(['account_type' => 'minor']);

        $guardianAccount = Account::factory()
            ->for($guardianUser, 'user')
            ->create(['account_type' => 'personal']);

        AccountMembership::factory()->create([
            'minor_account_id' => $childAccount->uuid,
            'guardian_account_id' => $guardianAccount->uuid,
            'role' => 'guardian',
        ]);

        $this->assertTrue($this->policy->viewMinor($guardianUser, $childAccount));
    }

    #[Test]
    public function allows_co_guardian_to_view_child_account(): void
    {
        $childUser = User::factory()->create();
        $coGuardianUser = User::factory()->create();

        $childAccount = Account::factory()
            ->for($childUser, 'user')
            ->create(['account_type' => 'minor']);

        $coGuardianAccount = Account::factory()
            ->for($coGuardianUser, 'user')
            ->create(['account_type' => 'personal']);

        AccountMembership::factory()->create([
            'minor_account_id' => $childAccount->uuid,
            'guardian_account_id' => $coGuardianAccount->uuid,
            'role' => 'co_guardian',
        ]);

        $this->assertTrue($this->policy->viewMinor($coGuardianUser, $childAccount));
    }

    #[Test]
    public function denies_non_guardian_from_viewing_child_account(): void
    {
        $childUser = User::factory()->create();
        $randomUser = User::factory()->create();

        $childAccount = Account::factory()
            ->for($childUser, 'user')
            ->create(['account_type' => 'minor']);

        $this->assertFalse($this->policy->viewMinor($randomUser, $childAccount));
    }

    #[Test]
    public function denies_random_user_from_viewing_any_account(): void
    {
        $randomUser1 = User::factory()->create();
        $randomUser2 = User::factory()->create();

        $childAccount = Account::factory()
            ->for($randomUser2, 'user')
            ->create(['account_type' => 'minor']);

        $this->assertFalse($this->policy->viewMinor($randomUser1, $childAccount));
    }

    // ============= ViewAnyMinor Tests =============

    #[Test]
    public function allows_guardian_to_view_any_if_they_have_any_children(): void
    {
        $guardianUser = User::factory()->create();
        $childUser = User::factory()->create();

        $guardianAccount = Account::factory()
            ->for($guardianUser, 'user')
            ->create(['account_type' => 'personal']);

        $childAccount = Account::factory()
            ->for($childUser, 'user')
            ->create(['account_type' => 'minor']);

        AccountMembership::factory()->create([
            'minor_account_id' => $childAccount->uuid,
            'guardian_account_id' => $guardianAccount->uuid,
            'role' => 'guardian',
        ]);

        $this->assertTrue($this->policy->viewAnyMinor($guardianUser));
    }

    #[Test]
    public function denies_non_guardian_from_view_any(): void
    {
        $randomUser = User::factory()->create();

        $this->assertFalse($this->policy->viewAnyMinor($randomUser));
    }

    #[Test]
    public function allows_co_guardian_to_view_any_if_they_have_any_children(): void
    {
        $coGuardianUser = User::factory()->create();
        $childUser = User::factory()->create();

        $coGuardianAccount = Account::factory()
            ->for($coGuardianUser, 'user')
            ->create(['account_type' => 'personal']);

        $childAccount = Account::factory()
            ->for($childUser, 'user')
            ->create(['account_type' => 'minor']);

        AccountMembership::factory()->create([
            'minor_account_id' => $childAccount->uuid,
            'guardian_account_id' => $coGuardianAccount->uuid,
            'role' => 'co_guardian',
        ]);

        $this->assertTrue($this->policy->viewAnyMinor($coGuardianUser));
    }

    // ============= CreateMinor Tests =============

    #[Test]
    public function allows_user_with_personal_account_to_create_minor_account(): void
    {
        $parentUser = User::factory()->create();

        Account::factory()
            ->for($parentUser, 'user')
            ->create(['account_type' => 'personal']);

        $this->assertTrue($this->policy->createMinor($parentUser));
    }

    #[Test]
    public function denies_child_account_from_creating_minor_account(): void
    {
        $childUser = User::factory()->create();

        Account::factory()
            ->for($childUser, 'user')
            ->create(['account_type' => 'minor']);

        $this->assertFalse($this->policy->createMinor($childUser));
    }

    #[Test]
    public function denies_user_without_personal_account_from_creating(): void
    {
        $user = User::factory()->create();

        // Don't create any account for this user

        $this->assertFalse($this->policy->createMinor($user));
    }

    // ============= UpdateMinor Tests =============

    #[Test]
    public function allows_primary_guardian_to_update_child_account(): void
    {
        $childUser = User::factory()->create();
        $guardianUser = User::factory()->create();

        $childAccount = Account::factory()
            ->for($childUser, 'user')
            ->create(['account_type' => 'minor']);

        $guardianAccount = Account::factory()
            ->for($guardianUser, 'user')
            ->create(['account_type' => 'personal']);

        AccountMembership::factory()->create([
            'minor_account_id' => $childAccount->uuid,
            'guardian_account_id' => $guardianAccount->uuid,
            'role' => 'guardian',
        ]);

        $this->assertTrue($this->policy->updateMinor($guardianUser, $childAccount));
    }

    #[Test]
    public function denies_co_guardian_from_updating_child_account(): void
    {
        $childUser = User::factory()->create();
        $coGuardianUser = User::factory()->create();

        $childAccount = Account::factory()
            ->for($childUser, 'user')
            ->create(['account_type' => 'minor']);

        $coGuardianAccount = Account::factory()
            ->for($coGuardianUser, 'user')
            ->create(['account_type' => 'personal']);

        AccountMembership::factory()->create([
            'minor_account_id' => $childAccount->uuid,
            'guardian_account_id' => $coGuardianAccount->uuid,
            'role' => 'co_guardian',
        ]);

        $this->assertFalse($this->policy->updateMinor($coGuardianUser, $childAccount));
    }

    #[Test]
    public function denies_child_from_updating_own_account(): void
    {
        $childUser = User::factory()->create();

        $childAccount = Account::factory()
            ->for($childUser, 'user')
            ->create(['account_type' => 'minor']);

        $this->assertFalse($this->policy->updateMinor($childUser, $childAccount));
    }

    #[Test]
    public function denies_non_guardian_from_updating_account(): void
    {
        $childUser = User::factory()->create();
        $randomUser = User::factory()->create();

        $childAccount = Account::factory()
            ->for($childUser, 'user')
            ->create(['account_type' => 'minor']);

        $this->assertFalse($this->policy->updateMinor($randomUser, $childAccount));
    }

    // ============= DeleteMinor Tests =============

    #[Test]
    public function allows_primary_guardian_to_delete_child_account(): void
    {
        $childUser = User::factory()->create();
        $guardianUser = User::factory()->create();

        $childAccount = Account::factory()
            ->for($childUser, 'user')
            ->create(['account_type' => 'minor']);

        $guardianAccount = Account::factory()
            ->for($guardianUser, 'user')
            ->create(['account_type' => 'personal']);

        AccountMembership::factory()->create([
            'minor_account_id' => $childAccount->uuid,
            'guardian_account_id' => $guardianAccount->uuid,
            'role' => 'guardian',
        ]);

        $this->assertTrue($this->policy->deleteMinor($guardianUser, $childAccount));
    }

    #[Test]
    public function denies_co_guardian_from_deleting_child_account(): void
    {
        $childUser = User::factory()->create();
        $coGuardianUser = User::factory()->create();

        $childAccount = Account::factory()
            ->for($childUser, 'user')
            ->create(['account_type' => 'minor']);

        $coGuardianAccount = Account::factory()
            ->for($coGuardianUser, 'user')
            ->create(['account_type' => 'personal']);

        AccountMembership::factory()->create([
            'minor_account_id' => $childAccount->uuid,
            'guardian_account_id' => $coGuardianAccount->uuid,
            'role' => 'co_guardian',
        ]);

        $this->assertFalse($this->policy->deleteMinor($coGuardianUser, $childAccount));
    }

    #[Test]
    public function denies_child_from_deleting_own_account(): void
    {
        $childUser = User::factory()->create();

        $childAccount = Account::factory()
            ->for($childUser, 'user')
            ->create(['account_type' => 'minor']);

        $this->assertFalse($this->policy->deleteMinor($childUser, $childAccount));
    }

    #[Test]
    public function denies_non_guardian_from_deleting_account(): void
    {
        $childUser = User::factory()->create();
        $randomUser = User::factory()->create();

        $childAccount = Account::factory()
            ->for($childUser, 'user')
            ->create(['account_type' => 'minor']);

        $this->assertFalse($this->policy->deleteMinor($randomUser, $childAccount));
    }

    // ============= ManageChildren Tests =============

    #[Test]
    public function allows_authenticated_user_with_personal_account_to_manage_children(): void
    {
        $parentUser = User::factory()->create();

        Account::factory()
            ->for($parentUser, 'user')
            ->create(['account_type' => 'personal']);

        $this->assertTrue($this->policy->manageChildren($parentUser));
    }

    #[Test]
    public function denies_child_account_from_managing_children(): void
    {
        $childUser = User::factory()->create();

        Account::factory()
            ->for($childUser, 'user')
            ->create(['account_type' => 'minor']);

        $this->assertFalse($this->policy->manageChildren($childUser));
    }

    #[Test]
    public function denies_user_without_personal_account_from_managing_children(): void
    {
        $user = User::factory()->create();

        // Don't create any account for this user

        $this->assertFalse($this->policy->manageChildren($user));
    }
}
