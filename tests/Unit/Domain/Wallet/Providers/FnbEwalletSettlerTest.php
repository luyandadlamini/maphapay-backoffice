<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Wallet\Providers;

use App\Domain\Account\Models\Account;
use App\Domain\Wallet\Models\WalletProviderTransaction;
use App\Domain\Wallet\Providers\FnbEwallet\FnbEwalletSettler;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FnbEwalletSettlerTest extends TestCase
{
    public function test_provider_id(): void
    {
        $this->assertSame(
            'fnb_ewallet',
            (new FnbEwalletSettler($this->createMock(WalletOperationsService::class)))->providerId(),
        );
    }

    public function test_posted_credit_credits_user_and_marks_row_successful(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'uuid'      => (string) Str::uuid(),
            'user_uuid' => $user->uuid,
            'frozen'    => false,
        ]);

        $row = WalletProviderTransaction::query()->create([
            'provider_id'         => 'fnb_ewallet',
            'provider_request_id' => 'fnb-ok',
            'type'                => WalletProviderTransaction::TYPE_COLLECT,
            'status'              => WalletProviderTransaction::STATUS_PENDING,
            'currency'            => 'SZL',
            'amount_minor'        => 20_000,
            'user_uuid'           => $user->uuid,
        ]);

        $walletOps = $this->createMock(WalletOperationsService::class);
        $walletOps->expects($this->once())
            ->method('deposit')
            ->with($account->uuid, 'SZL', '20000', 'fnb-ewallet-credit:fnb-ok', $this->anything())
            ->willReturn('dep-1');

        (new FnbEwalletSettler($walletOps))->settle('fnb-ok', 'POSTED', []);

        $fresh = $row->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(WalletProviderTransaction::STATUS_SUCCESSFUL, $fresh->status);
        $this->assertNotNull($fresh->settled_at);
    }

    public function test_declined_credit_marks_row_failed_and_does_not_deposit(): void
    {
        $user = User::factory()->create();
        Account::factory()->create([
            'uuid'      => (string) Str::uuid(),
            'user_uuid' => $user->uuid,
            'frozen'    => false,
        ]);

        WalletProviderTransaction::query()->create([
            'provider_id'         => 'fnb_ewallet',
            'provider_request_id' => 'fnb-no',
            'type'                => WalletProviderTransaction::TYPE_COLLECT,
            'status'              => WalletProviderTransaction::STATUS_PENDING,
            'currency'            => 'SZL',
            'amount_minor'        => 1_000,
            'user_uuid'           => $user->uuid,
        ]);

        $walletOps = $this->createMock(WalletOperationsService::class);
        $walletOps->expects($this->never())->method('deposit');

        (new FnbEwalletSettler($walletOps))->settle('fnb-no', 'DECLINED', ['reason' => 'AML_HOLD']);

        $fresh = WalletProviderTransaction::query()->where('provider_request_id', 'fnb-no')->first();
        $this->assertNotNull($fresh);
        $this->assertSame(WalletProviderTransaction::STATUS_FAILED, $fresh->status);
    }

    public function test_unknown_request_is_noop(): void
    {
        $walletOps = $this->createMock(WalletOperationsService::class);
        $walletOps->expects($this->never())->method('deposit');

        (new FnbEwalletSettler($walletOps))->settle('missing', 'POSTED', []);
    }
}
