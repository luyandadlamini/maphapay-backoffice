<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorAccountLifecycleException;
use App\Domain\User\Models\UserProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cross mysql (accounts/users) and central (account_memberships) in one flow; wrapping only the
 * default connection in a test transaction causes FK-related lock waits on the central connection.
 */
class EvaluateMinorAccountLifecycleTransitionsTest extends TestCase
{
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('minor_account_lifecycle_transitions')) {
            (require base_path('database/migrations/tenant/2026_04_23_110000_create_minor_account_lifecycle_transitions_table.php'))->up();
        }
        if (! Schema::hasTable('minor_account_lifecycle_exceptions')) {
            (require base_path('database/migrations/tenant/2026_04_23_110100_create_minor_account_lifecycle_exceptions_table.php'))->up();
        }
        if (! Schema::hasTable('minor_account_lifecycle_exception_acknowledgments')) {
            (require base_path('database/migrations/tenant/2026_04_23_110110_create_minor_account_lifecycle_exception_acknowledgments_table.php'))->up();
        }
        if (! Schema::hasColumn('accounts', 'minor_transition_state') || ! Schema::hasColumn('accounts', 'minor_transition_effective_at')) {
            (require base_path('database/migrations/tenant/2026_04_23_110120_add_minor_transition_columns_to_accounts_table.php'))->up();
        }

        $this->ensureCentralTenantExists('tenant-console');
    }

    private function ensureCentralTenantExists(string $tenantId): void
    {
        $central = DB::connection('central');
        if ($central->table('tenants')->where('id', $tenantId)->exists()) {
            return;
        }

        $central->table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Lifecycle test tenant',
            'plan' => 'default',
            'team_id' => null,
            'trial_ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'data' => json_encode([]),
        ]);
    }

    #[Test]
    public function it_advances_grow_minors_into_rise_when_age_progression_is_due(): void
    {
        $guardian = User::factory()->create();
        $child = User::factory()->create(['kyc_status' => 'approved']);
        $account = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'type' => 'minor',
            'tier' => 'grow',
            'permission_level' => 3,
        ]);

        UserProfile::query()->create([
            'user_id' => $child->id,
            'email' => $child->email,
            'first_name' => 'Child',
            'status' => 'active',
            'date_of_birth' => now()->subYears(13)->toDateString(),
            'is_verified' => false,
        ]);

        AccountMembership::query()->create([
            'tenant_id' => 'tenant-console',
            'account_uuid' => $account->uuid,
            'user_uuid' => $guardian->uuid,
            'role' => 'guardian',
            'status' => 'active',
            'account_type' => 'minor',
        ]);

        $this->artisan('minor-accounts:lifecycle-evaluate', ['--account' => $account->uuid])
            ->assertSuccessful();

        $this->assertSame('rise', $account->fresh()?->tier);
    }

    #[Test]
    public function it_fails_closed_when_blocked_lifecycle_exceptions_are_produced(): void
    {
        $guardian = User::factory()->create();
        $child = User::factory()->create(['kyc_status' => 'pending']);
        $account = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'type' => 'minor',
            'tier' => 'rise',
            'permission_level' => 6,
        ]);

        UserProfile::query()->create([
            'user_id' => $child->id,
            'email' => $child->email,
            'first_name' => 'Teen',
            'status' => 'active',
            'date_of_birth' => now()->subYears(18)->toDateString(),
            'is_verified' => false,
        ]);

        AccountMembership::query()->create([
            'tenant_id' => 'tenant-console',
            'account_uuid' => $account->uuid,
            'user_uuid' => $guardian->uuid,
            'role' => 'guardian',
            'status' => 'active',
            'account_type' => 'minor',
        ]);

        $this->artisan('minor-accounts:lifecycle-evaluate', ['--account' => $account->uuid])
            ->assertFailed();

        $this->assertTrue(
            MinorAccountLifecycleException::query()
                ->where('minor_account_uuid', $account->uuid)
                ->exists()
        );
    }
}
