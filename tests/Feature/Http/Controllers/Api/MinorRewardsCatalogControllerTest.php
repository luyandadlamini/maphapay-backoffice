<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorPointsLedger;
use App\Domain\Account\Models\MinorReward;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\CreatesApplication;

class MinorRewardsCatalogControllerTest extends BaseTestCase
{
    use CreatesApplication;

    private string $tenantId;

    private User $guardianUser;

    private User $childUser;

    private Account $guardianAccount;

    private Account $minorAccount;

    private MinorReward $reward;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        if (! Schema::hasTable('minor_points_ledger')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/tenant/2026_04_18_100000_create_minor_points_ledger_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasTable('minor_rewards')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/tenant/2026_04_20_099999_create_minor_rewards_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasColumn('minor_rewards', 'is_featured')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/tenant/2026_04_20_100000_add_phase_8_columns_to_minor_rewards_table.php',
                '--force' => true,
            ]);
        }

        $this->tenantId = (string) Str::uuid();
        DB::connection('central')->table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Minor Phase 8 Catalog Tenant',
            'plan' => 'default',
            'team_id' => null,
            'trial_ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'data' => json_encode([]),
        ]);

        $this->guardianUser = User::factory()->create();
        $this->childUser = User::factory()->create();

        $this->guardianAccount = $this->createOwnedPersonalAccount($this->guardianUser);
        $this->minorAccount = Account::factory()->create([
            'user_uuid' => $this->childUser->uuid,
            'type' => 'minor',
            'permission_level' => 3,
            'parent_account_id' => $this->guardianAccount->uuid,
        ]);

        $this->createMinorMembership($this->guardianUser, $this->minorAccount, 'guardian');

        MinorPointsLedger::query()->create([
            'minor_account_uuid' => $this->minorAccount->uuid,
            'points' => 450,
            'source' => 'seed',
            'description' => 'Seed points',
            'reference_id' => 'seed-points',
        ]);

        $this->reward = MinorReward::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Museum Voucher',
            'category' => 'experiences',
            'description' => 'Family museum pass',
            'image_url' => 'https://example.test/museum.png',
            'points_cost' => 300,
            'price_points' => 300,
            'type' => 'voucher',
            'metadata' => ['partner_name' => 'National Museum'],
            'stock' => 4,
            'is_active' => true,
            'is_featured' => true,
            'min_permission_level' => 2,
        ]);
    }

    #[Test]
    public function catalog_endpoint_returns_the_stabilized_phase_8_reward_contract(): void
    {
        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $response = $this->getJson("/api/accounts/minor/{$this->minorAccount->uuid}/rewards/catalog");

        $response->assertOk()
            ->assertJsonPath('data.minor_account_uuid', $this->minorAccount->uuid)
            ->assertJsonPath('data.points_balance', 450);

        $rewardPayload = collect($response->json('data.rewards'))
            ->firstWhere('id', $this->reward->id);

        self::assertNotNull($rewardPayload);
        self::assertSame(300, $rewardPayload['price_points'] ?? null);
        self::assertTrue($rewardPayload['requires_approval'] ?? false);
        self::assertTrue($rewardPayload['can_redeem'] ?? false);
        self::assertTrue($rewardPayload['is_affordable'] ?? false);
        self::assertSame(4, $rewardPayload['stock_remaining'] ?? null);
    }

    #[Test]
    public function reward_detail_endpoint_returns_the_stabilized_phase_8_reward_detail_contract(): void
    {
        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $response = $this->getJson("/api/accounts/minor/{$this->minorAccount->uuid}/rewards/{$this->reward->id}");

        $response->assertOk()
            ->assertJsonPath('data.minor_account_uuid', $this->minorAccount->uuid)
            ->assertJsonPath('data.reward.id', $this->reward->id)
            ->assertJsonPath('data.reward.name', 'Museum Voucher')
            ->assertJsonPath('data.reward.category', 'experiences')
            ->assertJsonPath('data.reward.price_points', 300)
            ->assertJsonPath('data.reward.requires_approval', true)
            ->assertJsonPath('data.reward.is_featured', true);
    }

    private function createOwnedPersonalAccount(User $user): Account
    {
        $account = Account::factory()->create([
            'user_uuid' => $user->uuid,
            'type' => 'personal',
        ]);

        AccountMembership::query()->create([
            'user_uuid' => $user->uuid,
            'tenant_id' => $this->tenantId,
            'account_uuid' => $account->uuid,
            'account_type' => 'personal',
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $account;
    }

    private function createMinorMembership(User $user, Account $minorAccount, string $role): void
    {
        AccountMembership::query()->create([
            'user_uuid' => $user->uuid,
            'tenant_id' => $this->tenantId,
            'account_uuid' => $minorAccount->uuid,
            'account_type' => 'minor',
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }
}
