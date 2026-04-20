<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Account;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorRedemptionApproval;
use App\Domain\Account\Models\MinorRedemptionOrder;
use App\Domain\Account\Models\MinorReward;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Stancl\Tenancy\Tenancy;
use Tests\TestCase;

class RedemptionTablesTest extends TestCase
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

    public function test_minor_reward_redemptions_table_has_required_columns(): void
    {
        $columns = \Illuminate\Support\Facades\DB::connection('tenant')
            ->getSchemaBuilder()
            ->getColumnListing('minor_reward_redemptions');

        $this->assertContains('id', $columns);
        $this->assertContains('minor_account_id', $columns);
        $this->assertContains('reward_id', $columns);
        $this->assertContains('status', $columns);
        $this->assertContains('points_redeemed', $columns);
        $this->assertContains('quantity', $columns);
        $this->assertContains('shipping_address_id', $columns);
        $this->assertContains('delivery_method', $columns);
        $this->assertContains('merchant_reference', $columns);
        $this->assertContains('tracking_number', $columns);
        $this->assertContains('child_phone_number', $columns);
        $this->assertContains('expires_at', $columns);
        $this->assertContains('completed_at', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
    }

    public function test_minor_redemption_approvals_table_has_required_columns(): void
    {
        $columns = \Illuminate\Support\Facades\DB::connection('tenant')
            ->getSchemaBuilder()
            ->getColumnListing('minor_redemption_approvals');

        $this->assertContains('id', $columns);
        $this->assertContains('redemption_id', $columns);
        $this->assertContains('parent_account_id', $columns);
        $this->assertContains('status', $columns);
        $this->assertContains('reason', $columns);
        $this->assertContains('approved_at', $columns);
        $this->assertContains('expires_at', $columns);
        $this->assertContains('created_at', $columns);
    }

    public function test_redemption_order_can_be_created(): void
    {
        $parent = Account::factory()->create();
        $child = Account::factory()
            ->create(['parent_account_id' => $parent->id, 'permission_level' => 1]);
        $reward = MinorReward::create([
            'name'         => 'Test Reward',
            'category'     => 'airtime',
            'price_points' => 100,
            'stock'        => 10,
            'description'  => 'Test reward',
            'image_url'    => 'https://example.com/test.jpg',
        ]);

        $order = MinorRedemptionOrder::create([
            'minor_account_id'   => $child->id,
            'reward_id'          => $reward->id,
            'status'             => 'awaiting_approval',
            'points_redeemed'    => 100,
            'quantity'           => 1,
            'delivery_method'    => 'sms',
            'child_phone_number' => '+268 76 123 456',
            'expires_at'         => now()->addDay(),
        ]);

        $this->assertNotNull($order->id);
        $this->assertEquals('awaiting_approval', $order->status);
        $this->assertEquals(100, $order->points_redeemed);
    }

    public function test_redemption_approval_can_be_created(): void
    {
        $parent = Account::factory()->create();
        $child = Account::factory()
            ->create(['parent_account_id' => $parent->id, 'permission_level' => 1]);
        $reward = MinorReward::create([
            'name'         => 'Test Reward',
            'category'     => 'airtime',
            'price_points' => 100,
            'stock'        => 10,
            'description'  => 'Test reward',
            'image_url'    => 'https://example.com/test.jpg',
        ]);

        $order = MinorRedemptionOrder::create([
            'minor_account_id' => $child->id,
            'reward_id'        => $reward->id,
            'status'           => 'awaiting_approval',
            'points_redeemed'  => 100,
            'quantity'         => 1,
            'expires_at'       => now()->addDay(),
        ]);

        $approval = MinorRedemptionApproval::create([
            'redemption_id'     => $order->id,
            'parent_account_id' => $parent->id,
            'status'            => 'pending',
            'expires_at'        => now()->addDay(),
        ]);

        $this->assertNotNull($approval->id);
        $this->assertEquals('pending', $approval->status);
    }
}
