<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorPointsLedger;
use App\Domain\Account\Models\MinorReward;
use App\Domain\Account\Models\MinorRewardRedemption;
use App\Domain\Account\Services\MinorRewardService;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class MinorRewardConcurrencyTest extends ControllerTestCase
{
    private MinorRewardService $service;

    private Account $minorAccount;

    private MinorReward $reward;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('minor_points_ledger')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/tenant/2026_04_18_100000_create_minor_points_ledger_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasTable('minor_reward_redemptions') || ! Schema::hasColumn('minor_reward_redemptions', 'minor_account_uuid')) {
            Schema::dropIfExists('minor_reward_redemptions');

            Artisan::call('migrate', [
                '--path'  => 'database/migrations/tenant/2026_04_18_100002_create_minor_reward_redemptions_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasTable('minor_rewards')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/tenant/2026_04_20_099999_create_minor_rewards_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasColumn('minor_rewards', 'is_featured')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/tenant/2026_04_20_100000_add_phase_8_columns_to_minor_rewards_table.php',
                '--force' => true,
            ]);
        }

        $child = User::factory()->create();

        $this->minorAccount = Account::factory()->create([
            'user_uuid'        => $child->uuid,
            'type'             => 'minor',
            'permission_level' => 3,
        ]);

        MinorPointsLedger::query()->create([
            'minor_account_uuid' => $this->minorAccount->uuid,
            'points'             => 200,
            'source'             => 'seed',
            'description'        => 'Seed points',
            'reference_id'       => 'seed-points',
        ]);

        $this->reward = MinorReward::query()->create([
            'id'                   => (string) Str::uuid(),
            'name'                 => 'Single Stock Voucher',
            'description'          => 'Only one child should ever claim this',
            'points_cost'          => 100,
            'price_points'         => 100,
            'type'                 => 'voucher',
            'metadata'             => ['amount' => '10.00'],
            'stock'                => 1,
            'is_active'            => true,
            'is_featured'          => false,
            'min_permission_level' => 1,
        ]);

        $this->service = app(MinorRewardService::class);
    }

    #[Test]
    public function stale_reward_snapshots_cannot_oversell_single_stock_rewards(): void
    {
        $firstSnapshot = MinorReward::query()->findOrFail($this->reward->id);
        $staleSnapshot = MinorReward::query()->findOrFail($this->reward->id);

        $firstRedemption = $this->service->redeem($this->minorAccount, $firstSnapshot);

        try {
            $this->service->redeem($this->minorAccount, $staleSnapshot);
            self::fail('Expected duplicate redemption to be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('reward', $exception->errors());
        }

        self::assertSame('pending', $firstRedemption->status);
        self::assertSame(1, MinorRewardRedemption::query()
            ->where('minor_account_uuid', $this->minorAccount->uuid)
            ->where('minor_reward_id', $this->reward->id)
            ->count());

        self::assertSame(100, (int) MinorPointsLedger::query()
            ->where('minor_account_uuid', $this->minorAccount->uuid)
            ->sum('points'));

        self::assertSame(0, MinorReward::query()->findOrFail($this->reward->id)->stock);
    }
}
