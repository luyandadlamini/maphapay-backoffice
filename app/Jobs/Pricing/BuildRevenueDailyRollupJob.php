<?php

declare(strict_types=1);

namespace App\Jobs\Pricing;

use App\Domain\Pricing\Models\RevenueDailyRollup;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BuildRevenueDailyRollupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly ?string $date = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $date = $this->date ?? Carbon::yesterday()->toDateString();

        $rows = DB::table('fee_events')
            ->whereDate('assessed_at', $date)
            ->selectRaw('
                DATE(assessed_at)         AS `date`,
                product_code,
                segment_id,
                currency,
                SUM(amount_minor)         AS gross_revenue_minor,
                COUNT(*)                  AS fee_count,
                COUNT(DISTINCT user_id)   AS unique_users,
                ROUND(AVG(amount_minor))  AS avg_fee_minor
            ')
            ->groupBy(DB::raw('DATE(assessed_at)'), 'product_code', 'segment_id', 'currency')
            ->get()
            ->map(fn (object $row): array => [
                'date'                => $row->date,
                'product_code'        => $row->product_code,
                'segment_id'          => $row->segment_id,
                'currency'            => $row->currency,
                'gross_revenue_minor' => (int) $row->gross_revenue_minor,
                'fee_count'           => (int) $row->fee_count,
                'unique_users'        => (int) $row->unique_users,
                'avg_fee_minor'       => (int) $row->avg_fee_minor,
                'created_at'          => now()->toDateTimeString(),
                'updated_at'          => now()->toDateTimeString(),
            ])
            ->toArray();

        if (empty($rows)) {
            Log::info('BuildRevenueDailyRollupJob: no fee events', ['date' => $date]);

            return;
        }

        // Delete-then-insert inside a transaction to handle NULL segment_id correctly.
        // MySQL unique indexes treat NULL != NULL, so upsert() would create duplicate rows
        // for the null-segment group on re-runs.
        DB::transaction(function () use ($date, $rows): void {
            RevenueDailyRollup::whereDate('date', $date)->delete();
            RevenueDailyRollup::insert($rows);
        });

        Log::info('BuildRevenueDailyRollupJob: done', [
            'date' => $date,
            'rows' => count($rows),
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('BuildRevenueDailyRollupJob failed', [
            'date'  => $this->date ?? Carbon::yesterday()->toDateString(),
            'error' => $e->getMessage(),
        ]);
    }
}
