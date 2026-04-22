<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorChore;
use App\Domain\Account\Models\MinorChoreCompletion;
use App\Domain\Account\Models\MinorPointsLedger;
use App\Domain\Account\Services\MinorChoreService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\CreatesApplication;

class MinorChoreTest extends BaseTestCase
{
    use CreatesApplication;

    private string $tenantId;

    private User $guardianUser;

    private User $coGuardianUser;

    private User $childUser;

    private User $strangerUser;

    private Account $guardianAccount;

    private Account $coGuardianAccount;

    private Account $minorAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        if (! Schema::hasTable('minor_chores')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/tenant/2026_04_18_100003_create_minor_chores_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasTable('minor_chore_completions')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/tenant/2026_04_18_100004_create_minor_chore_completions_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasTable('minor_points_ledger')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/tenant/2026_04_18_100000_create_minor_points_ledger_table.php',
                '--force' => true,
            ]);
        }

        $this->tenantId = (string) Str::uuid();
        DB::connection('central')->table('tenants')->insert([
            'id'            => $this->tenantId,
            'name'          => 'Minor Chore Test Tenant',
            'plan'          => 'default',
            'team_id'       => null,
            'trial_ends_at' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
            'data'          => json_encode([]),
        ]);

        $this->guardianUser = User::factory()->create();
        $this->coGuardianUser = User::factory()->create();
        $this->childUser = User::factory()->create();
        $this->strangerUser = User::factory()->create();

        $this->guardianAccount = $this->createOwnedPersonalAccount($this->guardianUser);
        $this->coGuardianAccount = $this->createOwnedPersonalAccount($this->coGuardianUser);

        $this->minorAccount = Account::factory()->create([
            'user_uuid'         => $this->childUser->uuid,
            'type'              => 'minor',
            'tier'              => 'grow',
            'permission_level'  => 3,
            'parent_account_id' => $this->guardianAccount->id,
        ]);

        $this->createMinorMembership($this->guardianUser, $this->minorAccount, 'guardian');
        $this->createMinorMembership($this->coGuardianUser, $this->minorAccount, 'co_guardian');
    }

    #[Test]
    public function guardian_can_create_chore_via_real_membership(): void
    {
        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $payload = [
            'title'         => 'Organize bookshelf',
            'payout_points' => 25,
            'description'   => 'Arrange books by color',
            'due_at'        => Carbon::now()->addDays(5)->toDateTimeString(),
        ];

        $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/chores", $payload)
            ->assertCreated()
            ->assertJsonPath('data.title', 'Organize bookshelf');

        $this->assertDatabaseHas('minor_chores', [
            'minor_account_uuid'    => $this->minorAccount->uuid,
            'guardian_account_uuid' => $this->guardianAccount->uuid,
            'title'                 => 'Organize bookshelf',
        ]);
    }

    #[Test]
    public function co_guardian_can_create_chore_via_real_membership(): void
    {
        Sanctum::actingAs($this->coGuardianUser, ['read', 'write', 'delete']);

        $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/chores", [
            'title'         => 'Wash dishes',
            'payout_points' => 10,
        ])->assertCreated();

        $this->assertDatabaseHas('minor_chores', [
            'minor_account_uuid'    => $this->minorAccount->uuid,
            'guardian_account_uuid' => $this->coGuardianAccount->uuid,
            'title'                 => 'Wash dishes',
        ]);
    }

    #[Test]
    public function child_can_list_own_chores_when_minor_has_guardian_membership(): void
    {
        MinorChore::create([
            'guardian_account_uuid' => $this->guardianAccount->uuid,
            'minor_account_uuid'    => $this->minorAccount->uuid,
            'title'                 => 'Water plants',
            'payout_points'         => 10,
            'status'                => 'active',
            'payout_type'           => 'points',
        ]);

        Sanctum::actingAs($this->childUser, ['read', 'write', 'delete']);

        $this->getJson("/api/accounts/minor/{$this->minorAccount->uuid}/chores")
            ->assertOk()
            ->assertJsonFragment(['title' => 'Water plants']);
    }

    #[Test]
    public function child_cannot_create_chore(): void
    {
        Sanctum::actingAs($this->childUser, ['read', 'write', 'delete']);

        $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/chores", [
            'title'         => 'Take out trash',
            'payout_points' => 10,
        ])->assertForbidden();
    }

    #[Test]
    public function stranger_cannot_list_chores(): void
    {
        Sanctum::actingAs($this->strangerUser, ['read', 'write', 'delete']);

        $this->getJson("/api/accounts/minor/{$this->minorAccount->uuid}/chores")
            ->assertForbidden();
    }

    #[Test]
    public function stale_completion_snapshots_cannot_award_points_twice(): void
    {
        $chore = MinorChore::create([
            'guardian_account_uuid' => $this->guardianAccount->uuid,
            'minor_account_uuid'    => $this->minorAccount->uuid,
            'title'                 => 'Vacuum the lounge',
            'payout_points'         => 40,
            'status'                => 'active',
            'payout_type'           => 'points',
        ]);

        $completion = MinorChoreCompletion::create([
            'chore_id' => $chore->id,
            'status'   => 'pending_review',
        ]);

        $firstSnapshot = MinorChoreCompletion::query()->findOrFail($completion->id);
        $staleSnapshot = MinorChoreCompletion::query()->findOrFail($completion->id);
        $service = app(MinorChoreService::class);

        $service->approve($firstSnapshot, $this->guardianAccount);

        try {
            $service->approve($staleSnapshot, $this->guardianAccount);
            self::fail('Expected duplicate chore approval to be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('completion', $exception->errors());
        }

        self::assertSame(40, (int) MinorPointsLedger::query()
            ->where('minor_account_uuid', $this->minorAccount->uuid)
            ->sum('points'));

        self::assertSame(1, MinorPointsLedger::query()
            ->where('minor_account_uuid', $this->minorAccount->uuid)
            ->where('source', 'chore')
            ->where('reference_id', $completion->id)
            ->count());
    }

    private function createOwnedPersonalAccount(User $user): Account
    {
        $account = Account::factory()->create([
            'user_uuid' => $user->uuid,
            'type'      => 'personal',
        ]);

        AccountMembership::query()->create([
            'user_uuid'    => $user->uuid,
            'tenant_id'    => $this->tenantId,
            'account_uuid' => $account->uuid,
            'account_type' => 'personal',
            'role'         => 'owner',
            'status'       => 'active',
            'joined_at'    => now(),
        ]);

        return $account;
    }

    private function createMinorMembership(User $user, Account $minorAccount, string $role): void
    {
        AccountMembership::query()->create([
            'user_uuid'    => $user->uuid,
            'tenant_id'    => $this->tenantId,
            'account_uuid' => $minorAccount->uuid,
            'account_type' => 'minor',
            'role'         => $role,
            'status'       => 'active',
            'joined_at'    => now(),
        ]);
    }
}
