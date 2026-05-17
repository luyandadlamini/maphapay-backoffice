# Revenue Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a Revenue Dashboard admin page with a daily rollup job, four analytics widgets (stacked bar, doughnut, line, table), and a Filament Page restricted to finance/platform_admin roles.

**Architecture:** A queued `BuildRevenueDailyRollupJob` aggregates yesterday's `fee_events` into `revenue_daily_rollups` nightly at 02:15. Four Filament widgets read from `revenue_daily_rollups`, `revenue_targets`, and `pricing_scenarios` and are rendered by a single `RevenueDashboard` Filament Page. The NULL-segment upsert ambiguity is avoided by delete-then-insert inside a DB transaction.

**Tech Stack:** Laravel 12, Filament v3, Chart.js (via Filament's `ChartWidget`), MySQL 8, Pest (feature test for the job).

---

## File Map

| Action | Path | Purpose |
|--------|------|---------|
| **Modify** | `app/Domain/Pricing/Models/RevenueDailyRollup.php` | Bring model in sync with new migration schema |
| **Create** | `app/Jobs/Pricing/BuildRevenueDailyRollupJob.php` | Nightly aggregation job |
| **Modify** | `routes/console.php` | Register job at 02:15 daily |
| **Create** | `app/Filament/Admin/Widgets/Revenue/RevenueByCategoryWidget.php` | Stacked bar — 90-day revenue by product_code |
| **Create** | `app/Filament/Admin/Widgets/Revenue/RevenueBySegmentWidget.php` | Doughnut — 30-day revenue by segment |
| **Create** | `app/Filament/Admin/Widgets/Revenue/TargetVsActualWidget.php` | Line — monthly target vs actual (3 months) |
| **Create** | `app/Filament/Admin/Widgets/Revenue/ScenarioComparisonWidget.php` | Table — last 3 scenarios vs 90-day actuals |
| **Create** | `resources/views/filament/admin/widgets/revenue/scenario-comparison-widget.blade.php` | Blade view for scenario table |
| **Create** | `app/Filament/Admin/Pages/RevenueDashboard.php` | Filament page registering all 4 widgets |
| **Create** | `resources/views/filament/admin/pages/revenue-dashboard.blade.php` | Minimal page view |
| **Create** | `tests/Feature/Domain/Pricing/BuildRevenueDailyRollupJobTest.php` | Feature test for the job |

---

## Task 1: Update RevenueDailyRollup Model

The existing model was scaffolded with the old schema (`stream_code`, `total_fees`, `HasUuids`). The new migration (`2026_05_16_000008_create_revenue_daily_rollups_table.php`) uses `bigIncrements` and different columns. Bring the model in sync.

**Files:**
- Modify: `app/Domain/Pricing/Models/RevenueDailyRollup.php`

- [ ] **Step 1: Replace the model body**

Open `app/Domain/Pricing/Models/RevenueDailyRollup.php` and replace the entire file content with:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Daily aggregated fee revenue per (date, product_code, segment_id, currency).
 *
 * @property int         $id
 * @property string      $date
 * @property string      $product_code
 * @property int|null    $segment_id
 * @property string      $currency
 * @property int         $gross_revenue_minor
 * @property int         $fee_count
 * @property int         $unique_users
 * @property int         $avg_fee_minor
 */
class RevenueDailyRollup extends Model
{
    use UsesTenantConnection;

    protected $table = 'revenue_daily_rollups';

    protected $fillable = [
        'date',
        'product_code',
        'segment_id',
        'currency',
        'gross_revenue_minor',
        'fee_count',
        'unique_users',
        'avg_fee_minor',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'date'                => 'date',
        'gross_revenue_minor' => 'integer',
        'fee_count'           => 'integer',
        'unique_users'        => 'integer',
        'avg_fee_minor'       => 'integer',
    ];
}
```

- [ ] **Step 2: Verify no PHPStan errors on the model alone**

```bash
cd /Users/Lihle/Development/Coding/maphapay-backoffice
XDEBUG_MODE=off vendor/bin/phpstan analyse app/Domain/Pricing/Models/RevenueDailyRollup.php --memory-limit=2G
```

Expected: `[OK] No errors`

- [ ] **Step 3: Commit**

```bash
git add app/Domain/Pricing/Models/RevenueDailyRollup.php
git commit -m "refactor(pricing): align RevenueDailyRollup model with new migration schema

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 2: Write Failing Test for BuildRevenueDailyRollupJob

**Files:**
- Create: `tests/Feature/Domain/Pricing/BuildRevenueDailyRollupJobTest.php`

- [ ] **Step 1: Create the test file**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Pricing;

use App\Domain\Pricing\Models\RevenueDailyRollup;
use App\Jobs\Pricing\BuildRevenueDailyRollupJob;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BuildRevenueDailyRollupJobTest extends TestCase
{
    public function test_it_aggregates_yesterdays_fee_events_into_rollup(): void
    {
        $yesterday = now()->subDay()->toDateString();

        // Seed two fee_events for yesterday, same product/currency, different segments
        DB::table('fee_events')->insert([
            [
                'transaction_uuid' => null,
                'pricing_rule_id'  => null,
                'product_code'     => 'p2p_send',
                'category'         => 'transfer',
                'user_id'          => 101,
                'segment_id'       => 1,
                'amount_minor'     => 500,
                'currency'         => 'SZL',
                'breakdown'        => '{}',
                'assessed_at'      => $yesterday . ' 10:00:00',
                'idempotency_key'  => 'test-key-1',
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'transaction_uuid' => null,
                'pricing_rule_id'  => null,
                'product_code'     => 'p2p_send',
                'category'         => 'transfer',
                'user_id'          => 102,
                'segment_id'       => 1,
                'amount_minor'     => 300,
                'currency'         => 'SZL',
                'breakdown'        => '{}',
                'assessed_at'      => $yesterday . ' 14:00:00',
                'idempotency_key'  => 'test-key-2',
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'transaction_uuid' => null,
                'pricing_rule_id'  => null,
                'product_code'     => 'p2p_send',
                'category'         => 'transfer',
                'user_id'          => 103,
                'segment_id'       => null,
                'amount_minor'     => 200,
                'currency'         => 'SZL',
                'breakdown'        => '{}',
                'assessed_at'      => $yesterday . ' 16:00:00',
                'idempotency_key'  => 'test-key-3',
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
        ]);

        (new BuildRevenueDailyRollupJob())->handle();

        // Segment 1 row: sum=800, count=2, unique_users=2, avg=400
        $seg1 = RevenueDailyRollup::where('date', $yesterday)
            ->where('product_code', 'p2p_send')
            ->where('segment_id', 1)
            ->where('currency', 'SZL')
            ->firstOrFail();

        $this->assertSame(800, $seg1->gross_revenue_minor);
        $this->assertSame(2, $seg1->fee_count);
        $this->assertSame(2, $seg1->unique_users);
        $this->assertSame(400, $seg1->avg_fee_minor);

        // Null-segment row: sum=200, count=1, unique_users=1, avg=200
        $segNull = RevenueDailyRollup::where('date', $yesterday)
            ->where('product_code', 'p2p_send')
            ->whereNull('segment_id')
            ->where('currency', 'SZL')
            ->firstOrFail();

        $this->assertSame(200, $segNull->gross_revenue_minor);
        $this->assertSame(1, $segNull->fee_count);
    }

    public function test_rerunning_job_for_same_date_replaces_rollup_not_doubles(): void
    {
        $yesterday = now()->subDay()->toDateString();

        DB::table('fee_events')->insert([
            [
                'transaction_uuid' => null,
                'pricing_rule_id'  => null,
                'product_code'     => 'merchant_qr',
                'category'         => 'payment',
                'user_id'          => 201,
                'segment_id'       => null,
                'amount_minor'     => 1000,
                'currency'         => 'ZAR',
                'breakdown'        => '{}',
                'assessed_at'      => $yesterday . ' 09:00:00',
                'idempotency_key'  => 'test-key-4',
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
        ]);

        (new BuildRevenueDailyRollupJob())->handle();
        (new BuildRevenueDailyRollupJob())->handle(); // second run

        $count = RevenueDailyRollup::where('date', $yesterday)
            ->where('product_code', 'merchant_qr')
            ->where('currency', 'ZAR')
            ->count();

        $this->assertSame(1, $count, 'Re-running the job must not duplicate rollup rows');
    }

    public function test_it_does_nothing_when_no_fee_events(): void
    {
        (new BuildRevenueDailyRollupJob())->handle();

        $this->assertSame(0, RevenueDailyRollup::count());
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails (class not found)**

```bash
DB_CONNECTION=mysql \
DB_HOST=127.0.0.1 \
DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test \
DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
php -d max_execution_time=300 ./vendor/bin/pest tests/Feature/Domain/Pricing/BuildRevenueDailyRollupJobTest.php
```

Expected: FAIL — `Class "App\Jobs\Pricing\BuildRevenueDailyRollupJob" not found`

---

## Task 3: Implement BuildRevenueDailyRollupJob

**Files:**
- Create: `app/Jobs/Pricing/BuildRevenueDailyRollupJob.php`

- [ ] **Step 1: Create the job**

```php
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
            ->selectRaw("
                DATE(assessed_at)         AS `date`,
                product_code,
                segment_id,
                currency,
                SUM(amount_minor)         AS gross_revenue_minor,
                COUNT(*)                  AS fee_count,
                COUNT(DISTINCT user_id)   AS unique_users,
                ROUND(AVG(amount_minor))  AS avg_fee_minor
            ")
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
```

- [ ] **Step 2: Run tests to confirm they pass**

```bash
DB_CONNECTION=mysql \
DB_HOST=127.0.0.1 \
DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test \
DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
php -d max_execution_time=300 ./vendor/bin/pest tests/Feature/Domain/Pricing/BuildRevenueDailyRollupJobTest.php --stop-on-failure
```

Expected: 3 tests PASS

- [ ] **Step 3: Commit**

```bash
git add app/Jobs/Pricing/BuildRevenueDailyRollupJob.php \
        tests/Feature/Domain/Pricing/BuildRevenueDailyRollupJobTest.php
git commit -m "feat(pricing): add BuildRevenueDailyRollupJob with feature tests

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 4: Register Job in Scheduler

**Files:**
- Modify: `routes/console.php`

- [ ] **Step 1: Add the import at the top of the file**

In `routes/console.php`, after the existing `use App\Jobs\Pricing\RefreshSegmentMembershipsJob;` line, add:

```php
use App\Jobs\Pricing\BuildRevenueDailyRollupJob;
```

- [ ] **Step 2: Add the schedule entry**

At the end of `routes/console.php`, before the closing `?>` (or at the end of the file if there is none), add:

```php
Schedule::job(new BuildRevenueDailyRollupJob())
    ->dailyAt('02:15')
    ->withoutOverlapping()
    ->description('Aggregate daily fee revenue rollup from fee_events');
```

- [ ] **Step 3: Verify the schedule list includes the new job**

```bash
php artisan schedule:list | grep BuildRevenue
```

Expected: a line containing `BuildRevenueDailyRollupJob` and `02:15`

- [ ] **Step 4: Commit**

```bash
git add routes/console.php
git commit -m "feat(pricing): schedule BuildRevenueDailyRollupJob at 02:15 daily

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 5: RevenueByCategoryWidget (stacked bar, 90 days)

**Files:**
- Create: `app/Filament/Admin/Widgets/Revenue/RevenueByCategoryWidget.php`

- [ ] **Step 1: Create the widget**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Revenue;

use App\Domain\Pricing\Models\RevenueDailyRollup;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

class RevenueByCategoryWidget extends ChartWidget
{
    protected static ?string $heading = 'Revenue by Product (90 days)';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '420px';

    protected function getData(): array
    {
        $since = now()->subDays(90)->toDateString();

        $rows = RevenueDailyRollup::where('date', '>=', $since)
            ->selectRaw('date, product_code, SUM(gross_revenue_minor) as total')
            ->groupBy('date', 'product_code')
            ->orderBy('date')
            ->get();

        $dateStrings = $rows->pluck('date')
            ->map(fn ($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d)
            ->unique()
            ->sort()
            ->values();

        $labels = $dateStrings->map(fn (string $d) => Carbon::parse($d)->format('M j'))->toArray();

        $products = $rows->pluck('product_code')->unique()->sort()->values();

        $palette = [
            'rgba(99,102,241,0.8)',
            'rgba(34,197,94,0.8)',
            'rgba(251,146,60,0.8)',
            'rgba(236,72,153,0.8)',
            'rgba(20,184,166,0.8)',
            'rgba(234,179,8,0.8)',
            'rgba(239,68,68,0.8)',
            'rgba(168,85,247,0.8)',
        ];

        $datasets = $products->values()->map(function (string $code, int $i) use ($rows, $dateStrings, $palette): array {
            $byDate = $rows->where('product_code', $code)
                ->keyBy(fn ($r) => $r->date instanceof Carbon ? $r->date->toDateString() : (string) $r->date);

            return [
                'label'           => $code,
                'data'            => $dateStrings->map(fn (string $d) => (int) ($byDate->get($d)?->total ?? 0))->toArray(),
                'backgroundColor' => $palette[$i % count($palette)],
                'stack'           => 'revenue',
            ];
        })->toArray();

        return [
            'datasets' => $datasets,
            'labels'   => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => true, 'position' => 'top'],
                'tooltip' => ['mode' => 'index', 'intersect' => false],
            ],
            'scales' => [
                'x' => ['stacked' => true],
                'y' => [
                    'stacked' => true,
                    'title'   => ['display' => true, 'text' => 'Revenue (minor units)'],
                ],
            ],
            'interaction' => ['mode' => 'nearest', 'axis' => 'x', 'intersect' => false],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
```

- [ ] **Step 2: Check for PHP parse errors**

```bash
php -l app/Filament/Admin/Widgets/Revenue/RevenueByCategoryWidget.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: PHPStan on this file**

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse app/Filament/Admin/Widgets/Revenue/RevenueByCategoryWidget.php --memory-limit=2G
```

Expected: `[OK] No errors`

---

## Task 6: RevenueBySegmentWidget (doughnut, 30 days)

**Files:**
- Create: `app/Filament/Admin/Widgets/Revenue/RevenueBySegmentWidget.php`

- [ ] **Step 1: Create the widget**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Revenue;

use App\Domain\Pricing\Models\RevenueDailyRollup;
use App\Domain\Segments\Models\CustomerSegment;
use Filament\Widgets\ChartWidget;

class RevenueBySegmentWidget extends ChartWidget
{
    protected static ?string $heading = 'Revenue by Segment (30 days)';

    protected static ?int $sort = 2;

    protected static ?string $maxHeight = '360px';

    protected int|string|array $columnSpan = 1;

    protected function getData(): array
    {
        $since = now()->subDays(30)->toDateString();

        $segmentNames = CustomerSegment::pluck('name', 'id');

        $rows = RevenueDailyRollup::where('date', '>=', $since)
            ->selectRaw('segment_id, SUM(gross_revenue_minor) as total')
            ->groupBy('segment_id')
            ->orderByDesc('total')
            ->get();

        $labels  = $rows->map(
            fn ($r) => $r->segment_id
                ? ($segmentNames[$r->segment_id] ?? "Segment #{$r->segment_id}")
                : 'Unassigned'
        )->toArray();

        $data = $rows->map(fn ($r) => (int) $r->total)->toArray();

        $palette = [
            'rgba(99,102,241,0.8)',
            'rgba(34,197,94,0.8)',
            'rgba(251,146,60,0.8)',
            'rgba(236,72,153,0.8)',
            'rgba(20,184,166,0.8)',
            'rgba(234,179,8,0.8)',
            'rgba(239,68,68,0.8)',
            'rgba(168,85,247,0.8)',
        ];

        return [
            'datasets' => [
                [
                    'data'            => $data,
                    'backgroundColor' => array_slice($palette, 0, count($data)),
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend'  => ['display' => true, 'position' => 'right'],
                'tooltip' => ['callbacks' => []],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
```

- [ ] **Step 2: Check for PHP parse errors**

```bash
php -l app/Filament/Admin/Widgets/Revenue/RevenueBySegmentWidget.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: PHPStan on this file**

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse app/Filament/Admin/Widgets/Revenue/RevenueBySegmentWidget.php --memory-limit=2G
```

Expected: `[OK] No errors`

---

## Task 7: TargetVsActualWidget (line, 3 months)

`revenue_targets.period_month` is `CHAR(7)` formatted as `YYYY-MM` (e.g. `"2026-05"`).
`revenue_targets.amount` is a major-unit decimal (e.g. `1500.00`).
`revenue_daily_rollups.gross_revenue_minor` is an integer in minor units; divide by 100 to compare.

**Files:**
- Create: `app/Filament/Admin/Widgets/Revenue/TargetVsActualWidget.php`

- [ ] **Step 1: Create the widget**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Revenue;

use App\Domain\Analytics\Models\RevenueTarget;
use App\Domain\Pricing\Models\RevenueDailyRollup;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class TargetVsActualWidget extends ChartWidget
{
    protected static ?string $heading = 'Target vs Actual Revenue (3 months)';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    protected static ?string $maxHeight = '360px';

    protected function getData(): array
    {
        $months = collect([
            now()->subMonths(2)->startOfMonth(),
            now()->subMonths(1)->startOfMonth(),
            now()->startOfMonth(),
        ]);

        $monthKeys = $months->map(fn (Carbon $m) => $m->format('Y-m'));

        // Actual: sum gross_revenue_minor per month, convert minor→major (/100)
        $actuals = RevenueDailyRollup::where('date', '>=', $months->first()->toDateString())
            ->selectRaw("DATE_FORMAT(`date`, '%Y-%m') as `month`, SUM(gross_revenue_minor) as total")
            ->groupBy(DB::raw("DATE_FORMAT(`date`, '%Y-%m')"))
            ->pluck('total', 'month')
            ->map(fn ($v) => round((float) $v / 100, 2));

        // Target: sum amount per YYYY-MM key (amount is already major-unit decimal)
        $targets = RevenueTarget::whereIn('period_month', $monthKeys->toArray())
            ->selectRaw("period_month as `month`, SUM(amount) as total")
            ->groupBy('period_month')
            ->pluck('total', 'month')
            ->map(fn ($v) => round((float) $v, 2));

        $labels         = $monthKeys->map(fn (string $k) => Carbon::parse($k . '-01')->format('M Y'))->toArray();
        $actualData     = $monthKeys->map(fn (string $k) => (float) ($actuals[$k] ?? 0))->toArray();
        $targetData     = $monthKeys->map(fn (string $k) => (float) ($targets[$k] ?? 0))->toArray();

        return [
            'datasets' => [
                [
                    'label'           => 'Actual',
                    'data'            => $actualData,
                    'borderColor'     => 'rgb(34,197,94)',
                    'backgroundColor' => 'rgba(34,197,94,0.1)',
                    'tension'         => 0.3,
                    'fill'            => true,
                ],
                [
                    'label'           => 'Target',
                    'data'            => $targetData,
                    'borderColor'     => 'rgb(251,146,60)',
                    'backgroundColor' => 'rgba(251,146,60,0.1)',
                    'tension'         => 0.3,
                    'borderDash'      => [6, 3],
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend'  => ['display' => true, 'position' => 'top'],
                'tooltip' => ['mode' => 'index', 'intersect' => false],
            ],
            'scales' => [
                'y' => [
                    'title' => ['display' => true, 'text' => 'Revenue (major units)'],
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
```

- [ ] **Step 2: Check for PHP parse errors**

```bash
php -l app/Filament/Admin/Widgets/Revenue/TargetVsActualWidget.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: PHPStan on this file**

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse app/Filament/Admin/Widgets/Revenue/TargetVsActualWidget.php --memory-limit=2G
```

Expected: `[OK] No errors`

---

## Task 8: ScenarioComparisonWidget (table, extends Widget)

`last_run_result` JSON has key `total_gross_revenue_minor` (from `ScenarioMetrics::toArray()`).
90-day actual is summed from `revenue_daily_rollups.gross_revenue_minor`.
Amounts are formatted as major-unit strings for display.

**Files:**
- Create: `app/Filament/Admin/Widgets/Revenue/ScenarioComparisonWidget.php`
- Create: `resources/views/filament/admin/widgets/revenue/scenario-comparison-widget.blade.php`

- [ ] **Step 1: Create the widget class**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Revenue;

use App\Domain\Pricing\Models\PricingScenario;
use App\Domain\Pricing\Models\RevenueDailyRollup;
use Filament\Widgets\Widget;

class ScenarioComparisonWidget extends Widget
{
    protected static string $view = 'filament.admin.widgets.revenue.scenario-comparison-widget';

    protected static ?string $heading = 'Scenario vs Actuals (90 days)';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $actual90Minor = (int) RevenueDailyRollup::where('date', '>=', now()->subDays(90)->toDateString())
            ->sum('gross_revenue_minor');

        $actual90 = number_format($actual90Minor / 100, 2);

        $scenarios = PricingScenario::whereNotNull('last_run_result')
            ->latest('last_run_at')
            ->limit(3)
            ->get();

        $rows = $scenarios->map(function (PricingScenario $scenario) use ($actual90Minor): array {
            /** @var array<string, mixed> $result */
            $result = $scenario->last_run_result ?? [];

            $scenarioMinor = (int) ($result['total_gross_revenue_minor'] ?? 0);
            $deltaMinor    = $scenarioMinor - $actual90Minor;

            return [
                'name'             => $scenario->name,
                'last_run_at'      => $scenario->last_run_at?->format('Y-m-d H:i'),
                'scenario_revenue' => number_format($scenarioMinor / 100, 2),
                'actual_revenue'   => number_format($actual90Minor / 100, 2),
                'delta'            => number_format(abs($deltaMinor) / 100, 2),
                'delta_positive'   => $deltaMinor >= 0,
            ];
        })->toArray();

        return [
            'rows'     => $rows,
            'actual90' => $actual90,
        ];
    }
}
```

- [ ] **Step 2: Create the blade view**

Create `resources/views/filament/admin/widgets/revenue/scenario-comparison-widget.blade.php`:

```blade
<x-filament-widgets::widget>
    <x-filament::section heading="Scenario vs Actuals (90 days)">
        @if(empty($rows))
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No scenarios with results yet. Run a pricing scenario to see comparisons.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <tr>
                            <th class="py-2 pr-4">Scenario</th>
                            <th class="py-2 pr-4 text-right">Last Run</th>
                            <th class="py-2 pr-4 text-right">Scenario Revenue</th>
                            <th class="py-2 pr-4 text-right">90-Day Actual</th>
                            <th class="py-2 text-right">Delta</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($rows as $row)
                            <tr>
                                <td class="py-2 pr-4 font-medium text-gray-900 dark:text-white">
                                    {{ $row['name'] }}
                                </td>
                                <td class="py-2 pr-4 text-right text-gray-500 dark:text-gray-400 text-xs">
                                    {{ $row['last_run_at'] ?? '—' }}
                                </td>
                                <td class="py-2 pr-4 text-right font-mono">
                                    {{ $row['scenario_revenue'] }}
                                </td>
                                <td class="py-2 pr-4 text-right font-mono">
                                    {{ $row['actual_revenue'] }}
                                </td>
                                <td class="py-2 text-right font-mono font-semibold {{ $row['delta_positive'] ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                    {{ $row['delta_positive'] ? '+' : '-' }}{{ $row['delta'] }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
```

- [ ] **Step 3: Check for PHP parse errors**

```bash
php -l app/Filament/Admin/Widgets/Revenue/ScenarioComparisonWidget.php
```

Expected: `No syntax errors detected`

- [ ] **Step 4: PHPStan on this file**

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse app/Filament/Admin/Widgets/Revenue/ScenarioComparisonWidget.php --memory-limit=2G
```

Expected: `[OK] No errors`

---

## Task 9: RevenueDashboard Page

**Files:**
- Create: `app/Filament/Admin/Pages/RevenueDashboard.php`
- Create: `resources/views/filament/admin/pages/revenue-dashboard.blade.php`

- [ ] **Step 1: Create the page class**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\Revenue\RevenueByCategoryWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueBySegmentWidget;
use App\Filament\Admin\Widgets\Revenue\ScenarioComparisonWidget;
use App\Filament\Admin\Widgets\Revenue\TargetVsActualWidget;
use Filament\Pages\Page;

class RevenueDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $view = 'filament.admin.pages.revenue-dashboard';

    protected static ?string $navigationGroup = 'Pricing & Revenue';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Revenue Dashboard';

    protected static ?string $slug = 'revenue-dashboard';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['finance', 'platform_admin']) ?? false;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            RevenueByCategoryWidget::class,
            RevenueBySegmentWidget::class,
            TargetVsActualWidget::class,
            ScenarioComparisonWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 2;
    }
}
```

- [ ] **Step 2: Create the page view**

Create `resources/views/filament/admin/pages/revenue-dashboard.blade.php`:

```blade
<x-filament-panels::page>
</x-filament-panels::page>
```

The `<x-filament-panels::page>` component automatically renders header widgets returned by `getHeaderWidgets()`.

- [ ] **Step 3: Check for PHP parse errors**

```bash
php -l app/Filament/Admin/Pages/RevenueDashboard.php
```

Expected: `No syntax errors detected`

- [ ] **Step 4: PHPStan on this file**

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse app/Filament/Admin/Pages/RevenueDashboard.php --memory-limit=2G
```

Expected: `[OK] No errors`

---

## Task 10: Full PHPStan + PHP-CS-Fixer Pass

- [ ] **Step 1: Run PHP-CS-Fixer on all new/modified files**

```bash
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php \
  app/Domain/Pricing/Models/RevenueDailyRollup.php \
  app/Jobs/Pricing/BuildRevenueDailyRollupJob.php \
  app/Filament/Admin/Widgets/Revenue/RevenueByCategoryWidget.php \
  app/Filament/Admin/Widgets/Revenue/RevenueBySegmentWidget.php \
  app/Filament/Admin/Widgets/Revenue/TargetVsActualWidget.php \
  app/Filament/Admin/Widgets/Revenue/ScenarioComparisonWidget.php \
  app/Filament/Admin/Pages/RevenueDashboard.php \
  routes/console.php
```

- [ ] **Step 2: Run PHPStan on the entire changed surface**

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse \
  app/Domain/Pricing/Models/RevenueDailyRollup.php \
  app/Jobs/Pricing/BuildRevenueDailyRollupJob.php \
  app/Filament/Admin/Widgets/Revenue/ \
  app/Filament/Admin/Pages/RevenueDashboard.php \
  --memory-limit=2G
```

Expected: `[OK] No errors`

- [ ] **Step 3: Run the full test suite to check for regressions**

```bash
DB_CONNECTION=mysql \
DB_HOST=127.0.0.1 \
DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test \
DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
php -d max_execution_time=300 ./vendor/bin/pest --parallel --stop-on-failure
```

Expected: all tests pass

- [ ] **Step 4: Final commit**

```bash
git add \
  app/Filament/Admin/Widgets/Revenue/ \
  app/Filament/Admin/Pages/RevenueDashboard.php \
  resources/views/filament/admin/widgets/revenue/ \
  resources/views/filament/admin/pages/revenue-dashboard.blade.php \
  routes/console.php
git commit -m "feat(revenue): add Revenue Dashboard page with 4 analytics widgets

- RevenueByCategoryWidget: stacked bar, 90 days by product_code
- RevenueBySegmentWidget: doughnut, 30 days by segment
- TargetVsActualWidget: line chart, 3-month target vs actual comparison
- ScenarioComparisonWidget: table, last 3 scenarios vs 90-day actuals
- RevenueDashboard page restricted to finance/platform_admin roles
- Scheduler entry at 02:15 daily

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Self-Review Checklist

- [x] **BuildRevenueDailyRollupJob** — covered in Tasks 2–4 (test-first, then implement, then schedule)
- [x] **RevenueByCategoryWidget** — stacked bar, 90 days, `product_code` + `date` grouping — Task 5
- [x] **RevenueBySegmentWidget** — doughnut, 30 days, segment grouping with "Unassigned" — Task 6
- [x] **TargetVsActualWidget** — line, 3-month target vs actual, correct `period_month` CHAR(7) format — Task 7
- [x] **ScenarioComparisonWidget** — extends `Widget`, not `ChartWidget`, blade view, last 3 scenarios — Task 8
- [x] **RevenueDashboard page** — nav group "Pricing & Revenue", `finance`/`platform_admin` gate — Task 9
- [x] **PHPStan + CS-Fixer** — Task 10
- [x] **NULL segment_id** — handled by delete-then-insert in transaction (documented in job comment)
- [x] **Model updated** — `HasUuids` removed, new fillable/casts — Task 1
- [x] **TDD** — test written before job implementation (Tasks 2→3)
- [x] **No placeholder steps** — all tasks contain actual code
