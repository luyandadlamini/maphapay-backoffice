<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Monitoring\Services;

use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Monitoring\Services\MaphaPayMoneyMovementTelemetry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Large]
class MaphaPayMoneyMovementTelemetryTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    #[Test]
    public function log_verification_failure_uses_atomic_cache_increment(): void
    {
        $telemetry = new MaphaPayMoneyMovementTelemetry();
        $request = Request::create('/api/verification-process/verify/pin', 'POST');

        Cache::shouldReceive('add')
            ->once()
            ->with(
                MaphaPayMoneyMovementTelemetry::METRIC_VERIFICATION_FAILURES_TOTAL,
                0,
                \Mockery::type(\DateTimeInterface::class),
            )
            ->andReturnTrue();
        Cache::shouldReceive('increment')
            ->once()
            ->with(MaphaPayMoneyMovementTelemetry::METRIC_VERIFICATION_FAILURES_TOTAL)
            ->andReturn(1);

        Log::shouldReceive('channel')->once()->with('structured')->andReturnSelf();
        Log::shouldReceive('log')->once();

        $telemetry->logVerificationFailure(
            $request,
            'pin',
            'send_money',
            'TRX-123',
            'Invalid transaction PIN.',
            422,
        );

        $this->assertTrue(true);
    }

    #[Test]
    public function verification_failure_log_includes_canonical_money_movement_context_when_transaction_is_available(): void
    {
        $telemetry = new MaphaPayMoneyMovementTelemetry();
        $request = Request::create('/api/verification-process/verify/otp', 'POST');

        $transaction = new AuthorizedTransaction([
            'user_id' => 42,
            'trx' => 'TRX-123',
            'status' => AuthorizedTransaction::STATUS_FAILED,
            'failure_reason' => 'Invalid OTP.',
            'payload' => [
                'from_account_uuid' => 'from-uuid',
                'to_account_uuid' => 'to-uuid',
                'recipient_user_id' => 77,
                'amount' => '10.00',
                'asset_code' => 'SZL',
                '_verification_policy' => [
                    'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
                    'risk_reason' => 'velocity_limit_exceeded',
                ],
            ],
            'result' => [
                'reference' => 'REF-123',
            ],
        ]);
        $transaction->forceFill(['id' => 'txn-123']);

        Cache::shouldReceive('add')->once()->andReturnTrue();
        Cache::shouldReceive('increment')->once()->andReturn(1);

        Log::shouldReceive('channel')->once()->with('structured')->andReturnSelf();
        Log::shouldReceive('log')->once()->with(
            'warning',
            'maphapay.compat.money_movement',
            \Mockery::on(function (array $payload): bool {
                return $payload['event'] === 'verification_failed'
                    && $payload['transaction_id'] === 'txn-123'
                    && $payload['trx'] === 'TRX-123'
                    && $payload['reference'] === 'REF-123'
                    && $payload['sender_account_uuid'] === 'from-uuid'
                    && $payload['recipient_account_uuid'] === 'to-uuid'
                    && $payload['sender_user_id'] === 42
                    && $payload['recipient_user_id'] === 77
                    && $payload['amount'] === '10.00'
                    && $payload['asset_code'] === 'SZL'
                    && $payload['status'] === AuthorizedTransaction::STATUS_FAILED
                    && $payload['failure_reason'] === 'Invalid OTP.'
                    && $payload['verification_policy'] === AuthorizedTransaction::VERIFICATION_OTP
                    && $payload['risk_reason'] === 'velocity_limit_exceeded';
            }),
        );

        $telemetry->logVerificationFailure(
            $request,
            'otp',
            'send_money',
            'TRX-123',
            'Invalid OTP.',
            422,
            $transaction,
        );

        $this->assertTrue(true);
    }
}
