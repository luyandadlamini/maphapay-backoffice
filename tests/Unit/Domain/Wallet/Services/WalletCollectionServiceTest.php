<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Wallet\Services;

use App\Domain\Wallet\Contracts\WalletMovementRequest;
use App\Domain\Wallet\Contracts\WalletMovementResult;
use App\Domain\Wallet\Contracts\WalletProviderAdapter;
use App\Domain\Wallet\Models\WalletProviderTransaction;
use App\Domain\Wallet\Providers\Emali\EmaliAdapter;
use App\Domain\Wallet\Providers\WalletProviderRegistry;
use App\Domain\Wallet\Services\WalletCollectionService;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

final class WalletCollectionServiceTest extends TestCase
{
    public function test_collect_creates_pending_row_and_calls_adapter(): void
    {
        $user = User::factory()->create();
        $referenceId = (string) Str::uuid();

        $adapter = $this->mockAdapter('emali_eswatini_mobile');
        $adapter->shouldReceive('collect')
            ->once()
            ->withArgs(function (WalletMovementRequest $req) use ($user): bool {
                return $req->providerAccountRef === '26876000001'
                    && $req->amountMinor === 10_000
                    && $req->idempotencyKey === 'idem-1';
            })
            ->andReturn(new WalletMovementResult(
                providerRequestId: $referenceId,
                status: WalletMovementResult::STATUS_PENDING,
                failureReason: null,
            ));

        $service = new WalletCollectionService($this->registryReturning($adapter));

        $result = $service->collect(
            providerId: 'emali_eswatini_mobile',
            userUuid: $user->uuid,
            providerAccountRef: '26876000001',
            linkToken: 'token',
            amountMinor: 10_000,
            currency: 'SZL',
            idempotencyKey: 'idem-1',
            callbackUrl: 'https://example.test/cb',
            memo: 'Top up',
        );

        $this->assertSame($referenceId, $result->providerRequestId);
        $this->assertSame(WalletProviderTransaction::STATUS_PENDING, $result->status);
        $this->assertFalse($result->isReplay);

        $row = WalletProviderTransaction::query()->where('provider_request_id', $referenceId)->firstOrFail();
        $this->assertSame($user->uuid, $row->user_uuid);
        $this->assertSame(10_000, $row->amount_minor);
        $this->assertSame('idem-1', $row->payload['idempotency_key']);
    }

    public function test_replay_with_same_idempotency_key_returns_existing_row(): void
    {
        $user = User::factory()->create();

        WalletProviderTransaction::query()->create([
            'provider_id'         => 'emali_eswatini_mobile',
            'provider_request_id' => 'existing-ref',
            'type'                => WalletProviderTransaction::TYPE_COLLECT,
            'status'              => WalletProviderTransaction::STATUS_SUCCESSFUL,
            'currency'            => 'SZL',
            'amount_minor'        => 5_000,
            'user_uuid'           => $user->uuid,
            'payload'             => ['idempotency_key' => 'idem-replay'],
        ]);

        $adapter = $this->mockAdapter('emali_eswatini_mobile');
        $adapter->shouldNotReceive('collect');

        $service = new WalletCollectionService($this->registryReturning($adapter));

        $result = $service->collect(
            providerId: 'emali_eswatini_mobile',
            userUuid: $user->uuid,
            providerAccountRef: '26876000001',
            linkToken: 'token',
            amountMinor: 5_000,
            currency: 'SZL',
            idempotencyKey: 'idem-replay',
            callbackUrl: 'https://example.test/cb',
            memo: 'Top up',
        );

        $this->assertTrue($result->isReplay);
        $this->assertSame('existing-ref', $result->providerRequestId);
        $this->assertSame(WalletProviderTransaction::STATUS_SUCCESSFUL, $result->status);
    }

    public function test_adapter_failed_result_marks_row_failed_and_settled(): void
    {
        $user = User::factory()->create();

        $adapter = $this->mockAdapter('emali_eswatini_mobile');
        $adapter->shouldReceive('collect')->once()->andReturn(new WalletMovementResult(
            providerRequestId: 'fail-ref',
            status: WalletMovementResult::STATUS_FAILED,
            failureReason: 'PAYER_NOT_FOUND',
        ));

        $service = new WalletCollectionService($this->registryReturning($adapter));

        $result = $service->collect(
            'emali_eswatini_mobile',
            $user->uuid,
            '26876000001',
            'token',
            1_000,
            'SZL',
            'idem-fail',
            'https://example.test/cb',
            'Top up',
        );

        $this->assertSame(WalletProviderTransaction::STATUS_FAILED, $result->status);
        $this->assertSame('PAYER_NOT_FOUND', $result->failureReason);

        $row = WalletProviderTransaction::query()->where('provider_request_id', 'fail-ref')->firstOrFail();
        $this->assertNotNull($row->settled_at);
    }

    public function test_rejects_zero_amount(): void
    {
        $service = new WalletCollectionService($this->app->make(WalletProviderRegistry::class));

        $this->expectException(InvalidArgumentException::class);
        $service->collect('emali_eswatini_mobile', 'u', 'r', 't', 0, 'SZL', 'k', 'cb', 'm');
    }

    public function test_rejects_empty_idempotency_key(): void
    {
        $service = new WalletCollectionService($this->app->make(WalletProviderRegistry::class));

        $this->expectException(InvalidArgumentException::class);
        $service->collect('emali_eswatini_mobile', 'u', 'r', 't', 100, 'SZL', '', 'cb', 'm');
    }

    private function mockAdapter(string $providerId): WalletProviderAdapter&Mockery\MockInterface
    {
        /** @var WalletProviderAdapter&Mockery\MockInterface $mock */
        $mock = Mockery::mock(WalletProviderAdapter::class);
        $mock->shouldReceive('providerId')->andReturn($providerId);

        // Bind into the container so the real registry's container->make resolves to it.
        $this->app->instance(EmaliAdapter::class, $mock);

        return $mock;
    }

    private function registryReturning(WalletProviderAdapter $adapter): WalletProviderRegistry
    {
        return $this->app->make(WalletProviderRegistry::class);
    }
}
