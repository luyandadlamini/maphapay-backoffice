<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Wallet\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Wallet\Contracts\WalletMovementResult;
use App\Domain\Wallet\Contracts\WalletProviderAdapter;
use App\Domain\Wallet\Models\WalletProviderTransaction;
use App\Domain\Wallet\Providers\Emali\EmaliAdapter;
use App\Domain\Wallet\Providers\WalletProviderRegistry;
use App\Domain\Wallet\Services\WalletDisbursementService;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class WalletDisbursementServiceTest extends TestCase
{
    public function test_disburse_debits_user_then_calls_adapter_and_creates_row(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'uuid'      => (string) Str::uuid(),
            'user_uuid' => $user->uuid,
            'frozen'    => false,
        ]);
        $referenceId = (string) Str::uuid();

        $adapter = $this->mockAdapter('emali_eswatini_mobile');
        $adapter->shouldReceive('disburse')->once()->andReturn(new WalletMovementResult(
            providerRequestId: $referenceId,
            status: WalletMovementResult::STATUS_PENDING,
            failureReason: null,
        ));

        $walletOps = $this->createMock(WalletOperationsService::class);
        $walletOps->expects($this->once())
            ->method('withdraw')
            ->with(
                $account->uuid,
                'SZL',
                '5000',
                'wallet-disburse-debit:emali_eswatini_mobile:idem-1',
                ['provider_id' => 'emali_eswatini_mobile', 'idempotency_key' => 'idem-1'],
            )
            ->willReturn('w-1');

        $service = new WalletDisbursementService($this->registryReturning($adapter), $walletOps);

        $result = $service->disburse(
            'emali_eswatini_mobile',
            $user->uuid,
            '26876000001',
            'token',
            5_000,
            'SZL',
            'idem-1',
            'https://example.test/cb',
            'Cash out',
        );

        $this->assertSame($referenceId, $result->providerRequestId);
        $this->assertSame(WalletProviderTransaction::STATUS_PENDING, $result->status);

        $row = WalletProviderTransaction::query()->where('provider_request_id', $referenceId)->firstOrFail();
        $this->assertSame(WalletProviderTransaction::TYPE_DISBURSE, $row->type);
        $this->assertArrayHasKey('wallet_debited_at', (array) $row->payload);
    }

    public function test_replay_returns_existing_without_double_debit(): void
    {
        $user = User::factory()->create();

        WalletProviderTransaction::query()->create([
            'provider_id'         => 'emali_eswatini_mobile',
            'provider_request_id' => 'existing-d',
            'type'                => WalletProviderTransaction::TYPE_DISBURSE,
            'status'              => WalletProviderTransaction::STATUS_PENDING,
            'currency'            => 'SZL',
            'amount_minor'        => 2_000,
            'user_uuid'           => $user->uuid,
            'payload'             => ['idempotency_key' => 'idem-replay-d'],
        ]);

        $adapter = $this->mockAdapter('emali_eswatini_mobile');
        $adapter->shouldNotReceive('disburse');

        $walletOps = $this->createMock(WalletOperationsService::class);
        $walletOps->expects($this->never())->method('withdraw');

        $service = new WalletDisbursementService($this->registryReturning($adapter), $walletOps);

        $result = $service->disburse(
            'emali_eswatini_mobile',
            $user->uuid,
            '26876000001',
            'token',
            2_000,
            'SZL',
            'idem-replay-d',
            'https://example.test/cb',
            'Cash out',
        );

        $this->assertTrue($result->isReplay);
        $this->assertSame('existing-d', $result->providerRequestId);
    }

    public function test_no_account_throws(): void
    {
        $user = User::factory()->create();

        $adapter = $this->mockAdapter('emali_eswatini_mobile');
        $walletOps = $this->createMock(WalletOperationsService::class);

        $service = new WalletDisbursementService($this->registryReturning($adapter), $walletOps);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No account found for user.');

        $service->disburse(
            'emali_eswatini_mobile',
            $user->uuid,
            '26876000001',
            'token',
            100,
            'SZL',
            'idem-noaccount',
            'cb',
            'm',
        );
    }

    public function test_rejects_zero_amount(): void
    {
        $service = new WalletDisbursementService(
            $this->app->make(WalletProviderRegistry::class),
            $this->createMock(WalletOperationsService::class),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->disburse('emali_eswatini_mobile', 'u', 'r', 't', 0, 'SZL', 'k', 'cb', 'm');
    }

    private function mockAdapter(string $providerId): WalletProviderAdapter&Mockery\MockInterface
    {
        /** @var WalletProviderAdapter&Mockery\MockInterface $mock */
        $mock = Mockery::mock(WalletProviderAdapter::class);
        $mock->shouldReceive('providerId')->andReturn($providerId);

        $this->app->instance(EmaliAdapter::class, $mock);

        return $mock;
    }

    private function registryReturning(WalletProviderAdapter $adapter): WalletProviderRegistry
    {
        return $this->app->make(WalletProviderRegistry::class);
    }
}
