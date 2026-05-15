<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Wallet\Providers;

use App\Domain\Emali\Services\EmaliClient;
use App\Domain\Wallet\Contracts\WalletLinkResult;
use App\Domain\Wallet\Contracts\WalletMovementRequest;
use App\Domain\Wallet\Contracts\WalletMovementResult;
use App\Domain\Wallet\Contracts\WalletMovementStatus;
use App\Domain\Wallet\Providers\Emali\EmaliAdapter;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

final class EmaliAdapterTest extends TestCase
{
    private function mockClient(): EmaliClient&MockInterface
    {
        /** @var EmaliClient&MockInterface */
        return Mockery::mock(EmaliClient::class);
    }

    public function test_provider_id(): void
    {
        $this->assertSame('emali_eswatini_mobile', (new EmaliAdapter($this->mockClient()))->providerId());
    }

    public function test_link_returns_active_result(): void
    {
        $result = (new EmaliAdapter($this->mockClient()))->link('26876000001', 'SZL');

        $this->assertSame('emali_eswatini_mobile', $result->providerId);
        $this->assertSame('26876000001', $result->providerAccountRef);
        $this->assertSame(WalletLinkResult::LINK_STATUS_ACTIVE, $result->linkStatus);
        $this->assertStringStartsWith('eMali ', $result->displayName);
    }

    public function test_collect_calls_initiate_and_returns_pending(): void
    {
        $captured = null;
        $client = $this->mockClient();
        $client->shouldReceive('initiateCollection')
            ->once()
            ->withArgs(function (
                string $referenceId,
                string $amountMajor,
                string $currency,
                string $payer,
                string $externalId,
                string $note,
            ) use (&$captured): bool {
                $captured = $referenceId;

                return Str::isUuid($referenceId)
                    && $amountMajor === '100.00'
                    && $currency === 'SZL'
                    && $payer === '26876000001'
                    && $externalId === 'idem-1'
                    && $note === 'Top up';
            })
            ->andReturn([]);

        $result = (new EmaliAdapter($client))->collect($this->movementRequest());

        $this->assertSame($captured, $result->providerRequestId);
        $this->assertSame(WalletMovementResult::STATUS_PENDING, $result->status);
        $this->assertNull($result->failureReason);
    }

    public function test_collect_returns_failed_when_client_throws(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('initiateCollection')->once()->andThrow(new RuntimeException('eMali collections failed'));

        $result = (new EmaliAdapter($client))->collect($this->movementRequest());

        $this->assertSame(WalletMovementResult::STATUS_FAILED, $result->status);
        $this->assertSame('eMali collections failed', $result->failureReason);
    }

    public function test_disburse_calls_client_and_returns_pending(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('initiateDisbursement')->once()->andReturn([]);

        $result = (new EmaliAdapter($client))->disburse($this->movementRequest());

        $this->assertSame(WalletMovementResult::STATUS_PENDING, $result->status);
        $this->assertTrue(Str::isUuid($result->providerRequestId));
    }

    public function test_status_maps_successful(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('getCollectionStatus')->once()->with('ref-1')->andReturn(['status' => 'SUCCESSFUL']);

        $result = (new EmaliAdapter($client))->status('ref-1');

        $this->assertSame(WalletMovementStatus::STATUS_SUCCESSFUL, $result->status);
        $this->assertNotNull($result->settledAt);
    }

    public function test_status_maps_failed_with_reason(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('getCollectionStatus')->once()->with('ref-1')->andReturn(['status' => 'REJECTED', 'reason' => 'INSUFFICIENT_FUNDS']);

        $result = (new EmaliAdapter($client))->status('ref-1');

        $this->assertSame(WalletMovementStatus::STATUS_FAILED, $result->status);
        $this->assertSame('INSUFFICIENT_FUNDS', $result->failureReason);
    }

    public function test_status_maps_pending(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('getCollectionStatus')->once()->with('ref-1')->andReturn(['status' => 'PENDING']);

        $result = (new EmaliAdapter($client))->status('ref-1');

        $this->assertSame(WalletMovementStatus::STATUS_PENDING, $result->status);
        $this->assertNull($result->settledAt);
    }

    public function test_verify_webhook_signature_accepts_valid(): void
    {
        config([
            'emali.callback_token'        => 'token',
            'emali.hmac_key'              => 'secret',
            'emali.verify_callback_token' => true,
            'emali.verify_hmac_signature' => true,
        ]);
        $body = '{"status":"SUCCESSFUL"}';

        $this->assertTrue((new EmaliAdapter($this->mockClient()))->verifyWebhookSignature($body, [
            'X-Callback-Token' => 'token',
            'X-Signature'      => hash_hmac('sha256', $body, 'secret'),
        ]));
    }

    public function test_verify_webhook_signature_rejects_tampered_body(): void
    {
        config([
            'emali.callback_token'        => 'token',
            'emali.hmac_key'              => 'secret',
            'emali.verify_callback_token' => true,
            'emali.verify_hmac_signature' => true,
        ]);
        $sig = hash_hmac('sha256', '{"status":"SUCCESSFUL"}', 'secret');

        $this->assertFalse((new EmaliAdapter($this->mockClient()))->verifyWebhookSignature('{"status":"FAILED"}', [
            'X-Callback-Token' => 'token',
            'X-Signature'      => $sig,
        ]));
    }

    public function test_verify_webhook_signature_rejects_wrong_token(): void
    {
        config([
            'emali.callback_token'        => 'token',
            'emali.hmac_key'              => 'secret',
            'emali.verify_callback_token' => true,
            'emali.verify_hmac_signature' => true,
        ]);
        $body = '{"status":"SUCCESSFUL"}';

        $this->assertFalse((new EmaliAdapter($this->mockClient()))->verifyWebhookSignature($body, [
            'X-Callback-Token' => 'wrong',
            'X-Signature'      => hash_hmac('sha256', $body, 'secret'),
        ]));
    }

    private function movementRequest(): WalletMovementRequest
    {
        return new WalletMovementRequest(
            providerId: 'emali_eswatini_mobile',
            providerAccountRef: '26876000001',
            linkToken: 'token',
            amountMinor: 10000,
            currency: 'SZL',
            idempotencyKey: 'idem-1',
            callbackUrl: 'https://example.test/callback',
            memo: 'Top up',
        );
    }
}
