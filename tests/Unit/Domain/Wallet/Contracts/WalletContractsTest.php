<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Wallet\Contracts;

use App\Domain\Wallet\Contracts\UnknownWalletProviderException;
use App\Domain\Wallet\Contracts\WalletLinkResult;
use App\Domain\Wallet\Contracts\WalletMovementRequest;
use App\Domain\Wallet\Contracts\WalletMovementResult;
use App\Domain\Wallet\Contracts\WalletMovementStatus;
use App\Domain\Wallet\Contracts\WalletProviderAdapter;
use ReflectionClass;
use Tests\TestCase;

final class WalletContractsTest extends TestCase
{
    public function test_wallet_link_result_holds_documented_properties(): void
    {
        $result = new WalletLinkResult(
            providerId: 'test_provider',
            providerAccountRef: 'ref123',
            displayName: 'Test Account',
            linkToken: 'token_abc',
            linkStatus: 'active',
        );

        $this->assertSame('test_provider', $result->providerId);
        $this->assertSame('ref123', $result->providerAccountRef);
        $this->assertSame('Test Account', $result->displayName);
        $this->assertSame('token_abc', $result->linkToken);
        $this->assertSame('active', $result->linkStatus);
    }

    public function test_wallet_link_result_status_constants_match_string_literals(): void
    {
        $this->assertSame('active', WalletLinkResult::LINK_STATUS_ACTIVE);
        $this->assertSame('pending', WalletLinkResult::LINK_STATUS_PENDING);
        $this->assertSame('failed', WalletLinkResult::LINK_STATUS_FAILED);
    }

    public function test_wallet_movement_request_holds_documented_properties(): void
    {
        $request = new WalletMovementRequest(
            providerId: 'test_provider',
            providerAccountRef: 'ref456',
            linkToken: 'token_xyz',
            amountMinor: 1050,
            currency: 'ZAR',
            idempotencyKey: 'idempotency_123',
            callbackUrl: 'https://example.com/callback',
            memo: 'Test payment',
        );

        $this->assertSame('test_provider', $request->providerId);
        $this->assertSame('ref456', $request->providerAccountRef);
        $this->assertSame('token_xyz', $request->linkToken);
        $this->assertSame(1050, $request->amountMinor);
        $this->assertSame('ZAR', $request->currency);
        $this->assertSame('idempotency_123', $request->idempotencyKey);
        $this->assertSame('https://example.com/callback', $request->callbackUrl);
        $this->assertSame('Test payment', $request->memo);
    }

    public function test_wallet_movement_result_holds_documented_properties_and_null_failure_reason_is_allowed(): void
    {
        $resultWithoutFailure = new WalletMovementResult(
            providerRequestId: 'req_123',
            status: 'successful',
            failureReason: null,
        );

        $this->assertSame('req_123', $resultWithoutFailure->providerRequestId);
        $this->assertSame('successful', $resultWithoutFailure->status);
        $this->assertNull($resultWithoutFailure->failureReason);

        $resultWithFailure = new WalletMovementResult(
            providerRequestId: 'req_456',
            status: 'failed',
            failureReason: 'Account not found',
        );

        $this->assertSame('req_456', $resultWithFailure->providerRequestId);
        $this->assertSame('failed', $resultWithFailure->status);
        $this->assertSame('Account not found', $resultWithFailure->failureReason);
    }

    public function test_wallet_movement_result_status_constants_match_string_literals(): void
    {
        $this->assertSame('pending', WalletMovementResult::STATUS_PENDING);
        $this->assertSame('successful', WalletMovementResult::STATUS_SUCCESSFUL);
        $this->assertSame('failed', WalletMovementResult::STATUS_FAILED);
    }

    public function test_wallet_movement_status_holds_documented_properties_and_nullable_settled_at(): void
    {
        $statusWithoutSettledAt = new WalletMovementStatus(
            providerRequestId: 'req_789',
            status: 'pending',
            failureReason: null,
            settledAt: null,
        );

        $this->assertSame('req_789', $statusWithoutSettledAt->providerRequestId);
        $this->assertSame('pending', $statusWithoutSettledAt->status);
        $this->assertNull($statusWithoutSettledAt->failureReason);
        $this->assertNull($statusWithoutSettledAt->settledAt);

        $statusWithSettledAt = new WalletMovementStatus(
            providerRequestId: 'req_999',
            status: 'successful',
            failureReason: null,
            settledAt: 1715785200,
        );

        $this->assertSame('req_999', $statusWithSettledAt->providerRequestId);
        $this->assertSame('successful', $statusWithSettledAt->status);
        $this->assertNull($statusWithSettledAt->failureReason);
        $this->assertSame(1715785200, $statusWithSettledAt->settledAt);
    }

    public function test_unknown_wallet_provider_exception_carries_provider_id_and_message(): void
    {
        $exception = new UnknownWalletProviderException('foo_bar');

        $this->assertSame('foo_bar', $exception->providerId);
        $this->assertSame('Unknown wallet provider: foo_bar', $exception->getMessage());
    }

    public function test_wallet_provider_adapter_interface_has_required_methods(): void
    {
        $reflection = new ReflectionClass(WalletProviderAdapter::class);

        $this->assertTrue($reflection->isInterface());

        $methodNames = array_map(
            static fn ($method) => $method->getName(),
            $reflection->getMethods()
        );

        $requiredMethods = [
            'providerId',
            'link',
            'collect',
            'disburse',
            'status',
            'verifyWebhookSignature',
        ];

        foreach ($requiredMethods as $methodName) {
            $this->assertContains($methodName, $methodNames, "Interface must have method: {$methodName}");
        }
    }

    public function test_dtos_are_readonly(): void
    {
        $dtoClasses = [
            WalletLinkResult::class,
            WalletMovementRequest::class,
            WalletMovementResult::class,
            WalletMovementStatus::class,
        ];

        foreach ($dtoClasses as $className) {
            $reflection = new ReflectionClass($className);
            $this->assertTrue(
                $reflection->isReadonly(),
                "{$className} must be a readonly class"
            );
        }
    }
}
