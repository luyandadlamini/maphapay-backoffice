<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\Dashboard;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class DashboardControllerTest extends ControllerTestCase
{
    private const ROUTE = '/api/dashboard';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['maphapay_migration.enable_dashboard' => true]);

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            ['name' => 'Swazi Lilangeni', 'type' => 'fiat', 'precision' => 2, 'is_active' => true],
        );
    }

    // ── Flag guard ───────────────────────────────────────────────────────────

    #[Test]
    public function test_route_not_registered_when_flag_disabled(): void
    {
        config(['maphapay_migration.enable_dashboard' => false]);

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $this->getJson(self::ROUTE)->assertNotFound();
    }

    // ── Response structure ───────────────────────────────────────────────────

    #[Test]
    public function test_returns_success_envelope_with_user_and_balance(): void
    {
        $user = User::factory()->create(['kyc_status' => 'approved', 'kyc_expires_at' => null]);

        $account = Account::factory()->create([
            'user_uuid' => $user->uuid,
            'frozen'    => false,
        ]);

        AccountBalance::factory()
            ->forAccount($account)
            ->forAsset('SZL')
            ->withBalance(500_00) // 500.00 SZL in minor units
            ->create();

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->getJson(self::ROUTE);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('remark', 'dashboard')
            ->assertJsonPath('data.balance', '500.00')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.balance', '500.00')
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'email', 'mobile', 'balance'],
                    'balance',
                    'offers',
                ],
            ]);
    }

    #[Test]
    public function test_balance_is_zero_when_user_has_no_account(): void
    {
        $user = User::factory()->create(['kyc_status' => 'approved', 'kyc_expires_at' => null]);
        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->getJson(self::ROUTE);

        $response->assertOk()
            ->assertJsonPath('data.balance', '0.00')
            ->assertJsonPath('data.user.balance', '0.00');
    }

    /**
     * SZL is the canonical user-facing currency. An account funded only in USD
     * (e.g. by Stripe card provisioning during testing) has no spendable SZL,
     * so the dashboard must report 0.00 — never a USD→SZL display conversion.
     *
     * If we ever show a converted value here, send-money (which checks
     * AccountBalance::where('asset_code', 'SZL') directly) will reject the
     * transfer and the UI will be lying to the user.
     */
    #[Test]
    public function test_usd_only_account_reports_zero_szl_balance_with_no_fx_conversion(): void
    {
        Asset::firstOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'type' => 'fiat', 'precision' => 2, 'is_active' => true],
        );

        $user = User::factory()->create(['kyc_status' => 'approved', 'kyc_expires_at' => null]);
        $account = Account::factory()->create(['user_uuid' => $user->uuid, 'frozen' => false]);

        AccountBalance::factory()
            ->forAccount($account)
            ->forAsset('USD')
            ->withBalance(100_00)
            ->create();

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $this->getJson(self::ROUTE)
            ->assertOk()
            ->assertJsonPath('data.balance', '0.00')
            ->assertJsonPath('data.user.balance', '0.00')
            ->assertJsonPath('data.total_balance', '0.00');
    }

    #[Test]
    public function test_offers_is_empty_array(): void
    {
        $user = User::factory()->create(['kyc_status' => 'approved', 'kyc_expires_at' => null]);
        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $offers = $this->getJson(self::ROUTE)->assertOk()->json('data.offers');
        $this->assertIsArray($offers);
        $this->assertEmpty($offers);
    }

    // ── Caching ──────────────────────────────────────────────────────────────

    #[Test]
    public function test_response_is_cached_per_user(): void
    {
        $user = User::factory()->create(['kyc_status' => 'approved', 'kyc_expires_at' => null]);

        Account::factory()->create(['user_uuid' => $user->uuid]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $first = $this->getJson(self::ROUTE)->assertOk()->json('data.balance');
        $second = $this->getJson(self::ROUTE)->assertOk()->json('data.balance');

        $this->assertSame($first, $second);
        $this->assertTrue(Cache::has("maphapay.dashboard.balance.{$user->id}"));
    }

    #[Test]
    public function test_two_users_get_independent_balances(): void
    {
        $userA = User::factory()->create(['kyc_status' => 'approved', 'kyc_expires_at' => null]);
        $userB = User::factory()->create(['kyc_status' => 'approved', 'kyc_expires_at' => null]);

        $accountA = Account::factory()->create(['user_uuid' => $userA->uuid]);
        $accountB = Account::factory()->create(['user_uuid' => $userB->uuid]);

        AccountBalance::factory()->forAccount($accountA)->forAsset('SZL')->withBalance(1_000_00)->create();
        AccountBalance::factory()->forAccount($accountB)->forAsset('SZL')->withBalance(250_00)->create();

        Sanctum::actingAs($userA, ['read', 'write', 'delete']);
        $balanceA = $this->getJson(self::ROUTE)->assertOk()->json('data.balance');

        Sanctum::actingAs($userB, ['read', 'write', 'delete']);
        $balanceB = $this->getJson(self::ROUTE)->assertOk()->json('data.balance');

        $this->assertSame('1000.00', $balanceA);
        $this->assertSame('250.00', $balanceB);
    }
}
