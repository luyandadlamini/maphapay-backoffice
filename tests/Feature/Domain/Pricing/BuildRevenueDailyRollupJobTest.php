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
        (new BuildRevenueDailyRollupJob())->handle();

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
