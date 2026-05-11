<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Jobs;

use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Services\CardLifecycleService;
use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class CloseCardsOnSubscriptionEndJob implements ShouldQueue
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

    public function handle(CardLifecycleService $lifecycle): void
    {
        foreach (Tenant::query()->cursor() as $tenant) {
            tenancy()->initialize($tenant);

            try {
                CardSubscription::query()
                    ->where('status', CardSubscriptionStatus::Cancelled)
                    ->whereDate('current_period_end', '<=', today())
                    ->whereHas('cards', static fn ($q) => $q->where('status', 'active'))
                    ->with(['subscriber', 'cards' => static fn ($q) => $q->where('status', 'active')])
                    ->orderBy('id')
                    ->chunkById(50, function ($subs) use ($lifecycle): void {
                        foreach ($subs as $sub) {
                            /** @var User|null $actor */
                            $actor = $sub->subscriber;
                            if ($actor === null) {
                                continue;
                            }

                            foreach ($sub->cards as $card) {
                                $lifecycle->cancelCard($actor, $card, 'subscription_ended');
                            }
                        }
                    });
            } catch (Throwable $e) {
                Log::error('cards.close_cards_on_subscription_end.tenant_failed', [
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
        return array_merge(['cards', 'billing', 'close'], $this->tenantTags());
    }
}
