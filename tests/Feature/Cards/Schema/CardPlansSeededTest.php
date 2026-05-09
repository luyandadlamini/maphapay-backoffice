<?php

declare(strict_types=1);

namespace Tests\Feature\Cards\Schema;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

class CardPlansSeededTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->markTestSkipped('Database connection not available: ' . $e->getMessage());
        }

        if (! DB::getSchemaBuilder()->hasTable('card_plans')) {
            $this->markTestSkipped('card_plans table does not exist — run migrations first.');
        }
    }

    #[Test]
    public function it_has_exactly_six_plans(): void
    {
        $this->assertSame(6, (int) DB::table('card_plans')->count());
    }

    #[Test]
    public function it_has_free_wallet_plan_with_correct_values(): void
    {
        $plan = DB::table('card_plans')->where('code', 'FREE_WALLET')->first();

        $this->assertNotNull($plan, 'FREE_WALLET plan must exist');
        $this->assertSame('Free Wallet', $plan->name);
        $this->assertSame('0.00', $plan->monthly_fee);
        $this->assertSame(0, (int) $plan->max_virtual_cards);
        $this->assertSame(0, (int) $plan->max_physical_cards);
        $this->assertSame(0, (int) $plan->fx_markup_bps);
        $this->assertSame('adult', $plan->eligibility);
        $this->assertTrue((bool) $plan->active);
        $this->assertFalse((bool) $plan->atm_enabled);
    }

    #[Test]
    public function it_has_virtual_lite_plan_with_correct_values(): void
    {
        $plan = DB::table('card_plans')->where('code', 'VIRTUAL_LITE')->first();

        $this->assertNotNull($plan, 'VIRTUAL_LITE plan must exist');
        $this->assertSame('25.00', $plan->monthly_fee);
        $this->assertSame(1, (int) $plan->max_virtual_cards);
        $this->assertSame(0, (int) $plan->max_physical_cards);
        $this->assertSame('1500.00', $plan->single_transaction_limit);
        $this->assertSame('1500.00', $plan->daily_card_spend_limit);
        $this->assertSame('3000.00', $plan->monthly_card_spend_limit);
        $this->assertSame(350, (int) $plan->fx_markup_bps);
        $this->assertSame('15.00', $plan->virtual_card_replacement_fee);
        $this->assertFalse((bool) $plan->atm_enabled);
        $this->assertSame('adult', $plan->eligibility);
    }

    #[Test]
    public function it_has_virtual_plus_plan_with_correct_values(): void
    {
        $plan = DB::table('card_plans')->where('code', 'VIRTUAL_PLUS')->first();

        $this->assertNotNull($plan, 'VIRTUAL_PLUS plan must exist');
        $this->assertSame('50.00', $plan->monthly_fee);
        $this->assertSame(3, (int) $plan->max_virtual_cards);
        $this->assertSame('5000.00', $plan->single_transaction_limit);
        $this->assertSame('7500.00', $plan->daily_card_spend_limit);
        $this->assertSame('15000.00', $plan->monthly_card_spend_limit);
        $this->assertSame(300, (int) $plan->fx_markup_bps);
        $this->assertSame(1, (int) $plan->free_virtual_reissues_per_month);
        $this->assertFalse((bool) $plan->atm_enabled);
        $this->assertSame('20.00', $plan->virtual_card_replacement_fee);
    }

    #[Test]
    public function it_has_physical_card_plan_with_correct_values(): void
    {
        $plan = DB::table('card_plans')->where('code', 'PHYSICAL_CARD')->first();

        $this->assertNotNull($plan, 'PHYSICAL_CARD plan must exist');
        $this->assertSame('65.00', $plan->monthly_fee);
        $this->assertSame(3, (int) $plan->max_virtual_cards);
        $this->assertSame(1, (int) $plan->max_physical_cards);
        $this->assertSame('7500.00', $plan->single_transaction_limit);
        $this->assertSame('10000.00', $plan->daily_card_spend_limit);
        $this->assertSame('25000.00', $plan->monthly_card_spend_limit);
        $this->assertTrue((bool) $plan->atm_enabled);
        $this->assertSame('1500.00', $plan->atm_daily_limit);
        $this->assertSame('5000.00', $plan->atm_monthly_limit);
        $this->assertSame('12.00', $plan->atm_fixed_fee);
        $this->assertSame(150, (int) $plan->atm_percentage_fee_bps);
        $this->assertSame(275, (int) $plan->fx_markup_bps);
        $this->assertSame('120.00', $plan->physical_card_issuance_fee);
        $this->assertSame('90.00', $plan->physical_card_replacement_fee);
    }

    #[Test]
    public function it_has_premium_card_plan_with_correct_values(): void
    {
        $plan = DB::table('card_plans')->where('code', 'PREMIUM_CARD')->first();

        $this->assertNotNull($plan, 'PREMIUM_CARD plan must exist');
        $this->assertSame('120.00', $plan->monthly_fee);
        $this->assertSame(5, (int) $plan->max_virtual_cards);
        $this->assertSame(1, (int) $plan->max_physical_cards);
        $this->assertSame('15000.00', $plan->single_transaction_limit);
        $this->assertSame('25000.00', $plan->daily_card_spend_limit);
        $this->assertSame('60000.00', $plan->monthly_card_spend_limit);
        $this->assertTrue((bool) $plan->atm_enabled);
        $this->assertSame('3000.00', $plan->atm_daily_limit);
        $this->assertSame('10000.00', $plan->atm_monthly_limit);
        $this->assertSame('8.00', $plan->atm_fixed_fee);
        $this->assertSame(100, (int) $plan->atm_percentage_fee_bps);
        $this->assertSame(175, (int) $plan->fx_markup_bps);
        $this->assertSame('0.00', $plan->physical_card_issuance_fee);
        $this->assertSame('60.00', $plan->physical_card_replacement_fee);
        $this->assertSame(2, (int) $plan->free_virtual_reissues_per_month);
        $this->assertSame('adult', $plan->eligibility);
    }

    #[Test]
    public function it_has_minor_khula_card_plan_with_correct_values(): void
    {
        $plan = DB::table('card_plans')->where('code', 'MINOR_KHULA_CARD')->first();

        $this->assertNotNull($plan, 'MINOR_KHULA_CARD plan must exist');
        $this->assertSame('Khula', $plan->name);
        $this->assertSame('20.00', $plan->monthly_fee);
        $this->assertSame(1, (int) $plan->max_virtual_cards);
        $this->assertSame(0, (int) $plan->max_physical_cards);
        $this->assertSame('500.00', $plan->single_transaction_limit);
        $this->assertSame('500.00', $plan->daily_card_spend_limit);
        $this->assertSame('2000.00', $plan->monthly_card_spend_limit);
        $this->assertFalse((bool) $plan->atm_enabled);
        $this->assertSame(350, (int) $plan->fx_markup_bps);
        $this->assertSame('15.00', $plan->virtual_card_replacement_fee);
        $this->assertSame('minor', $plan->eligibility);
        $this->assertTrue((bool) $plan->active);
    }
}
