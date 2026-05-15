<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Wallet\Providers;

use App\Domain\Account\Models\Account;
use App\Domain\Wallet\Models\WalletProviderTransaction;
use App\Domain\Wallet\Providers\NedbankSendMoney\NedbankSendMoneySettler;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NedbankSendMoneySettlerTest extends TestCase
{
    public function test_provider_id(): void
    {
        $this->assertSame(
            'nedbank_send_money',
            (new NedbankSendMoneySettler($this->createMock(WalletOperationsService::class)))->providerId(),
        );
    }

    public function test_completed_inbound_credits_user(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'uuid'      => (string) Str::uuid(),
            'user_uuid' => $user->uuid,
            'frozen'    => false,
        ]);

        $row = WalletProviderTransaction::query()->create([
            'provider_id'         => 'nedbank_send_money',
            'provider_request_id' => 'ned-ok',
            'type'                => WalletProviderTransaction::TYPE_COLLECT,
            'status'              => WalletProviderTransaction::STATUS_PENDING,
            'currency'            => 'SZL',
            'amount_minor'        => 40_000,
            'user_uuid'           => $user->uuid,
        ]);

        $walletOps = $this->createMock(WalletOperationsService::class);
        $walletOps->expects($this->once())
            ->method('deposit')
            ->with($account->uuid, 'SZL', '40000', 'nedbank-send-money-inbound:ned-ok', $this->anything())
            ->willReturn('dep-1');

        (new NedbankSendMoneySettler($walletOps))->settle('ned-ok', 'COMPLETED', []);

        $this->assertSame(WalletProviderTransaction::STATUS_SUCCESSFUL, $row->fresh()->status);
    }

    public function test_expired_marks_row_failed_and_does_not_credit(): void
    {
        $user = User::factory()->create();
        Account::factory()->create([
            'uuid'      => (string) Str::uuid(),
            'user_uuid' => $user->uuid,
            'frozen'    => false,
        ]);

        WalletProviderTransaction::query()->create([
            'provider_id'         => 'nedbank_send_money',
            'provider_request_id' => 'ned-no',
            'type'                => WalletProviderTransaction::TYPE_COLLECT,
            'status'              => WalletProviderTransaction::STATUS_PENDING,
            'currency'            => 'SZL',
            'amount_minor'        => 1_000,
            'user_uuid'           => $user->uuid,
        ]);

        $walletOps = $this->createMock(WalletOperationsService::class);
        $walletOps->expects($this->never())->method('deposit');

        (new NedbankSendMoneySettler($walletOps))->settle('ned-no', 'EXPIRED', ['reason' => 'NOT_REDEEMED']);

        $this->assertSame(
            WalletProviderTransaction::STATUS_FAILED,
            WalletProviderTransaction::query()->where('provider_request_id', 'ned-no')->first()->status,
        );
    }

    public function test_unknown_request_noop(): void
    {
        $walletOps = $this->createMock(WalletOperationsService::class);
        $walletOps->expects($this->never())->method('deposit');

        (new NedbankSendMoneySettler($walletOps))->settle('missing', 'COMPLETED', []);
    }
}
