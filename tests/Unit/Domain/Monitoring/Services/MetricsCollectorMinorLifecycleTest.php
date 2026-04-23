<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Monitoring\Services;

use App\Domain\Monitoring\Services\MetricsCollector;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetricsCollectorMinorLifecycleTest extends TestCase
{
    #[Test]
    public function it_tracks_minor_lifecycle_counters_in_cache(): void
    {
        Cache::flush();
        $collector = app(MetricsCollector::class);

        $collector->recordMinorLifecycleTransitionScheduled();
        $collector->recordMinorLifecycleTransitionBlocked();
        $collector->recordMinorLifecycleExceptionOpened();
        $collector->recordMinorLifecycleExceptionsSlaBreached(2);
        $collector->recordMinorLifecycleExceptionResolved();

        $snapshot = $collector->getMinorLifecycleCounterSnapshot();

        $this->assertSame(1, $snapshot['transitions_scheduled_total']);
        $this->assertSame(1, $snapshot['transitions_blocked_total']);
        $this->assertSame(0, $snapshot['lifecycle_exceptions_open_total']);
        $this->assertSame(2, $snapshot['lifecycle_exceptions_sla_breached_total']);
    }
}
