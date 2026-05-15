<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Wallet\Providers;

use App\Domain\Account\Models\Account;
use App\Domain\Wallet\Models\WalletProviderTransaction;
use App\Domain\Wallet\Providers\StandardUnayo\StandardUnayoSettler;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

final class StandardUnayoSettlerTest extends TestCase
{
    public function test_provider_id(): void
    {
        $this->assertSame(
            'standard_unayo',
            (new StandardUnayoSettler($this->createMock(WalletOperationsService::class)))->providerId(),
        );
    }

    public function test_settled_cashin_credits_user(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'uuid'      => (string) Str::uuid(),
            'user_uuid' => $user->uuid,
            'frozen'    => false,
        ]);

        $row = WalletProviderTransaction::query()->create([
            'provider_id'         => 'standard_unayo',
            'provider_request_id' => 'unayo-ok',
            'type'                => WalletProviderTransaction::TYPE_COLLECT,
            'status'              => WalletProviderTransaction::STATUS_PENDING,
            'currency'            => 'SZL',
            'amount_minor'        => 30_000,
            'user_uuid'           => $user->uuid,
        ]);

        $walletOps = $this->createMock(WalletOperationsService::class);
        $walletOps->expects($this->once())
            ->method('deposit')
            ->with($account->uuid, 'SZL', '30000', 'standard-unayo-cashin:unayo-ok', $this->anything())
            ->willReturn('dep-1');

        (new StandardUnayoSettler($walletOps))->settle('unayo-ok', 'SETTLED', []);

        $this->assertSame(WalletProviderTransaction::STATUS_SUCCESSFUL, $row->fresh()->status);
    }

    public function test_reversed_marks_row_failed(): void
    {
        $user = User::factory()->create();
        Account::factory()->create([
            'uuid'      => (string) Str::uuid(),
            'user_uuid' => $user->uuid,
            'frozen'    => false,
        ]);

        WalletProviderTransaction::query()->create([
            'provider_id'         => 'standard_unayo',
            'provider_request_id' => 'unayo-no',
            'type'                => WalletProviderTransaction::TYPE_COLLECT,
            'status'              => WalletProviderTransaction::STATUS_PENDING,
            'currency'            => 'SZL',
            'amount_minor'        => 1_000,
            'user_uuid'           => $user->uuid,
        ]);

        $walletOps = $this->createMock(WalletOperationsService::class);
        $walletOps->expects($this->never())->method('deposit');

        (new StandardUnayoSettler($walletOps))->settle('unayo-no', 'REVERSED', ['reason' => 'TIMEOUT']);

        $this->assertSame(
            WalletProviderTransaction::STATUS_FAILED,
            WalletProviderTransaction::query()->where('provider_request_id', 'unayo-no')->first()->status,
        );
    }

    public function test_unknown_request_noop(): void
    {
        $walletOps = $this->createMock(WalletOperationsService::class);
        $walletOps->expects($this->never())->method('deposit');

        (new StandardUnayoSettler($walletOps))->settle('missing', 'SETTLED', []);
    }
}
