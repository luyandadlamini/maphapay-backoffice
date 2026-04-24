<?php

declare(strict_types=1);

namespace Tests\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Account\Services\AccountService;
use Illuminate\Support\Str;
use Tests\DomainTestCase;

class AccountServiceDirectBalanceTest extends DomainTestCase
{
    private AccountService $accountService;
    private string $accountUuid;
    private string $assetCode = 'SZL';

    protected function setUp(): void
    {
        parent::setUp();

        $this->accountService = app(AccountService::class);
        $this->accountUuid = (string) Str::uuid();

        Account::factory()->create([
            'uuid' => $this->accountUuid,
        ]);
    }

    public function test_deposit_direct_updates_account_balance(): void
    {
        $amount = 5000;

        $reference = $this->accountService->depositDirect($this->accountUuid, $amount);

        $this->assertNotEmpty($reference);

        $balance = AccountBalance::where('account_uuid', $this->accountUuid)
            ->where('asset_code', $this->assetCode)
            ->first();

        $this->assertNotNull($balance, 'AccountBalance record should exist after deposit');
        $this->assertEquals($amount, $balance->balance, 'Balance should equal deposited amount');
    }

    public function test_withdraw_direct_updates_account_balance(): void
    {
        $depositAmount = 10000;
        $withdrawAmount = 3000;

        $this->accountService->depositDirect($this->accountUuid, $depositAmount);

        $reference = $this->accountService->withdrawDirect($this->accountUuid, $withdrawAmount);

        $this->assertNotEmpty($reference);

        $balance = AccountBalance::where('account_uuid', $this->accountUuid)
            ->where('asset_code', $this->assetCode)
            ->first();

        $this->assertNotNull($balance, 'AccountBalance record should exist after withdrawal');
        $this->assertEquals($depositAmount - $withdrawAmount, $balance->balance, 'Balance should reflect withdrawal');
    }

    public function test_multiple_deposits_accumulate_balance(): void
    {
        $this->accountService->depositDirect($this->accountUuid, 1000);
        $this->accountService->depositDirect($this->accountUuid, 2500);
        $this->accountService->depositDirect($this->accountUuid, 1500);

        $balance = AccountBalance::where('account_uuid', $this->accountUuid)
            ->where('asset_code', $this->assetCode)
            ->first();

        $this->assertNotNull($balance);
        $this->assertEquals(5000, $balance->balance);
    }

    public function test_withdraw_insufficient_balance_throws(): void
    {
        $this->expectException(\App\Domain\Account\Exceptions\NotEnoughFunds::class);

        $this->accountService->withdrawDirect($this->accountUuid, 1);
    }
}