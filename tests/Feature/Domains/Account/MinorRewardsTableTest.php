<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Account;

use App\Domain\Account\Models\MinorReward;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

class MinorRewardsTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create a user, team, and tenant for testing
        $user = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $user->id]);
        $tenant = Tenant::createFromTeam($team);

        // Initialize tenancy for this test
        app(Tenancy::class)->initialize($tenant);
    }

    protected function tearDown(): void
    {
        // End tenancy if active
        $tenancy = app(Tenancy::class);
        if ($tenancy->initialized) {
            $tenancy->end();
        }

        parent::tearDown();
    }

    public function test_minor_rewards_table_has_phase_8_columns(): void
    {
        $columns = \Illuminate\Support\Facades\DB::connection('tenant')
            ->getSchemaBuilder()
            ->getColumnListing('minor_rewards');

        // Phase 8 additions beyond Phase 4
        $this->assertContains('description', $columns);
        $this->assertContains('category', $columns);
        $this->assertContains('image_url', $columns);
        $this->assertContains('price_points', $columns);
        $this->assertContains('stock', $columns);
        $this->assertContains('is_featured', $columns);
        $this->assertContains('partner_id', $columns);
        $this->assertContains('expiry_date', $columns);
        $this->assertContains('age_restriction', $columns);
    }

    public function test_reward_with_unlimited_stock(): void
    {
        $reward = MinorReward::create([
            'name'         => 'MTN 50 SZL Airtime',
            'category'     => 'airtime',
            'price_points' => 100,
            'stock'        => -1, // unlimited
            'is_featured'  => true,
            'description'  => 'Instant airtime credit',
            'image_url'    => 'https://example.com/mtn-50.jpg',
        ]);

        $this->assertEquals(-1, $reward->stock);
    }

    public function test_reward_with_limited_stock(): void
    {
        $reward = MinorReward::create([
            'name'         => 'Voucher',
            'category'     => 'voucher',
            'price_points' => 200,
            'stock'        => 25,
            'description'  => 'Limited voucher',
            'image_url'    => 'https://example.com/voucher.jpg',
        ]);

        $this->assertEquals(25, $reward->stock);
    }

    public function test_reward_with_zero_stock_is_sold_out(): void
    {
        $reward = MinorReward::create([
            'name'         => 'Sold Out Reward',
            'category'     => 'experience',
            'price_points' => 500,
            'stock'        => 0,
            'description'  => 'No longer available',
            'image_url'    => 'https://example.com/sold-out.jpg',
        ]);

        $this->assertEquals(0, $reward->stock);
    }
}
