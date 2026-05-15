<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Wallet\Providers;

use App\Domain\StandardUnayo\Services\StandardUnayoClient;
use App\Domain\Wallet\Contracts\WalletLinkResult;
use App\Domain\Wallet\Contracts\WalletMovementRequest;
use App\Domain\Wallet\Contracts\WalletMovementResult;
use App\Domain\Wallet\Contracts\WalletMovementStatus;
use App\Domain\Wallet\Providers\StandardUnayo\StandardUnayoAdapter;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

final class StandardUnayoAdapterTest extends TestCase
{
    private function mockClient(): StandardUnayoClient&MockInterface
    {
        /** @var StandardUnayoClient&MockInterface */
        return Mockery::mock(StandardUnayoClient::class);
    }

    public function test_provider_id(): void
    {
        $this->assertSame('standard_unayo', (new StandardUnayoAdapter($this->mockClient()))->providerId());
    }

    public function test_link_active(): void
    {
        $result = (new StandardUnayoAdapter($this->mockClient()))->link('26876000001', 'SZL');

        $this->assertSame('standard_unayo', $result->providerId);
        $this->assertSame(WalletLinkResult::LINK_STATUS_ACTIVE, $result->linkStatus);
        $this->assertStringStartsWith('Unayo ', $result->displayName);
    }

    public function test_collect_calls_cash_in(): void
    {
        $captured = null;
        $client = $this->mockClient();
        $client->shouldReceive('initiateCashIn')
            ->once()
            ->withArgs(function (string $ref) use (&$captured): bool {
                $captured = $ref;

                return Str::isUuid($ref);
            })
            ->andReturn([]);

        $result = (new StandardUnayoAdapter($client))->collect($this->req());

        $this->assertSame($captured, $result->providerRequestId);
        $this->assertSame(WalletMovementResult::STATUS_PENDING, $result->status);
    }

    public function test_collect_failed_on_exception(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('initiateCashIn')->andThrow(new RuntimeException('Unayo cashin failed'));

        $result = (new StandardUnayoAdapter($client))->collect($this->req());

        $this->assertSame(WalletMovementResult::STATUS_FAILED, $result->status);
        $this->assertSame('Unayo cashin failed', $result->failureReason);
    }

    public function test_disburse_calls_cash_out(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('initiateCashOut')->once()->andReturn([]);

        $this->assertSame(
            WalletMovementResult::STATUS_PENDING,
            (new StandardUnayoAdapter($client))->disburse($this->req())->status,
        );
    }

    public function test_status_settled_maps_successful(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('getCashInStatus')->once()->andReturn(['status' => 'SETTLED']);

        $this->assertSame(
            WalletMovementStatus::STATUS_SUCCESSFUL,
            (new StandardUnayoAdapter($client))->status('ref-1')->status,
        );
    }

    public function test_status_reversed_maps_failed(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('getCashInStatus')->once()->andReturn(['status' => 'REVERSED', 'reason' => 'TIMEOUT']);

        $result = (new StandardUnayoAdapter($client))->status('ref-1');

        $this->assertSame(WalletMovementStatus::STATUS_FAILED, $result->status);
        $this->assertSame('TIMEOUT', $result->failureReason);
    }

    public function test_status_pending(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('getCashInStatus')->once()->andReturn(['status' => 'PENDING']);

        $this->assertSame(
            WalletMovementStatus::STATUS_PENDING,
            (new StandardUnayoAdapter($client))->status('ref-1')->status,
        );
    }

    public function test_verify_webhook_signature_accepts_valid(): void
    {
        config([
            'standard_unayo.callback_token'        => 'tok',
            'standard_unayo.hmac_key'              => 'sec',
            'standard_unayo.verify_callback_token' => true,
            'standard_unayo.verify_hmac_signature' => true,
        ]);
        $body = '{"status":"SETTLED"}';

        $this->assertTrue((new StandardUnayoAdapter($this->mockClient()))->verifyWebhookSignature($body, [
            'X-Callback-Token' => 'tok',
            'X-Signature'      => hash_hmac('sha256', $body, 'sec'),
        ]));
    }

    public function test_verify_webhook_signature_rejects_bad(): void
    {
        config([
            'standard_unayo.callback_token'        => 'tok',
            'standard_unayo.hmac_key'              => 'sec',
            'standard_unayo.verify_callback_token' => true,
            'standard_unayo.verify_hmac_signature' => true,
        ]);

        $this->assertFalse((new StandardUnayoAdapter($this->mockClient()))->verifyWebhookSignature('{"x":1}', [
            'X-Callback-Token' => 'tok',
            'X-Signature'      => 'bad',
        ]));
    }

    private function req(): WalletMovementRequest
    {
        return new WalletMovementRequest(
            providerId: 'standard_unayo',
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
