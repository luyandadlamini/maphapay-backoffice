<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\Transactions;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Asset\Models\Asset;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class TransactionHistoryTypeAliasFilterTest extends ControllerTestCase
{
    private const ROUTE = '/api/transactions';

    protected function setUp(): void
    {
        parent::setUp();

        config(['maphapay_migration.enable_transaction_history' => true]);

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

        return [$user, $account];
    }

    #[Test]
    public function test_income_alias_includes_deposits_and_transfer_in_transactions(): void
    {
        [$user, $account] = $this->makeUserWithAccount();

        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'type'         => 'deposit',
            'status'       => 'completed',
        ]);
        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'type'         => 'transfer_in',
            'status'       => 'completed',
        ]);
        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'type'         => 'transfer_out',
            'status'       => 'completed',
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $rows = $this->getJson(self::ROUTE . '?type=income')->assertOk()->json('data.transactions.data');

        $this->assertCount(2, $rows);
        $this->assertSame(['deposit', 'transfer_in'], collect($rows)->pluck('type')->sort()->values()->all());
    }

    #[Test]
    public function test_expense_alias_includes_withdrawals_and_transfer_out_transactions(): void
    {
        [$user, $account] = $this->makeUserWithAccount();

        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'type'         => 'withdrawal',
            'status'       => 'completed',
        ]);
        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'type'         => 'transfer_out',
            'status'       => 'completed',
        ]);
        TransactionProjection::factory()->create([
            'account_uuid' => $account->uuid,
            'asset_code'   => 'SZL',
            'type'         => 'transfer_in',
            'status'       => 'completed',
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $rows = $this->getJson(self::ROUTE . '?type=expense')->assertOk()->json('data.transactions.data');

        $this->assertCount(2, $rows);
        $this->assertSame(['transfer_out', 'withdrawal'], collect($rows)->pluck('type')->sort()->values()->all());
    }
}
