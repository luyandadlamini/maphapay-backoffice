<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\TransactionProjection;
use App\Models\User;
use App\Policies\AccountPolicy;
use App\Rules\ValidateMinorAccountPermission;
use Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

class MinorAccountIntegrationTest extends TestCase
{
    protected function connectionsToTransact(): array
    {
        return ['mysql', 'central'];
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    #[Test]
    public function it_covers_the_phase_one_minor_account_workflow(): void
    {
        $this->withoutMiddleware();

        if (! Schema::connection('central')->hasTable('guardian_invites')) {
            Artisan::call('migrate', [
                '--database' => 'central',
                '--path'     => 'database/migrations/2026_04_16_130000_create_guardian_invites_table.php',
                '--force'    => true,
            ]);
        }

        $tenantId = (string) Str::uuid();
        DB::connection('central')->table('tenants')->insert([
            'id'            => $tenantId,
            'name'          => 'Integration Tenant',
            'plan'          => 'default',
            'team_id'       => null,
            'trial_ends_at' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
            'data'          => json_encode([]),
        ]);

        $parent = User::factory()->create();
        $coGuardian = User::factory()->create();
        $child = User::factory()->create();

        $parentAccount = Account::factory()->create([
            'user_uuid'    => $parent->uuid,
            'type'         => 'personal',
        ]);

        AccountMembership::query()->create([
            'user_uuid'    => $parent->uuid,
            'tenant_id'    => $tenantId,
            'account_uuid' => $parentAccount->uuid,
            'type'         => 'personal',
            'role'         => 'owner',
            'status'       => 'active',
            'joined_at'    => now(),
        ]);

        Sanctum::actingAs($parent, ['read', 'write', 'delete']);

        $createResponse = $this->postJson('/api/accounts/minor', [
            'name'          => 'Emma',
            'date_of_birth' => now()->subYears(10)->format('Y-m-d'),
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.account.account_tier', 'grow')
            ->assertJsonPath('data.account.permission_level', 3);

        $minorAccountUuid = (string) $createResponse->json('data.account.uuid');
        $minorAccount = Account::query()->where('uuid', $minorAccountUuid)->firstOrFail();

        $minorAccount->update(['user_uuid' => $child->uuid]);

        $accountsResponse = $this->getJson('/api/accounts');
        $accountsResponse->assertOk();
        $this->assertTrue(collect($accountsResponse->json('data'))->contains(
            fn (array $account): bool => ($account['account_uuid'] ?? null) === $minorAccountUuid
        ));

        $inviteResponse = $this
            ->withHeaders(['X-Account-Id' => $minorAccountUuid])
            ->postJson("/api/accounts/minor/{$minorAccountUuid}/invite-co-guardian");

        $inviteResponse->assertOk();
        $inviteCode = (string) $inviteResponse->json('data.code');

        Sanctum::actingAs($coGuardian, ['read', 'write', 'delete']);

        $acceptResponse = $this->postJson("/api/guardian-invites/{$inviteCode}/accept");
        $acceptResponse->assertOk()
            ->assertJsonPath('data.role', 'co_guardian');

        $policy = app(AccountPolicy::class);

        $this->assertTrue($policy->viewMinor($coGuardian, $minorAccount->fresh()));
        $this->assertFalse($policy->updateMinor($coGuardian, $minorAccount->fresh()));

        $withinLimitValidator = Validator::make(
            ['amount' => 10000],
            ['amount' => [new ValidateMinorAccountPermission($minorAccount->fresh(), 'transfer')]],
        );
        $this->assertFalse($withinLimitValidator->fails());

        TransactionProjection::factory()->create([
            'account_uuid' => $minorAccountUuid,
            'amount'       => 45000,
            'type'         => 'transfer',
            'status'       => 'completed',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $overLimitValidator = Validator::make(
            ['amount' => 10000],
            ['amount' => [new ValidateMinorAccountPermission($minorAccount->fresh(), 'transfer')]],
        );
        $this->assertTrue($overLimitValidator->fails());

        Sanctum::actingAs($parent, ['read', 'write', 'delete']);

        $updateLevelResponse = $this
            ->withHeaders(['X-Account-Id' => $minorAccountUuid])
            ->putJson("/api/accounts/minor/{$minorAccountUuid}/permission-level", [
                'permission_level' => 4,
            ]);

        $updateLevelResponse->assertOk()
            ->assertJsonPath('data.permission_level', 4);

        Sanctum::actingAs($coGuardian, ['read', 'write', 'delete']);

        $coGuardianUpdateResponse = $this
            ->withHeaders(['X-Account-Id' => $minorAccountUuid])
            ->putJson("/api/accounts/minor/{$minorAccountUuid}/permission-level", [
                'permission_level' => 5,
            ]);

        $coGuardianUpdateResponse->assertForbidden();
    }
}
