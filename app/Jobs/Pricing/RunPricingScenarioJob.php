<?php

declare(strict_types=1);

namespace App\Jobs\Pricing;

use App\Domain\Pricing\Models\PricingScenario;
use App\Domain\Pricing\Services\ScenarioSimulator;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queues a {@see ScenarioSimulator} run for a given scenario and date window.
 *
 * Failure policy: bounded retries (tries=2). Errors are logged with the
 * scenario id + window so an operator can rerun manually after fixing the
 * underlying issue (e.g. missing pricing rule for an emerging category).
 */
class RunPricingScenarioJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly int $scenarioId,
        public readonly string $fromDate,
        public readonly string $toDate,
        public readonly bool $behavioural = false,
    ) {
    }

    public function handle(ScenarioSimulator $simulator): void
    {
        /** @var PricingScenario $scenario */
        $scenario = PricingScenario::findOrFail($this->scenarioId);

        $simulator->run(
            scenario: $scenario,
            from: Carbon::parse($this->fromDate),
            to: Carbon::parse($this->toDate),
            behavioural: $this->behavioural,
        );
    }

    public function failed(Throwable $e): void
    {
        Log::error('RunPricingScenarioJob failed', [
            'scenario_id' => $this->scenarioId,
            'from'        => $this->fromDate,
            'to'          => $this->toDate,
            'behavioural' => $this->behavioural,
            'error'       => $e->getMessage(),
        ]);
    }
}
