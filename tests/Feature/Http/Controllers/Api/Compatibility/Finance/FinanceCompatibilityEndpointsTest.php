<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\Finance;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Asset\Models\Asset;
use App\Domain\Mobile\Models\MobileNotificationPreference;
use App\Domain\Mobile\Models\MobilePushNotification;
use App\Domain\Mobile\Models\Pocket;
use App\Domain\Mobile\Models\PocketSmartRule;
use App\Domain\Rewards\Models\RewardProfile;
use App\Domain\Rewards\Models\RewardRedemption;
use App\Domain\Rewards\Models\RewardShopItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class FinanceCompatibilityEndpointsTest extends ControllerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['maphapay_migration.enable_transaction_history' => true]);
        $this->ensureFinanceTablesExist();

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            ['name' => 'Swazi Lilangeni', 'type' => 'fiat', 'precision' => 2, 'is_active' => true],
        );
    }

    /** @return array{User, Account} */
    private function makeUserWithAccount(): array
    {
        $user = User::factory()->create([
            'kyc_status' => 'approved',
        ]);

        $account = Account::factory()->create([
            'user_uuid' => $user->uuid,
            'frozen'    => false,
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        return [$user, $account];
    }

    private function setModelTimestamp(object $model, CarbonImmutable $timestamp): void
    {
        $model->forceFill([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->saveQuietly();
    }

    private function ensureFinanceTablesExist(): void
    {
        if (! Schema::hasTable('pockets')) {
            Schema::create('pockets', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->uuid('uuid')->unique();
                $table->uuid('user_uuid')->index();
                $table->string('name');
                $table->decimal('target_amount', 20, 2)->default(0);
                $table->decimal('current_amount', 20, 2)->default(0);
                $table->date('target_date')->nullable();
                $table->string('category')->nullable();
                $table->string('color')->nullable();
                $table->boolean('is_completed')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pocket_smart_rules')) {
            Schema::create('pocket_smart_rules', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->uuid('pocket_id')->index();
                $table->boolean('round_up_change')->default(false);
                $table->boolean('auto_save_deposits')->default(false);
                $table->boolean('auto_save_salary')->default(false);
                $table->decimal('auto_save_amount', 20, 2)->default(0);
                $table->string('auto_save_frequency')->default('monthly');
                $table->boolean('lock_pocket')->default(false);
                $table->boolean('notify_on_transfer')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('reward_profiles')) {
            Schema::create('reward_profiles', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('user_id')->unique();
                $table->integer('xp')->default(0);
                $table->integer('level')->default(1);
                $table->integer('current_streak')->default(0);
                $table->integer('longest_streak')->default(0);
                $table->date('last_activity_date')->nullable();
                $table->integer('points_balance')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('reward_shop_items')) {
            Schema::create('reward_shop_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('slug')->unique();
                $table->string('title');
                $table->text('description')->nullable();
                $table->integer('points_cost')->default(0);
                $table->string('category')->nullable();
                $table->string('icon')->nullable();
                $table->integer('stock')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('reward_redemptions')) {
            Schema::create('reward_redemptions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('reward_profile_id')->index();
                $table->uuid('shop_item_id')->index();
                $table->integer('points_spent')->default(0);
                $table->string('status')->default('completed');
                $table->timestamp('fulfilled_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mobile_push_notifications')) {
            Schema::create('mobile_push_notifications', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('user_id')->index();
                $table->uuid('mobile_device_id')->nullable();
                $table->string('notification_type');
                $table->string('title');
                $table->text('body');
                $table->json('data')->nullable();
                $table->string('status')->default('pending');
                $table->string('external_id')->nullable();
                $table->text('error_message')->nullable();
                $table->unsignedInteger('retry_count')->default(0);
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mobile_notification_preferences')) {
            Schema::create('mobile_notification_preferences', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('user_id')->index();
                $table->uuid('mobile_device_id')->nullable();
                $table->string('notification_type');
                $table->boolean('push_enabled')->default(true);
                $table->boolean('email_enabled')->default(false);
                $table->timestamps();
            });
        }
    }

    #[Test]
    public function test_transactions_sync_returns_only_changed_rows_after_cursor(): void
    {
        [$user, $account] = $this->makeUserWithAccount();

        $older = TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'reference'    => 'REF-OLD',
            'description'  => 'Older transaction',
            'subtype'      => 'send_money',
            'status'       => 'completed',
        ]);
        $newer = TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'reference'    => 'REF-NEW',
            'description'  => 'Newer transaction',
            'subtype'      => 'request_money',
            'status'       => 'completed',
        ]);

        $this->setModelTimestamp($older, CarbonImmutable::parse('2026-04-01T08:00:00Z'));
        $this->setModelTimestamp($newer, CarbonImmutable::parse('2026-04-02T09:30:00Z'));

        $response = $this->getJson('/api/transactions/sync?changed_since=2026-04-01T12:00:00Z');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('remark', 'transactions_sync')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.reference', 'REF-NEW')
            ->assertJsonPath('deleted_ids', []);

        $this->assertNotEmpty($response->json('next_sync_token'));
    }

    #[Test]
    public function test_pockets_sync_returns_only_changed_rows_after_cursor(): void
    {
        [$user] = $this->makeUserWithAccount();

        $older = Pocket::create([
            'uuid'           => (string) Str::uuid(),
            'user_uuid'      => $user->uuid,
            'name'           => 'Trip',
            'target_amount'  => '2000.00',
            'current_amount' => '150.00',
            'target_date'    => '2026-12-31',
            'category'       => 'travel',
            'color'          => '#00A86B',
            'is_completed'   => false,
        ]);
        $newer = Pocket::create([
            'uuid'           => (string) Str::uuid(),
            'user_uuid'      => $user->uuid,
            'name'           => 'Emergency',
            'target_amount'  => '5000.00',
            'current_amount' => '750.00',
            'target_date'    => '2026-10-01',
            'category'       => 'emergency',
            'color'          => '#FF6B35',
            'is_completed'   => false,
        ]);

        PocketSmartRule::create([
            'pocket_id' => $newer->uuid,
            ...PocketSmartRule::defaults(),
            'round_up_change' => true,
        ]);

        $this->setModelTimestamp($older, CarbonImmutable::parse('2026-04-01T07:00:00Z'));
        $this->setModelTimestamp($newer, CarbonImmutable::parse('2026-04-02T15:45:00Z'));

        $response = $this->getJson('/api/pockets/sync?changed_since=2026-04-01T12:00:00Z');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('remark', 'pockets_sync')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.name', 'Emergency')
            ->assertJsonPath('items.0.smart_rules.round_up_change', true)
            ->assertJsonPath('deleted_ids', []);
    }

    #[Test]
    public function test_rewards_endpoints_return_real_rewards_data_and_sync_payload(): void
    {
        [$user] = $this->makeUserWithAccount();

        RewardProfile::create([
            'user_id'        => $user->id,
            'xp'             => 120,
            'level'          => 2,
            'current_streak' => 3,
            'longest_streak' => 7,
            'points_balance' => 420,
        ]);

        $rewardA = RewardShopItem::create([
            'slug'        => 'coffee-voucher',
            'title'       => 'Coffee Voucher',
            'description' => 'Free coffee',
            'points_cost' => 150,
            'category'    => 'food',
            'icon'        => 'coffee',
            'stock'       => 10,
            'is_active'   => true,
            'sort_order'  => 1,
        ]);
        $rewardB = RewardShopItem::create([
            'slug'        => 'travel-discount',
            'title'       => 'Travel Discount',
            'description' => 'Discount on travel',
            'points_cost' => 700,
            'category'    => 'travel',
            'icon'        => 'plane',
            'stock'       => 5,
            'is_active'   => true,
            'sort_order'  => 2,
        ]);

        $redemption = RewardRedemption::create([
            'reward_profile_id' => RewardProfile::where('user_id', $user->id)->firstOrFail()->id,
            'shop_item_id'      => $rewardA->id,
            'points_spent'      => $rewardA->points_cost,
            'status'            => 'completed',
        ]);

        $this->setModelTimestamp($rewardA, CarbonImmutable::parse('2026-04-01T08:00:00Z'));
        $this->setModelTimestamp($rewardB, CarbonImmutable::parse('2026-04-02T18:00:00Z'));
        $this->setModelTimestamp($redemption, CarbonImmutable::parse('2026-04-01T09:00:00Z'));

        $this->getJson('/api/rewards')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data.rewards')
            ->assertJsonPath('data.rewards.0.is_redeemed', true);

        $this->getJson('/api/rewards/points')
            ->assertOk()
            ->assertJsonPath('data.points.currentBalance', 420);

        $this->getJson('/api/rewards/sync?changed_since=2026-04-01T12:00:00Z')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(1, 'data.rewards')
            ->assertJsonPath('data.rewards.0.title', 'Travel Discount')
            ->assertJsonPath('data.points.currentBalance', 420);
    }

    #[Test]
    public function test_rewards_redeem_endpoint_uses_compat_contract_and_uuid_ids(): void
    {
        [$user] = $this->makeUserWithAccount();

        RewardProfile::create([
            'user_id' => $user->id,
            'points_balance' => 1000,
        ]);

        $reward = RewardShopItem::create([
            'slug' => 'bronze-tier',
            'title' => 'Bronze Tier',
            'description' => 'Unlock bronze perks',
            'points_cost' => 250,
            'category' => 'tiers',
            'icon' => 'medal',
            'stock' => 10,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->postJson('/api/rewards/redeem', [
            'reward_id' => $reward->id,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('remark', 'reward_redeem')
            ->assertJsonPath('data.item_id', $reward->id)
            ->assertJsonPath('data.points_spent', 250)
            ->assertJsonPath('data.points_balance', 750);

        $this->assertDatabaseHas('reward_redemptions', [
            'reward_profile_id' => RewardProfile::where('user_id', $user->id)->firstOrFail()->id,
            'shop_item_id' => $reward->id,
            'status' => 'completed',
        ]);
    }

    #[Test]
    public function test_push_notification_compat_endpoints_list_mark_read_and_sync(): void
    {
        [$user] = $this->makeUserWithAccount();

        $older = MobilePushNotification::create([
            'user_id'           => $user->id,
            'notification_type' => MobilePushNotification::TYPE_GENERAL,
            'title'             => 'Older notice',
            'body'              => 'Already here',
            'status'            => MobilePushNotification::STATUS_DELIVERED,
        ]);
        $newer = MobilePushNotification::create([
            'user_id'           => $user->id,
            'notification_type' => MobilePushNotification::TYPE_TRANSACTION_RECEIVED,
            'title'             => 'New money',
            'body'              => 'Money received',
            'status'            => MobilePushNotification::STATUS_DELIVERED,
        ]);

        $this->setModelTimestamp($older, CarbonImmutable::parse('2026-04-01T09:00:00Z'));
        $this->setModelTimestamp($newer, CarbonImmutable::parse('2026-04-02T11:00:00Z'));

        $this->getJson('/api/push-notifications')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data.notifications.data');

        $this->postJson('/api/push-notifications/read/' . $newer->id)
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.notification.id', $newer->id)
            ->assertJsonPath('data.notification.user_read', true);

        $this->getJson('/api/push-notifications/sync?changed_since=2026-04-01T12:00:00Z')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $newer->id);
    }

    #[Test]
    public function test_notification_settings_alias_reads_and_updates_compact_mobile_payload(): void
    {
        [$user] = $this->makeUserWithAccount();

        MobileNotificationPreference::create([
            'user_id'           => $user->id,
            'notification_type' => MobileNotificationPreference::TYPE_TRANSACTION_RECEIVED,
            'push_enabled'      => true,
            'email_enabled'     => false,
        ]);
        MobileNotificationPreference::create([
            'user_id'           => $user->id,
            'notification_type' => MobileNotificationPreference::TYPE_TRANSACTION_SENT,
            'push_enabled'      => true,
            'email_enabled'     => false,
        ]);
        MobileNotificationPreference::create([
            'user_id'           => $user->id,
            'notification_type' => MobileNotificationPreference::TYPE_MARKETING,
            'push_enabled'      => false,
            'email_enabled'     => false,
        ]);

        $this->getJson('/api/notification/settings')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.transactions', true)
            ->assertJsonPath('data.promotions', false);

        $this->postJson('/api/notification/settings', [
            'transactions' => false,
            'promotions'   => true,
            'security'     => false,
            'social'       => true,
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.transactions', false)
            ->assertJsonPath('data.promotions', true)
            ->assertJsonPath('data.security', false)
            ->assertJsonPath('data.social', true);
    }
}
