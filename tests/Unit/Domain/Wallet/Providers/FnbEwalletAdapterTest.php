<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Wallet\Providers;

use App\Domain\FnbEwallet\Services\FnbEwalletClient;
use App\Domain\Wallet\Contracts\WalletLinkResult;
use App\Domain\Wallet\Contracts\WalletMovementRequest;
use App\Domain\Wallet\Contracts\WalletMovementResult;
use App\Domain\Wallet\Contracts\WalletMovementStatus;
use App\Domain\Wallet\Providers\FnbEwallet\FnbEwalletAdapter;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

final class FnbEwalletAdapterTest extends TestCase
{
    private function mockClient(): FnbEwalletClient&MockInterface
    {
        /** @var FnbEwalletClient&MockInterface */
        return Mockery::mock(FnbEwalletClient::class);
    }

    public function test_provider_id(): void
    {
        $this->assertSame('fnb_ewallet', (new FnbEwalletAdapter($this->mockClient()))->providerId());
    }

    public function test_link_returns_active(): void
    {
        $result = (new FnbEwalletAdapter($this->mockClient()))->link('26876000001', 'SZL');

        $this->assertSame('fnb_ewallet', $result->providerId);
        $this->assertSame(WalletLinkResult::LINK_STATUS_ACTIVE, $result->linkStatus);
        $this->assertStringStartsWith('FNB eWallet ', $result->displayName);
    }

    public function test_collect_calls_initiate_credit(): void
    {
        $captured = null;
        $client = $this->mockClient();
        $client->shouldReceive('initiateCredit')
            ->once()
            ->withArgs(function (string $ref, string $amount, string $cur, string $payer, string $ext, string $note) use (&$captured): bool {
                $captured = $ref;

                return Str::isUuid($ref) && $amount === '100.00' && $cur === 'SZL'
                    && $payer === '26876000001' && $ext === 'idem-1' && $note === 'Top up';
            })
            ->andReturn([]);

        $result = (new FnbEwalletAdapter($client))->collect($this->movementRequest());

        $this->assertSame($captured, $result->providerRequestId);
        $this->assertSame(WalletMovementResult::STATUS_PENDING, $result->status);
    }

    public function test_collect_failed_on_client_exception(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('initiateCredit')->once()->andThrow(new RuntimeException('FNB credits failed'));

        $result = (new FnbEwalletAdapter($client))->collect($this->movementRequest());

        $this->assertSame(WalletMovementResult::STATUS_FAILED, $result->status);
        $this->assertSame('FNB credits failed', $result->failureReason);
    }

    public function test_disburse_calls_initiate_transfer(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('initiateTransfer')->once()->andReturn([]);

        $result = (new FnbEwalletAdapter($client))->disburse($this->movementRequest());

        $this->assertSame(WalletMovementResult::STATUS_PENDING, $result->status);
        $this->assertTrue(Str::isUuid($result->providerRequestId));
    }

    public function test_status_maps_posted_as_successful(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('getCreditStatus')->once()->with('ref-1')->andReturn(['status' => 'POSTED']);

        $result = (new FnbEwalletAdapter($client))->status('ref-1');

        $this->assertSame(WalletMovementStatus::STATUS_SUCCESSFUL, $result->status);
    }

    public function test_status_maps_declined_as_failed_with_reason(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('getCreditStatus')->once()->andReturn(['status' => 'DECLINED', 'reason' => 'AML_HOLD']);

        $result = (new FnbEwalletAdapter($client))->status('ref-1');

        $this->assertSame(WalletMovementStatus::STATUS_FAILED, $result->status);
        $this->assertSame('AML_HOLD', $result->failureReason);
    }

    public function test_status_maps_pending(): void
    {
        $client = $this->mockClient();
        $client->shouldReceive('getCreditStatus')->once()->andReturn(['status' => 'PENDING']);

        $this->assertSame(
            WalletMovementStatus::STATUS_PENDING,
            (new FnbEwalletAdapter($client))->status('ref-1')->status,
        );
    }

    public function test_verify_webhook_signature_accepts_valid(): void
    {
        config([
            'fnb_ewallet.callback_token'        => 'tok',
            'fnb_ewallet.hmac_key'              => 'sec',
            'fnb_ewallet.verify_callback_token' => true,
            'fnb_ewallet.verify_hmac_signature' => true,
        ]);
        $body = '{"status":"POSTED"}';

        $this->assertTrue((new FnbEwalletAdapter($this->mockClient()))->verifyWebhookSignature($body, [
            'X-Callback-Token' => 'tok',
            'X-Signature'      => hash_hmac('sha256', $body, 'sec'),
        ]));
    }

    public function test_verify_webhook_signature_rejects_bad_signature(): void
    {
        config([
            'fnb_ewallet.callback_token'        => 'tok',
            'fnb_ewallet.hmac_key'              => 'sec',
            'fnb_ewallet.verify_callback_token' => true,
            'fnb_ewallet.verify_hmac_signature' => true,
        ]);

        $this->assertFalse((new FnbEwalletAdapter($this->mockClient()))->verifyWebhookSignature('{"x":1}', [
            'X-Callback-Token' => 'tok',
            'X-Signature'      => 'bad',
        ]));
    }

    private function movementRequest(): WalletMovementRequest
    {
        return new WalletMovementRequest(
            providerId: 'fnb_ewallet',
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
