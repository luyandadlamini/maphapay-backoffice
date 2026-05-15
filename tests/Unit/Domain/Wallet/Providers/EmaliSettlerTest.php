<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Wallet\Providers;

use App\Domain\Account\Models\Account;
use App\Domain\Wallet\Models\WalletProviderTransaction;
use App\Domain\Wallet\Providers\Emali\EmaliSettler;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmaliSettlerTest extends TestCase
{
    public function test_provider_id(): void
    {
        $walletOps = $this->createMock(WalletOperationsService::class);

        $this->assertSame('emali_eswatini_mobile', (new EmaliSettler($walletOps))->providerId());
    }

    public function test_successful_collection_credits_user_account_and_updates_row(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'uuid'      => (string) Str::uuid(),
            'user_uuid' => $user->uuid,
            'frozen'    => false,
        ]);

        $row = WalletProviderTransaction::query()->create([
            'provider_id'         => 'emali_eswatini_mobile',
            'provider_request_id' => 'ref-success',
            'type'                => WalletProviderTransaction::TYPE_COLLECT,
            'status'              => WalletProviderTransaction::STATUS_PENDING,
            'currency'            => 'SZL',
            'amount_minor'        => 10_000,
            'user_uuid'           => $user->uuid,
        ]);

        $walletOps = $this->createMock(WalletOperationsService::class);
        $walletOps->expects($this->once())
            ->method('deposit')
            ->with(
                $account->uuid,
                'SZL',
                '10000',
                'emali-collect:ref-success',
                ['wallet_provider_transaction_id' => $row->id],
            )
            ->willReturn('dep-1');

        (new EmaliSettler($walletOps))->settle('ref-success', 'SUCCESSFUL', ['financial_transaction_id' => 'fin-1']);

        $fresh = $row->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(WalletProviderTransaction::STATUS_SUCCESSFUL, $fresh->status);
        $this->assertNotNull($fresh->settled_at);
    }

    public function test_failed_collection_updates_row_and_does_not_credit(): void
    {
        $user = User::factory()->create();
        Account::factory()->create([
            'uuid'      => (string) Str::uuid(),
            'user_uuid' => $user->uuid,
            'frozen'    => false,
        ]);

        WalletProviderTransaction::query()->create([
            'provider_id'         => 'emali_eswatini_mobile',
            'provider_request_id' => 'ref-fail',
            'type'                => WalletProviderTransaction::TYPE_COLLECT,
            'status'              => WalletProviderTransaction::STATUS_PENDING,
            'currency'            => 'SZL',
            'amount_minor'        => 5_000,
            'user_uuid'           => $user->uuid,
        ]);

        $walletOps = $this->createMock(WalletOperationsService::class);
        $walletOps->expects($this->never())->method('deposit');

        (new EmaliSettler($walletOps))->settle('ref-fail', 'REJECTED', ['reason' => 'INSUFFICIENT_FUNDS']);

        $fresh = WalletProviderTransaction::query()
            ->where('provider_request_id', 'ref-fail')
            ->first();
        $this->assertNotNull($fresh);
        $this->assertSame(WalletProviderTransaction::STATUS_FAILED, $fresh->status);
        $this->assertNotNull($fresh->settled_at);
    }

    public function test_unknown_provider_request_is_a_no_op(): void
    {
        $walletOps = $this->createMock(WalletOperationsService::class);
        $walletOps->expects($this->never())->method('deposit');

        (new EmaliSettler($walletOps))->settle('does-not-exist', 'SUCCESSFUL', []);

        $this->assertSame(0, WalletProviderTransaction::query()->count());
    }

    public function test_already_terminal_row_is_not_re_credited(): void
    {
        $user = User::factory()->create();
        Account::factory()->create([
            'uuid'      => (string) Str::uuid(),
            'user_uuid' => $user->uuid,
            'frozen'    => false,
        ]);

        WalletProviderTransaction::query()->create([
            'provider_id'         => 'emali_eswatini_mobile',
            'provider_request_id' => 'ref-idem',
            'type'                => WalletProviderTransaction::TYPE_COLLECT,
            'status'              => WalletProviderTransaction::STATUS_SUCCESSFUL,
            'currency'            => 'SZL',
            'amount_minor'        => 1_000,
            'user_uuid'           => $user->uuid,
            'settled_at'          => now(),
        ]);

        $walletOps = $this->createMock(WalletOperationsService::class);
        $walletOps->expects($this->never())->method('deposit');

        (new EmaliSettler($walletOps))->settle('ref-idem', 'SUCCESSFUL', []);
    }
}
