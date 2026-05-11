<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Jobs;

use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RetryFailedBillingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TenantAwareJob;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('billing');
        $this->initializeTenantAwareJob();
    }

    public function requiresTenantContext(): bool
    {
        return false;
    }

    public function handle(): void
    {
        foreach (Tenant::query()->cursor() as $tenant) {
            tenancy()->initialize($tenant);

            try {
                CardSubscription::query()
                    ->where('status', CardSubscriptionStatus::PastDue)
                    ->orderBy('id')
                    ->chunkById(100, function ($subs): void {
                        foreach ($subs as $sub) {
                            $lastAttempt = $sub->billingAttempts()
                                ->orderByDesc('attempted_at')
                                ->first();

                            if ($lastAttempt === null || $lastAttempt->attempted_at->greaterThan(now()->subDay())) {
                                continue;
                            }

                            ProcessSingleSubscriptionRenewalJob::dispatch((string) $sub->id)
                                ->onQueue('billing');
                        }
                    });
            } catch (Throwable $e) {
                Log::error('cards.retry_failed_billing.tenant_failed', [
                    'tenant_id' => $tenant->id,
                    'error'     => $e->getMessage(),
                ]);
            } finally {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        }
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
        return array_merge(['cards', 'billing', 'retry'], $this->tenantTags());
    }
}
