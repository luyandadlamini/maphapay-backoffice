<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Jobs;

use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Services\CardSubscriptionService;
use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SuspendPastDueSubscriptionsJob implements ShouldQueue
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

    public function handle(CardSubscriptionService $subscriptions): void
    {
        foreach (Tenant::query()->cursor() as $tenant) {
            tenancy()->initialize($tenant);

            try {
                CardSubscription::query()
                    ->where('status', CardSubscriptionStatus::PastDue)
                    ->where('grace_period_ends_at', '<=', now())
                    ->orderBy('id')
                    ->chunkById(100, function ($subs) use ($subscriptions): void {
                        foreach ($subs as $sub) {
                            $subscriptions->suspend($sub);
                        }
                    });
            } catch (Throwable $e) {
                Log::error('cards.suspend_past_due.tenant_failed', [
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
        return array_merge(['cards', 'billing', 'suspend'], $this->tenantTags());
    }
}
