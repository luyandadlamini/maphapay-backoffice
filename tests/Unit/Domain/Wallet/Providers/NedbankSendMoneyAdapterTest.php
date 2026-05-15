<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Wallet\Providers;

use App\Domain\NedbankSendMoney\Services\NedbankSendMoneyClient;
use App\Domain\Wallet\Contracts\WalletLinkResult;
use App\Domain\Wallet\Contracts\WalletMovementRequest;
use App\Domain\Wallet\Contracts\WalletMovementResult;
use App\Domain\Wallet\Contracts\WalletMovementStatus;
use App\Domain\Wallet\Providers\NedbankSendMoney\NedbankSendMoneyAdapter;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

final class NedbankSendMoneyAdapterTest extends TestCase
{
    private function mockClient(): NedbankSendMoneyClient&MockInterface
    {
        /** @var NedbankSendMoneyClient&MockInterface */
        return Mockery::mock(NedbankSendMoneyClient::class);
    }

    public function test_provider_id(): void
    {
        $this->assertSame('nedbank_send_money', (new NedbankSendMoneyAdapter($this->mockClient()))->providerId());
    }

    public function test_link_active(): void
    {
        $result = (new NedbankSendMoneyAdapter($this->mockClient()))->link('26876000001', 'SZL');

        $this->assertSame(WalletLinkResult::LINK_STATUS_ACTIVE, $result->linkStatus);
        $this->assertStringStartsWith('Nedbank Send Money ', $result->displayName);
    }

    public function test_collect_calls_inbound(): void
    {
        $captured = null;
        $client = $this->mockClient();
        $client->shouldReceive('initiateInbound')
            ->once()
            ->withArgs(function (string $ref) use (&$captured): bool {
                $captured = $ref;

                return Str::isUuid($ref);
            })
            ->andReturn([]);

        $result = (new NedbankSendMoneyAdapter($client))->collect($this->req());

        $this->assertSame($captured, $result->providerRequestId);
        $this->assertSame(WalletMovementResult::STATUS_PENDING, $result->status);
    }

    public function test_collect_failed_on_exception(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('initiateInbound')->andThrow(new RuntimeException('Nedbank inbound failed'));

        $result = (new NedbankSendMoneyAdapter($client))->collect($this->req());

        $this->assertSame(WalletMovementResult::STATUS_FAILED, $result->status);
        $this->assertSame('Nedbank inbound failed', $result->failureReason);
    }

    public function test_disburse_calls_outbound(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('initiateOutbound')->once()->andReturn([]);

        $this->assertSame(
            WalletMovementResult::STATUS_PENDING,
            (new NedbankSendMoneyAdapter($client))->disburse($this->req())->status,
        );
    }

    public function test_status_completed_maps_successful(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('getInboundStatus')->once()->andReturn(['status' => 'COMPLETED']);

        $this->assertSame(
            WalletMovementStatus::STATUS_SUCCESSFUL,
            (new NedbankSendMoneyAdapter($client))->status('ref-1')->status,
        );
    }

    public function test_status_expired_maps_failed(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('getInboundStatus')->once()->andReturn(['status' => 'EXPIRED', 'reason' => 'NOT_REDEEMED']);

        $result = (new NedbankSendMoneyAdapter($client))->status('ref-1');

        $this->assertSame(WalletMovementStatus::STATUS_FAILED, $result->status);
        $this->assertSame('NOT_REDEEMED', $result->failureReason);
    }

    public function test_status_pending(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('getInboundStatus')->once()->andReturn(['status' => 'PENDING']);

        $this->assertSame(
            WalletMovementStatus::STATUS_PENDING,
            (new NedbankSendMoneyAdapter($client))->status('ref-1')->status,
        );
    }

    public function test_verify_webhook_signature_accepts_valid(): void
    {
        config([
            'nedbank_send_money.callback_token'        => 'tok',
            'nedbank_send_money.hmac_key'              => 'sec',
            'nedbank_send_money.verify_callback_token' => true,
            'nedbank_send_money.verify_hmac_signature' => true,
        ]);
        $body = '{"status":"COMPLETED"}';

        $this->assertTrue((new NedbankSendMoneyAdapter($this->mockClient()))->verifyWebhookSignature($body, [
            'X-Callback-Token' => 'tok',
            'X-Signature'      => hash_hmac('sha256', $body, 'sec'),
        ]));
    }

    public function test_verify_webhook_signature_rejects_bad(): void
    {
        config([
            'nedbank_send_money.callback_token'        => 'tok',
            'nedbank_send_money.hmac_key'              => 'sec',
            'nedbank_send_money.verify_callback_token' => true,
            'nedbank_send_money.verify_hmac_signature' => true,
        ]);

        $this->assertFalse((new NedbankSendMoneyAdapter($this->mockClient()))->verifyWebhookSignature('{"x":1}', [
            'X-Callback-Token' => 'tok',
            'X-Signature'      => 'bad',
        ]));
    }

    private function req(): WalletMovementRequest
    {
        return new WalletMovementRequest(
            providerId: 'nedbank_send_money',
            providerAccountRef: '26876000001',
            linkToken: 'tok',
            amountMinor: 10000,
            currency: 'SZL',
            idempotencyKey: 'idem-1',
            callbackUrl: 'https://example.test/cb',
            memo: 'Top up',
        );
    }
}
