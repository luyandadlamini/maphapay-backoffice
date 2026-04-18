<?php
declare(strict_types=1);
namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorPointsLedger;
use App\Domain\Account\Models\MinorReward;
use App\Domain\Account\Services\MinorRewardService;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorRewardTest extends TestCase
{
    protected function connectionsToTransact(): array { return ['mysql', 'central']; }
    protected function shouldCreateDefaultAccountsInSetup(): bool { return false; }

    private MinorRewardService $service;
    private Account $minorAccount;
    private MinorReward $reward;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MinorRewardService::class);
        $user = User::factory()->create();
        $this->minorAccount = Account::factory()->create([
            'user_uuid'        => $user->uuid,
            'type'             => 'minor',
            'permission_level' => 3,
        ]);
        // Give the account 500 points
        MinorPointsLedger::create([
            'minor_account_uuid' => $this->minorAccount->uuid,
            'points'             => 500,
            'source'             => 'level_unlock',
            'description'        => 'Test points',
            'reference_id'       => null,
        ]);
        $this->reward = MinorReward::create([
            'id'          => Str::uuid(),
            'name'        => 'Test Airtime',
            'description' => 'Test',
            'points_cost' => 100,
            'type'        => 'airtime',
            'metadata'    => ['amount' => '50.00', 'provider' => 'MTN'],
            'stock'       => 5,
            'is_active'   => true,
            'min_permission_level' => 1,
        ]);
    }

    #[Test]
    public function it_redeems_reward_deducting_points_and_decrementing_stock(): void
    {
        $redemption = $this->service->redeem($this->minorAccount, $this->reward);

        $this->assertSame('pending', $redemption->status);
        $this->assertSame($this->reward->points_cost, $redemption->points_cost);
        $this->assertDatabaseHas('minor_reward_redemptions', [
            'minor_account_uuid' => $this->minorAccount->uuid,
            'minor_reward_id'    => $this->reward->id,
            'status'             => 'pending',
        ]);
        // Stock decremented
        $this->assertDatabaseHas('minor_rewards', [
            'id'    => $this->reward->id,
            'stock' => 4,
        ]);
        // Points deducted
        $this->assertDatabaseHas('minor_points_ledger', [
            'minor_account_uuid' => $this->minorAccount->uuid,
            'points'             => -100,
            'source'             => 'redemption',
        ]);
    }

    #[Test]
    public function it_throws_when_points_are_insufficient(): void
    {
        // Drain most points
        MinorPointsLedger::create([
            'minor_account_uuid' => $this->minorAccount->uuid,
            'points'             => -450,
            'source'             => 'redemption',
            'description'        => 'Drain',
            'reference_id'       => 'drain',
        ]);
        // Balance is now 50, reward costs 100

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->redeem($this->minorAccount, $this->reward);
    }

    #[Test]
    public function it_throws_when_reward_is_out_of_stock(): void
    {
        $this->reward->update(['stock' => 0]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->redeem($this->minorAccount, $this->reward);
    }

    #[Test]
    public function it_throws_when_reward_is_inactive(): void
    {
        $this->reward->update(['is_active' => false]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->service->redeem($this->minorAccount, $this->reward);
    }

    #[Test]
    public function unlimited_stock_reward_does_not_decrement(): void
    {
        $this->reward->update(['stock' => -1]);
        $this->service->redeem($this->minorAccount, $this->reward);

        $this->assertDatabaseHas('minor_rewards', ['id' => $this->reward->id, 'stock' => -1]);
    }
}
