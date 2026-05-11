<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Jobs;

use App\Domain\Shared\Jobs\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Placeholder: reveal rate limits use Redis TTL; no DB rows to purge today.
 *
 * @see docs/cards/07-jobs-and-events.md §3
 */
class PurgeExpiredRevealUrlsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TenantAwareJob;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('default');
        $this->initializeTenantAwareJob();
    }

    public function requiresTenantContext(): bool
    {
        return false;
    }

    public function handle(): void
    {
        // Intentionally empty — reserved for future reveal-cache cleanup.
    }

    public function failed(Throwable $e): void
    {
        Log::error('Card job failed', [
            'job'   => static::class,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return array_merge(['cards', 'purge-reveal'], $this->tenantTags());
    }
}
