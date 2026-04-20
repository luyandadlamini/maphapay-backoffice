<?php
declare(strict_types=1);

namespace Tests\Feature\Domains\Account;

use App\Domain\Account\Models\MinorReward;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorRewardsTableTest extends TestCase
{
    protected function connectionsToTransact(): array
    {
        return ['mysql', 'central'];
    }

    #[Test]
    public function minor_rewards_table_has_phase_8_columns(): void
    {
        $columns = \Illuminate\Support\Facades\DB::connection('mysql')
            ->getSchemaBuilder()
            ->getColumnListing('minor_rewards');

        // Phase 8 additions beyond Phase 4
        $this->assertContains('category', $columns);
        $this->assertContains('image_url', $columns);
        $this->assertContains('price_points', $columns);
        $this->assertContains('stock', $columns);
        $this->assertContains('is_featured', $columns);
        $this->assertContains('partner_id', $columns);
        $this->assertContains('expiry_date', $columns);
        $this->assertContains('age_restriction', $columns);
    }

    #[Test]
    public function reward_with_unlimited_stock(): void
    {
        $reward = MinorReward::create([
            'id' => Str::uuid(),
            'name' => 'MTN 50 SZL Airtime',
            'category' => 'airtime',
            'price_points' => 100,
            'stock' => -1, // unlimited
            'is_featured' => true,
            'description' => 'Instant airtime credit',
            'image_url' => 'https://example.com/mtn-50.jpg',
        ]);

        $this->assertEquals(-1, $reward->stock);
    }

    #[Test]
    public function reward_with_limited_stock(): void
    {
        $reward = MinorReward::create([
            'id' => Str::uuid(),
            'name' => 'Voucher',
            'category' => 'voucher',
            'price_points' => 200,
            'stock' => 25,
            'description' => 'Limited voucher',
            'image_url' => 'https://example.com/voucher.jpg',
        ]);

        $this->assertEquals(25, $reward->stock);
    }

    #[Test]
    public function reward_with_zero_stock_is_sold_out(): void
    {
        $reward = MinorReward::create([
            'id' => Str::uuid(),
            'name' => 'Sold Out Reward',
            'category' => 'experience',
            'price_points' => 500,
            'stock' => 0,
            'description' => 'No longer available',
            'image_url' => 'https://example.com/sold-out.jpg',
        ]);

        $this->assertEquals(0, $reward->stock);
    }
}
