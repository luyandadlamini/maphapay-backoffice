<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Policies;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Policies\AccountPolicy;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
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

    // =========================================================================
    // viewMinor Tests
    // =========================================================================

    #[Test]
    public function test_viewMinor_allows_child_viewing_own_account(): void
    {
        // Arrange
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'account_type' => 'minor',
        ]);

        // Act
        $result = $this->policy->viewMinor($child, $childAccount);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function test_viewMinor_allows_guardian_viewing_minor_account(): void
    {
        // Arrange
        $guardian = User::factory()->create();
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'account_type' => 'minor',
        ]);

        // Get the guardian's team/tenant
        $team = $guardian->teams()->first() ?? \App\Models\Team::factory()->create(['user_id' => $guardian->id]);
        $tenant = \App\Models\Tenant::firstOrCreate(['team_id' => $team->id], ['name' => $team->name]);

        // Create active guardian membership
        AccountMembership::create([
            'account_uuid' => $childAccount->uuid,
            'user_uuid' => $guardian->uuid,
            'tenant_id' => $tenant->id,
            'role' => 'guardian',
            'status' => 'active',
        ]);

        // Act
        $result = $this->policy->viewMinor($guardian, $childAccount);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function test_viewMinor_allows_co_guardian_viewing_minor_account(): void
    {
        // Arrange
        $coGuardian = User::factory()->create();
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'account_type' => 'minor',
        ]);

        // Get the co-guardian's tenant
        $team = $coGuardian->teams()->first() ?? \App\Models\Team::factory()->create(['user_id' => $coGuardian->id]);
        $tenant = \App\Models\Tenant::firstOrCreate(['team_id' => $team->id], ['name' => $team->name]);

        // Create active co_guardian membership
        AccountMembership::create([
            'account_uuid' => $childAccount->uuid,
            'user_uuid' => $coGuardian->uuid,
            'tenant_id' => $tenant->id,
            'role' => 'co_guardian',
            'status' => 'active',
        ]);

        // Act
        $result = $this->policy->viewMinor($coGuardian, $childAccount);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function test_viewMinor_denies_non_guardian(): void
    {
        // Arrange
        $nonGuardian = User::factory()->create();
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'account_type' => 'minor',
        ]);

        // Act
        $result = $this->policy->viewMinor($nonGuardian, $childAccount);

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function test_viewMinor_denies_inactive_membership(): void
    {
        // Arrange
        $guardian = User::factory()->create();
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'account_type' => 'minor',
        ]);

        // Get the guardian's tenant
        $team = $guardian->teams()->first() ?? \App\Models\Team::factory()->create(['user_id' => $guardian->id]);
        $tenant = \App\Models\Tenant::firstOrCreate(['team_id' => $team->id], ['name' => $team->name]);

        // Create inactive guardian membership
        AccountMembership::create([
            'account_uuid' => $childAccount->uuid,
            'user_uuid' => $guardian->uuid,
            'tenant_id' => $tenant->id,
            'role' => 'guardian',
            'status' => 'inactive',
        ]);

        // Act
        $result = $this->policy->viewMinor($guardian, $childAccount);

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function test_viewMinor_denies_random_user(): void
    {
        // Arrange
        $randomUser = User::factory()->create();
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'account_type' => 'minor',
        ]);

        // Act
        $result = $this->policy->viewMinor($randomUser, $childAccount);

        // Assert
        $this->assertFalse($result);
    }

    // =========================================================================
    // viewAnyMinor Tests
    // =========================================================================

    #[Test]
    public function test_viewAnyMinor_allows_user_with_active_guardian_membership(): void
    {
        // Arrange
        $guardian = User::factory()->create();
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'account_type' => 'minor',
        ]);

        // Get the guardian's tenant
        $team = $guardian->teams()->first() ?? \App\Models\Team::factory()->create(['user_id' => $guardian->id]);
        $tenant = \App\Models\Tenant::firstOrCreate(['team_id' => $team->id], ['name' => $team->name]);

        // Create active guardian membership
        AccountMembership::create([
            'account_uuid' => $childAccount->uuid,
            'user_uuid' => $guardian->uuid,
            'tenant_id' => $tenant->id,
            'role' => 'guardian',
            'status' => 'active',
        ]);

        // Act
        $result = $this->policy->viewAnyMinor($guardian);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function test_viewAnyMinor_allows_user_with_active_co_guardian_membership(): void
    {
        // Arrange
        $coGuardian = User::factory()->create();
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'account_type' => 'minor',
        ]);

        // Get the co-guardian's tenant
        $team = $coGuardian->teams()->first() ?? \App\Models\Team::factory()->create(['user_id' => $coGuardian->id]);
        $tenant = \App\Models\Tenant::firstOrCreate(['team_id' => $team->id], ['name' => $team->name]);

        // Create active co_guardian membership
        AccountMembership::create([
            'account_uuid' => $childAccount->uuid,
            'user_uuid' => $coGuardian->uuid,
            'tenant_id' => $tenant->id,
            'role' => 'co_guardian',
            'status' => 'active',
        ]);

        // Act
        $result = $this->policy->viewAnyMinor($coGuardian);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function test_viewAnyMinor_denies_user_without_guardian_membership(): void
    {
        // Arrange
        $user = User::factory()->create();

        // Act
        $result = $this->policy->viewAnyMinor($user);

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function test_viewAnyMinor_denies_user_with_inactive_membership(): void
    {
        // Arrange
        $guardian = User::factory()->create();
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'account_type' => 'minor',
        ]);

        // Get the guardian's tenant
        $team = $guardian->teams()->first() ?? \App\Models\Team::factory()->create(['user_id' => $guardian->id]);
        $tenant = \App\Models\Tenant::firstOrCreate(['team_id' => $team->id], ['name' => $team->name]);

        // Create inactive guardian membership
        AccountMembership::create([
            'account_uuid' => $childAccount->uuid,
            'user_uuid' => $guardian->uuid,
            'tenant_id' => $tenant->id,
            'role' => 'guardian',
            'status' => 'inactive',
        ]);

        // Act
        $result = $this->policy->viewAnyMinor($guardian);

        // Assert
        $this->assertFalse($result);
    }

    // =========================================================================
    // createMinor Tests
    // =========================================================================

    #[Test]
    public function test_createMinor_allows_user_with_owner_role_on_personal_account(): void
    {
        // Arrange
        $parent = User::factory()->create();
        $personalAccount = Account::factory()->create([
            'user_uuid' => $parent->uuid,
            'account_type' => 'personal',
        ]);

        // Get the parent's tenant
        $team = $parent->teams()->first() ?? \App\Models\Team::factory()->create(['user_id' => $parent->id]);
        $tenant = \App\Models\Tenant::firstOrCreate(['team_id' => $team->id], ['name' => $team->name]);

        // Create owner membership on personal account
        AccountMembership::create([
            'account_uuid' => $personalAccount->uuid,
            'user_uuid' => $parent->uuid,
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'status' => 'active',
            'account_type' => 'personal',
        ]);

        // Act
        $result = $this->policy->createMinor($parent);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function test_createMinor_denies_minor_account_user(): void
    {
        // Arrange
        $child = User::factory()->create();
        $minorAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'account_type' => 'minor',
        ]);

        // Get the child's tenant
        $team = $child->teams()->first() ?? \App\Models\Team::factory()->create(['user_id' => $child->id]);
        $tenant = \App\Models\Tenant::firstOrCreate(['team_id' => $team->id], ['name' => $team->name]);

        // Create owner membership on minor account (shouldn't allow creating)
        AccountMembership::create([
            'account_uuid' => $minorAccount->uuid,
            'user_uuid' => $child->uuid,
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'status' => 'active',
            'account_type' => 'minor',
        ]);

        // Act
        $result = $this->policy->createMinor($child);

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function test_createMinor_denies_user_without_personal_account(): void
    {
        // Arrange
        $user = User::factory()->create();

        // Act
        $result = $this->policy->createMinor($user);

        // Assert
        $this->assertFalse($result);
    }

    // =========================================================================
    // updateMinor Tests
    // =========================================================================

    #[Test]
    public function test_updateMinor_allows_primary_guardian(): void
    {
        // Arrange
        $guardian = User::factory()->create();
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'account_type' => 'minor',
        ]);

        // Get the guardian's tenant
        $team = $guardian->teams()->first() ?? \App\Models\Team::factory()->create(['user_id' => $guardian->id]);
        $tenant = \App\Models\Tenant::firstOrCreate(['team_id' => $team->id], ['name' => $team->name]);

        // Create guardian membership
        AccountMembership::create([
            'account_uuid' => $childAccount->uuid,
            'user_uuid' => $guardian->uuid,
            'tenant_id' => $tenant->id,
            'role' => 'guardian',
            'status' => 'active',
        ]);

        // Act
        $result = $this->policy->updateMinor($guardian, $childAccount);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function test_updateMinor_denies_co_guardian(): void
    {
        // Arrange
        $coGuardian = User::factory()->create();
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'account_type' => 'minor',
        ]);

        // Get the co-guardian's tenant
        $team = $coGuardian->teams()->first() ?? \App\Models\Team::factory()->create(['user_id' => $coGuardian->id]);
        $tenant = \App\Models\Tenant::firstOrCreate(['team_id' => $team->id], ['name' => $team->name]);

        // Create co_guardian membership
        AccountMembership::create([
            'account_uuid' => $childAccount->uuid,
            'user_uuid' => $coGuardian->uuid,
            'tenant_id' => $tenant->id,
            'role' => 'co_guardian',
            'status' => 'active',
        ]);

        // Act
        $result = $this->policy->updateMinor($coGuardian, $childAccount);

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function test_updateMinor_denies_child(): void
    {
        // Arrange
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'account_type' => 'minor',
        ]);

        // Act
        $result = $this->policy->updateMinor($child, $childAccount);

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function test_updateMinor_denies_non_guardian(): void
    {
        // Arrange
        $nonGuardian = User::factory()->create();
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'account_type' => 'minor',
        ]);

        // Act
        $result = $this->policy->updateMinor($nonGuardian, $childAccount);

        // Assert
        $this->assertFalse($result);
    }

    // =========================================================================
    // deleteMinor Tests
    // =========================================================================

    #[Test]
    public function test_deleteMinor_allows_primary_guardian(): void
    {
        // Arrange
        $guardian = User::factory()->create();
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'account_type' => 'minor',
        ]);

        // Get the guardian's tenant
        $team = $guardian->teams()->first() ?? \App\Models\Team::factory()->create(['user_id' => $guardian->id]);
        $tenant = \App\Models\Tenant::firstOrCreate(['team_id' => $team->id], ['name' => $team->name]);

        // Create guardian membership
        AccountMembership::create([
            'account_uuid' => $childAccount->uuid,
            'user_uuid' => $guardian->uuid,
            'tenant_id' => $tenant->id,
            'role' => 'guardian',
            'status' => 'active',
        ]);

        // Act
        $result = $this->policy->deleteMinor($guardian, $childAccount);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function test_deleteMinor_denies_co_guardian(): void
    {
        // Arrange
        $coGuardian = User::factory()->create();
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'account_type' => 'minor',
        ]);

        // Get the co-guardian's tenant
        $team = $coGuardian->teams()->first() ?? \App\Models\Team::factory()->create(['user_id' => $coGuardian->id]);
        $tenant = \App\Models\Tenant::firstOrCreate(['team_id' => $team->id], ['name' => $team->name]);

        // Create co_guardian membership
        AccountMembership::create([
            'account_uuid' => $childAccount->uuid,
            'user_uuid' => $coGuardian->uuid,
            'tenant_id' => $tenant->id,
            'role' => 'co_guardian',
            'status' => 'active',
        ]);

        // Act
        $result = $this->policy->deleteMinor($coGuardian, $childAccount);

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function test_deleteMinor_denies_child(): void
    {
        // Arrange
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'account_type' => 'minor',
        ]);

        // Act
        $result = $this->policy->deleteMinor($child, $childAccount);

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function test_deleteMinor_denies_non_guardian(): void
    {
        // Arrange
        $nonGuardian = User::factory()->create();
        $child = User::factory()->create();
        $childAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'account_type' => 'minor',
        ]);

        // Act
        $result = $this->policy->deleteMinor($nonGuardian, $childAccount);

        // Assert
        $this->assertFalse($result);
    }

    // =========================================================================
    // manageChildren Tests
    // =========================================================================

    #[Test]
    public function test_manageChildren_allows_user_with_owner_role_on_personal_account(): void
    {
        // Arrange
        $parent = User::factory()->create();
        $personalAccount = Account::factory()->create([
            'user_uuid' => $parent->uuid,
            'account_type' => 'personal',
        ]);

        // Get the parent's tenant
        $team = $parent->teams()->first() ?? \App\Models\Team::factory()->create(['user_id' => $parent->id]);
        $tenant = \App\Models\Tenant::firstOrCreate(['team_id' => $team->id], ['name' => $team->name]);

        // Create owner membership on personal account
        AccountMembership::create([
            'account_uuid' => $personalAccount->uuid,
            'user_uuid' => $parent->uuid,
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'status' => 'active',
            'account_type' => 'personal',
        ]);

        // Act
        $result = $this->policy->manageChildren($parent);

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function test_manageChildren_denies_minor_account_user(): void
    {
        // Arrange
        $child = User::factory()->create();
        $minorAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'account_type' => 'minor',
        ]);

        // Get the child's tenant
        $team = $child->teams()->first() ?? \App\Models\Team::factory()->create(['user_id' => $child->id]);
        $tenant = \App\Models\Tenant::firstOrCreate(['team_id' => $team->id], ['name' => $team->name]);

        // Create owner membership on minor account
        AccountMembership::create([
            'account_uuid' => $minorAccount->uuid,
            'user_uuid' => $child->uuid,
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'status' => 'active',
            'account_type' => 'minor',
        ]);

        // Act
        $result = $this->policy->manageChildren($child);

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function test_manageChildren_denies_user_without_personal_account(): void
    {
        // Arrange
        $user = User::factory()->create();

        // Act
        $result = $this->policy->manageChildren($user);

        // Assert
        $this->assertFalse($result);
    }
}
