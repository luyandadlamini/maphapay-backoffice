<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Jobs;

use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Services\CardBillingService;
use App\Domain\Shared\Jobs\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSingleSubscriptionRenewalJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TenantAwareJob;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(public string $subscriptionId)
    {
        $this->onQueue('billing');
        $this->initializeTenantAwareJob();
    }

    public function handle(CardBillingService $billing): void
    {
        $this->verifyTenantContext();

        DB::transaction(function () use ($billing): void {
            /** @var CardSubscription|null $sub */
            $sub = CardSubscription::query()->lockForUpdate()->find($this->subscriptionId);

            if ($sub === null) {
                return;
            }

            if ($sub->status === CardSubscriptionStatus::Active && $sub->next_billing_date <= now()) {
                $billing->billRenewal($sub);

                return;
            }

            if ($sub->status === CardSubscriptionStatus::PastDue) {
                $billing->retryFailedPayment($sub);
            }
        });
    }

    public function failed(Throwable $e): void
    {
        Log::error('Card job failed', [
            'job'             => static::class,
            'subscription_id' => $this->subscriptionId,
            'tenant'          => $this->dispatchedTenantId,
            'error'           => $e->getMessage(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return array_merge(
            ['cards', 'billing', 'sub:' . $this->subscriptionId],
            $this->tenantTags()
        );
    }
}
