<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Jobs;

use App\Domain\CardSubscriptions\Enums\CardFeeStatus;
use App\Domain\CardSubscriptions\Enums\CardFeeType;
use App\Domain\Shared\Jobs\TenantAwareJob;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecalculateCardsMrrJob implements ShouldQueue
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
        $aggregate = '0';

        foreach (Tenant::query()->cursor() as $tenant) {
            tenancy()->initialize($tenant);

            try {
                $sum = DB::table('card_fees')
                    ->where('fee_type', CardFeeType::Subscription->value)
                    ->where('status', CardFeeStatus::Charged->value)
                    ->where('charged_at', '>=', now()->subDays(30))
                    ->sum('amount');

                $aggregate = bcadd($aggregate, (string) ($sum ?? '0'), 2);
            } catch (Throwable $e) {
                Log::warning('cards.recalc_mrr.tenant_failed', [
                    'tenant_id' => $tenant->id,
                    'error'     => $e->getMessage(),
                ]);
            } finally {
                if (tenancy()->initialized) {
                    tenancy()->end();
                }
            }
        }

        Cache::put('cards.mrr', $aggregate, now()->addHours(2));
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
        return array_merge(['cards', 'mrr'], $this->tenantTags());
    }
}
