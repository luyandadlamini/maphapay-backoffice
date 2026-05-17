<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing\Services;

use App\Domain\Pricing\Models\FeeEvent;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Services\FeeEventRecorder;
use App\Domain\Pricing\ValueObjects\FeeBreakdown;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FeeEventRecorderTest extends TestCase
{
    private FeeEventRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recorder = new FeeEventRecorder();
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    public function test_record_persists_fee_event_with_correct_fields(): void
    {
        $productId = $this->insertProduct(code: 'p_rec', category: 'local_transfer');
        $ruleId = $this->insertRule(productId: $productId, formula: 'fixed', config: ['fixed_minor' => 500]);
        /** @var PricingRule $rule */
        $rule = PricingRule::find($ruleId);
        $breakdown = new FeeBreakdown(fixedMinor: 500, currency: 'SZL');

        $event = $this->recorder->record('txn-uuid-001', $breakdown, $rule, userId: 42);

        $this->assertInstanceOf(FeeEvent::class, $event);
        $this->assertSame(500, $event->amount_minor);
        $this->assertSame('txn-uuid-001', $event->transaction_uuid);
        $this->assertSame(42, $event->user_id);
        $this->assertSame('SZL', $event->currency);
        $this->assertSame($ruleId, $event->pricing_rule_id);
    }

    public function test_record_is_idempotent_same_uuid_and_rule_returns_same_row(): void
    {
        $productId = $this->insertProduct(code: 'p_idem', category: 'wallet_to_wallet');
        $ruleId = $this->insertRule(productId: $productId, formula: 'fixed', config: ['fixed_minor' => 250]);
        /** @var PricingRule $rule */
        $rule = PricingRule::find($ruleId);
        $breakdown = new FeeBreakdown(fixedMinor: 250, currency: 'SZL');

        $first = $this->recorder->record('same-uuid', $breakdown, $rule, userId: 1);
        $second = $this->recorder->record('same-uuid', $breakdown, $rule, userId: 1);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('fee_events')->where('transaction_uuid', 'same-uuid')->count());
    }

    public function test_record_amount_minor_equals_breakdown_total_minor(): void
    {
        $productId = $this->insertProduct(code: 'p_amt', category: 'bank_transfer');
        $ruleId = $this->insertRule(productId: $productId, formula: 'hybrid', config: ['fixed_minor' => 100, 'bps' => 50]);
        /** @var PricingRule $rule */
        $rule = PricingRule::find($ruleId);
        // 100 fixed + 50 percentage = 150
        $breakdown = new FeeBreakdown(fixedMinor: 100, percentageMinor: 50, currency: 'SZL');

        $event = $this->recorder->record('txn-amt', $breakdown, $rule, userId: 5);

        $this->assertSame($breakdown->totalMinor(), $event->amount_minor);
        $this->assertSame(150, $event->amount_minor);
    }

    public function test_record_stores_breakdown_array(): void
    {
        $productId = $this->insertProduct(code: 'p_brkd', category: 'cash_out');
        $ruleId = $this->insertRule(productId: $productId, formula: 'fixed', config: ['fixed_minor' => 300]);
        /** @var PricingRule $rule */
        $rule = PricingRule::find($ruleId);
        $breakdown = new FeeBreakdown(fixedMinor: 300, percentageMinor: 75, currency: 'SZL');

        $event = $this->recorder->record('txn-brkd', $breakdown, $rule, userId: 9);

        $this->assertIsArray($event->breakdown);
        $this->assertSame(300, $event->breakdown['fixed_minor']);
        $this->assertSame(75, $event->breakdown['percentage_minor']);
        $this->assertSame('SZL', $event->breakdown['currency']);
        $this->assertSame($breakdown->totalMinor(), $event->breakdown['total_minor']);
    }

    public function test_record_sets_product_code_and_category_from_rule_product(): void
    {
        $productId = $this->insertProduct(code: 'cash_out_p', category: 'cash_out');
        $ruleId = $this->insertRule(productId: $productId, formula: 'fixed', config: ['fixed_minor' => 200]);
        /** @var PricingRule $rule */
        $rule = PricingRule::find($ruleId);
        $breakdown = new FeeBreakdown(fixedMinor: 200, currency: 'SZL');

        $event = $this->recorder->record('txn-prod', $breakdown, $rule, userId: 3);

        $this->assertSame('cash_out_p', $event->product_code);
        $this->assertSame('cash_out', $event->category);
    }

    public function test_record_zero_event_stores_zero_amount_minor(): void
    {
        $event = $this->recorder->recordZero(
            transactionUuid: 'zero-txn-001',
            productCode: 'send_money',
            category: 'local_transfer',
            currency: 'SZL',
            userId: 7,
        );

        $this->assertInstanceOf(FeeEvent::class, $event);
        $this->assertSame(0, $event->amount_minor);
        $this->assertSame('zero-txn-001', $event->transaction_uuid);
        $this->assertSame('send_money', $event->product_code);
        $this->assertSame('SZL', $event->currency);
        $this->assertNull($event->pricing_rule_id);
    }

    public function test_record_zero_with_rule_id_stores_rule_reference(): void
    {
        $productId = $this->insertProduct(code: 'p_zr', category: 'airtime');
        $ruleId = $this->insertRule(productId: $productId, formula: 'fixed', config: ['fixed_minor' => 0]);

        $event = $this->recorder->recordZero(
            transactionUuid: 'zero-with-rule',
            productCode: 'p_zr',
            category: 'airtime',
            currency: 'SZL',
            userId: 3,
            ruleId: $ruleId,
        );

        $this->assertSame($ruleId, $event->pricing_rule_id);
    }

    public function test_record_zero_is_idempotent(): void
    {
        $first = $this->recorder->recordZero('zero-idem', 'p_code', 'local_transfer', 'SZL', 1);
        $second = $this->recorder->recordZero('zero-idem', 'p_code', 'local_transfer', 'SZL', 1);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('fee_events')->where('transaction_uuid', 'zero-idem')->count());
    }

    public function test_record_optional_source_domain_and_experiment_arm(): void
    {
        $productId = $this->insertProduct(code: 'p_opt', category: 'merchant_payment');
        $ruleId = $this->insertRule(productId: $productId, formula: 'fixed', config: ['fixed_minor' => 100]);
        /** @var PricingRule $rule */
        $rule = PricingRule::find($ruleId);
        $breakdown = new FeeBreakdown(fixedMinor: 100, currency: 'SZL');

        $event = $this->recorder->record(
            transactionUuid: 'txn-opt',
            breakdown: $breakdown,
            rule: $rule,
            userId: 2,
            sourceDomain: 'send_money',
            experimentArm: 'arm_b',
        );

        $this->assertSame('send_money', $event->source_domain);
        $this->assertSame('arm_b', $event->experiment_arm);
    }

    // ---------- helpers ----------

    /** @param array<string, mixed> $config */
    private function insertRule(
        int $productId,
        string $formula,
        array $config,
        ?int $segmentId = null,
        int $priority = 0,
        string $status = 'active',
    ): int {
        return (int) DB::table('pricing_rules')->insertGetId([
            'product_id' => $productId,
            'segment_id' => $segmentId,
            'name'       => 'rule-' . uniqid(),
            'formula'    => $formula,
            'config'     => json_encode($config),
            'priority'   => $priority,
            'status'     => $status,
            'version'    => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertProduct(string $code, string $category): int
    {
        return (int) DB::table('pricing_products')->insertGetId([
            'code'             => $code,
            'name'             => $code,
            'category'         => $category,
            'default_currency' => 'SZL',
            'active'           => true,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }
}
