<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Wallet\Providers;

use App\Domain\MtnMomo\Services\MtnMomoClient;
use App\Domain\Wallet\Contracts\WalletLinkResult;
use App\Domain\Wallet\Contracts\WalletMovementRequest;
use App\Domain\Wallet\Contracts\WalletMovementResult;
use App\Domain\Wallet\Contracts\WalletMovementStatus;
use App\Domain\Wallet\Providers\MtnMomo\MtnMomoAdapter;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

final class MtnMomoAdapterTest extends TestCase
{
    private function mockClient(): MtnMomoClient&MockInterface
    {
        /** @var MtnMomoClient&MockInterface */
        return Mockery::mock(MtnMomoClient::class);
    }

    public function test_provider_id_returns_mtn_momo(): void
    {
        $adapter = new MtnMomoAdapter($this->mockClient());

        $this->assertSame('mtn_momo', $adapter->providerId());
    }

    public function test_link_returns_active_wallet_link_result(): void
    {
        $adapter = new MtnMomoAdapter($this->mockClient());

        $result = $adapter->link('26876000001', 'SZL');

        $this->assertSame('mtn_momo', $result->providerId);
        $this->assertSame('26876000001', $result->providerAccountRef);
        $this->assertSame(WalletLinkResult::LINK_STATUS_ACTIVE, $result->linkStatus);
        $this->assertNotSame('', $result->linkToken);
    }

    public function test_collect_calls_request_to_pay_and_returns_pending_with_generated_reference(): void
    {
        $capturedReferenceId = null;
        $client = $this->mockClient();
        $client->shouldReceive('requestToPay')
            ->once()
            ->withArgs(function (
                string $referenceId,
                string $amount,
                string $currency,
                string $payerMsisdn,
                string $externalId,
                string $payerMessage,
                string $payeeNote,
            ) use (&$capturedReferenceId): bool {
                $capturedReferenceId = $referenceId;

                return Str::isUuid($referenceId)
                    && $amount === '100.00'
                    && $currency === 'SZL'
                    && $payerMsisdn === '26876000001'
                    && $externalId === 'idem-1'
                    && $payerMessage === 'Top up'
                    && $payeeNote === 'Top up';
            })
            ->andReturnNull();

        $adapter = new MtnMomoAdapter($client);

        $result = $adapter->collect($this->movementRequest());

        $this->assertSame($capturedReferenceId, $result->providerRequestId);
        $this->assertSame(WalletMovementResult::STATUS_PENDING, $result->status);
        $this->assertNull($result->failureReason);
    }

    public function test_collect_returns_failed_when_client_throws(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('requestToPay')
            ->once()
            ->andThrow(new RuntimeException('MTN requesttopay failed'));

        $adapter = new MtnMomoAdapter($client);

        $result = $adapter->collect($this->movementRequest());

        $this->assertTrue(Str::isUuid($result->providerRequestId));
        $this->assertSame(WalletMovementResult::STATUS_FAILED, $result->status);
        $this->assertSame('MTN requesttopay failed', $result->failureReason);
    }

    public function test_disburse_calls_client_and_returns_pending(): void
    {
        $capturedReferenceId = null;
        $client = $this->mockClient();
        $client->shouldReceive('disburse')
            ->once()
            ->withArgs(function (
                string $referenceId,
                string $amount,
                string $currency,
                string $payeeMsisdn,
                string $externalId,
                string $payerMessage,
                string $payeeNote,
            ) use (&$capturedReferenceId): bool {
                $capturedReferenceId = $referenceId;

                return Str::isUuid($referenceId)
                    && $amount === '100.00'
                    && $currency === 'SZL'
                    && $payeeMsisdn === '26876000001'
                    && $externalId === 'idem-1'
                    && $payerMessage === 'Top up'
                    && $payeeNote === 'Top up';
            })
            ->andReturnNull();

        $adapter = new MtnMomoAdapter($client);

        $result = $adapter->disburse($this->movementRequest());

        $this->assertSame($capturedReferenceId, $result->providerRequestId);
        $this->assertSame(WalletMovementResult::STATUS_PENDING, $result->status);
        $this->assertNull($result->failureReason);
    }

    public function test_status_maps_successful_response(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('getRequestToPayStatus')
            ->once()
            ->with('ref-1')
            ->andReturn(['status' => 'SUCCESSFUL']);

        $result = (new MtnMomoAdapter($client))->status('ref-1');

        $this->assertSame('ref-1', $result->providerRequestId);
        $this->assertSame(WalletMovementStatus::STATUS_SUCCESSFUL, $result->status);
        $this->assertNull($result->failureReason);
        $this->assertNotNull($result->settledAt);
    }

    public function test_status_maps_failed_response_with_reason(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('getRequestToPayStatus')
            ->once()
            ->with('ref-1')
            ->andReturn(['status' => 'FAILED', 'reason' => 'PAYER_NOT_FOUND']);

        $result = (new MtnMomoAdapter($client))->status('ref-1');

        $this->assertSame(WalletMovementStatus::STATUS_FAILED, $result->status);
        $this->assertSame('PAYER_NOT_FOUND', $result->failureReason);
        $this->assertNotNull($result->settledAt);
    }

    public function test_status_maps_pending_response(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('getRequestToPayStatus')
            ->once()
            ->with('ref-1')
            ->andReturn(['status' => 'PENDING']);

        $result = (new MtnMomoAdapter($client))->status('ref-1');

        $this->assertSame(WalletMovementStatus::STATUS_PENDING, $result->status);
        $this->assertNull($result->failureReason);
        $this->assertNull($result->settledAt);
    }

    public function test_verify_webhook_signature_accepts_valid_hmac_and_token(): void
    {
        config([
            'mtn_momo.callback_token'        => 'callback-token',
            'mtn_momo.hmac_key'              => 'secret',
            'mtn_momo.verify_callback_token' => true,
            'mtn_momo.verify_hmac_signature' => true,
        ]);
        $rawBody = '{"status":"SUCCESSFUL"}';
        $signature = hash_hmac('sha256', $rawBody, 'secret');

        $adapter = new MtnMomoAdapter($this->mockClient());

        $this->assertTrue($adapter->verifyWebhookSignature($rawBody, [
            'X-Signature'      => $signature,
            'X-Callback-Token' => 'callback-token',
        ]));
    }

    public function test_verify_webhook_signature_rejects_tampered_body(): void
    {
        config([
            'mtn_momo.callback_token'        => 'callback-token',
            'mtn_momo.hmac_key'              => 'secret',
            'mtn_momo.verify_callback_token' => true,
            'mtn_momo.verify_hmac_signature' => true,
        ]);
        $signature = hash_hmac('sha256', '{"status":"SUCCESSFUL"}', 'secret');

        $adapter = new MtnMomoAdapter($this->mockClient());

        $this->assertFalse($adapter->verifyWebhookSignature('{"status":"FAILED"}', [
            'x-signature'      => $signature,
            'x-callback-token' => 'callback-token',
        ]));
    }

    public function test_verify_webhook_signature_rejects_wrong_callback_token(): void
    {
        config([
            'mtn_momo.callback_token'        => 'callback-token',
            'mtn_momo.hmac_key'              => 'secret',
            'mtn_momo.verify_callback_token' => true,
            'mtn_momo.verify_hmac_signature' => true,
        ]);
        $rawBody = '{"status":"SUCCESSFUL"}';
        $signature = hash_hmac('sha256', $rawBody, 'secret');

        $adapter = new MtnMomoAdapter($this->mockClient());

        $this->assertFalse($adapter->verifyWebhookSignature($rawBody, [
            'X-Signature'      => $signature,
            'X-Callback-Token' => 'wrong-token',
        ]));
    }

    public function test_verify_webhook_signature_accepts_when_both_checks_are_disabled(): void
    {
        config([
            'mtn_momo.verify_callback_token' => false,
            'mtn_momo.verify_hmac_signature' => false,
        ]);

        $adapter = new MtnMomoAdapter($this->mockClient());

        $this->assertTrue($adapter->verifyWebhookSignature('tampered', []));
    }

    private function movementRequest(): WalletMovementRequest
    {
        return new WalletMovementRequest(
            providerId: 'mtn_momo',
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
