<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\Pockets;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Asset\Models\Asset;
use App\Domain\Mobile\Models\Pocket;
use App\Http\Middleware\TracingMiddleware;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class PocketsFundsControllerTest extends ControllerTestCase
{
    private const ROUTE_STORE = '/api/pockets/store';
    private const ROUTE_ADD = '/api/pockets/add-funds';
    private const ROUTE_WITHDRAW = '/api/pockets/withdraw-funds';

    protected function setUp(): void
    {
        parent::setUp();

        // Avoid TracingMiddleware::endTrace() persisting spans via event sourcing (can recurse
        // in Eloquent touchOwners and exceed PHPUnit's default 10s per-test limit).
        $this->withoutMiddleware(TracingMiddleware::class);

        config(['banking.default_currency' => 'SZL']);

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            [
                'name' => 'Swazi Lilangeni',
                'type' => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );
    }

    #[Test]
    public function test_add_funds_debits_wallet_and_credits_pocket(): void
    {
        $user = User::factory()->create();

        $account = Account::factory()->create([
            'user_uuid' => $user->uuid,
            'frozen' => false,
        ]);

        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();
        $initialWalletMajor = 100.00;
        $amountMajor = 10.00;

        AccountBalance::factory()
            ->forAccount($account)
            ->forAsset('SZL')
            ->withBalance($asset->toSmallestUnit($initialWalletMajor))
            ->create();

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $pocketResponse = $this->postJson(self::ROUTE_STORE, [
            'name' => 'Holiday',
            'target_amount' => 10000,
            'target_date' => '2027-06-01',
            'category' => 'travel',
            'color' => '#4F8CFF',
        ]);

        $pocketResponse->assertCreated()
            ->assertJsonPath('status', 'success');

        $pocketUuid = (string) $pocketResponse->json('data.pocket.id');

        $addResponse = $this->postJson(self::ROUTE_ADD . '/' . $pocketUuid, [
            'amount' => (string) $amountMajor,
        ]);

        $addResponse->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.pocket.current_amount', '10.00');

        $accountBalance = AccountBalance::query()
            ->where('account_uuid', $account->uuid)
            ->where('asset_code', 'SZL')
            ->firstOrFail();

        self::assertSame(
            $asset->toSmallestUnit($initialWalletMajor) - $asset->toSmallestUnit($amountMajor),
            $accountBalance->balance,
        );

        $projection = TransactionProjection::query()
            ->where('account_uuid', $account->uuid)
            ->latest('id')
            ->firstOrFail();

        self::assertSame('withdrawal', $projection->type);
        self::assertSame('pocket_deposit', $projection->subtype);
        self::assertSame('pocket_transfer', $projection->metadata['source'] ?? null);
        self::assertSame('to_pocket', $projection->metadata['direction'] ?? null);
        self::assertSame($pocketUuid, $projection->metadata['pocket_uuid'] ?? null);
    }

    #[Test]
    public function test_add_funds_fails_with_insufficient_wallet_balance(): void
    {
        $user = User::factory()->create();

        $account = Account::factory()->create([
            'user_uuid' => $user->uuid,
            'frozen' => false,
        ]);

        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        $initialWalletMajor = 5.00;
        $amountMajor = 10.00;

        AccountBalance::factory()
            ->forAccount($account)
            ->forAsset('SZL')
            ->withBalance($asset->toSmallestUnit($initialWalletMajor))
            ->create();

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $pocketResponse = $this->postJson(self::ROUTE_STORE, [
            'name' => 'Holiday',
            'target_amount' => 10000,
            'target_date' => '2027-06-01',
            'category' => 'travel',
            'color' => '#4F8CFF',
        ]);

        $pocketUuid = (string) $pocketResponse->json('data.pocket.id');

        $addResponse = $this->postJson(self::ROUTE_ADD . '/' . $pocketUuid, [
            'amount' => (string) $amountMajor,
        ]);

        $addResponse->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message.0', 'Insufficient balance in wallet');

        $pocket = Pocket::query()->where('uuid', $pocketUuid)->firstOrFail();
        self::assertSame('0.00', number_format((float) $pocket->current_amount, 2, '.', ''));

        $accountBalance = AccountBalance::query()
            ->where('account_uuid', $account->uuid)
            ->where('asset_code', 'SZL')
            ->firstOrFail();

        self::assertSame($asset->toSmallestUnit($initialWalletMajor), $accountBalance->balance);
    }

    #[Test]
    public function test_withdraw_funds_from_pocket_returns_money_to_wallet(): void
    {
        $user = User::factory()->create();

        $account = Account::factory()->create([
            'user_uuid' => $user->uuid,
            'frozen' => false,
        ]);

        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        $initialWalletMajor = 100.00;
        $pocketFundMajor = 20.00;
        $withdrawMajor = 5.00;

        AccountBalance::factory()
            ->forAccount($account)
            ->forAsset('SZL')
            ->withBalance($asset->toSmallestUnit($initialWalletMajor))
            ->create();

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $pocketResponse = $this->postJson(self::ROUTE_STORE, [
            'name' => 'Holiday',
            'target_amount' => 10000,
            'target_date' => '2027-06-01',
            'category' => 'travel',
            'color' => '#4F8CFF',
        ]);

        $pocketUuid = (string) $pocketResponse->json('data.pocket.id');

        $fundResponse = $this->postJson(self::ROUTE_ADD . '/' . $pocketUuid, [
            'amount' => (string) $pocketFundMajor,
        ]);

        $fundResponse->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.pocket.current_amount', '20.00');

        $walletAfterFund = AccountBalance::query()
            ->where('account_uuid', $account->uuid)
            ->where('asset_code', 'SZL')
            ->firstOrFail();

        self::assertSame(
            $asset->toSmallestUnit($initialWalletMajor) - $asset->toSmallestUnit($pocketFundMajor),
            $walletAfterFund->balance,
        );

        $withdrawResponse = $this->postJson(self::ROUTE_WITHDRAW . '/' . $pocketUuid, [
            'amount' => (string) $withdrawMajor,
        ]);

        $withdrawResponse->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.pocket.current_amount', '15.00');

        $walletAfterWithdraw = AccountBalance::query()
            ->where('account_uuid', $account->uuid)
            ->where('asset_code', 'SZL')
            ->firstOrFail();

        self::assertSame(
            $asset->toSmallestUnit($initialWalletMajor) - $asset->toSmallestUnit($pocketFundMajor) + $asset->toSmallestUnit($withdrawMajor),
            $walletAfterWithdraw->balance,
        );

        $projection = TransactionProjection::query()
            ->where('account_uuid', $account->uuid)
            ->latest('id')
            ->firstOrFail();

        self::assertSame('deposit', $projection->type);
        self::assertSame('pocket_withdrawal', $projection->subtype);
        self::assertSame('pocket_transfer', $projection->metadata['source'] ?? null);
        self::assertSame('from_pocket', $projection->metadata['direction'] ?? null);
        self::assertSame($pocketUuid, $projection->metadata['pocket_uuid'] ?? null);
    }
}
