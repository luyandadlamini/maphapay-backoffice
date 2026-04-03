<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Monitoring\Services;

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
}
