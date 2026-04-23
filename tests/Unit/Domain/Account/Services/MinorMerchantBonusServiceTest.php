<?php

namespace Tests\Unit\Domain\Account\Services;

use App\Domain\Account\Models\MinorMerchantBonusTransaction;
use App\Domain\Account\Services\MinorMerchantBonusService;
use App\Models\MerchantPartner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MinorMerchantBonusServiceTest extends TestCase
{
    private MinorMerchantBonusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MinorMerchantBonusService::class);
        MerchantPartner::unguard();
    }

    public function test_calculate_bonus_points_uses_floor(): void
    {
        $result = $this->service->calculateBonusPoints(15.00, 2.0);
        $this->assertEquals(3, $result);
        
        $result = $this->service->calculateBonusPoints(20.00, 2.0);
        $this->assertEquals(4, $result);
        
        $result = $this->service->calculateBonusPoints(25.00, 2.0);
        $this->assertEquals(5, $result);
    }

    public function test_calculate_bonus_points_caps_at_max_multiplier(): void
    {
        $result = $this->service->calculateBonusPoints(25.00, 6.0);
        $this->assertEquals(5, $result);
    }

    public function test_award_bonus_checks_idempotency(): void
    {
        $partner = MerchantPartner::create([
            'id' => 1,
            'name' => 'Test Merchant',
            'is_active_for_minors' => true,
            'bonus_multiplier' => 2.0,
            'min_age_allowance' => 12,
        ]);

        $result = $this->service->awardBonus(
            'trx-123',
            1,
            'minor-uuid',
            25.00
        );
        $this->assertEquals(5, $result['bonus_points_awarded']);
        
        $result = $this->service->awardBonus(
            'trx-123',
            1,
            'minor-uuid',
            25.00
        );
        $this->assertEquals(0, $result['bonus_points_awarded']);
    }

    public function test_award_bonus_checks_minor_age(): void
    {
        $partner = MerchantPartner::create([
            'id' => 1,
            'name' => 'Test Merchant',
            'is_active_for_minors' => true,
            'bonus_multiplier' => 2.0,
            'min_age_allowance' => 12,
        ]);

        $result = $this->service->awardBonus(
            'trx-new',
            1,
            'minor-uuid',
            25.00,
            10
        );
        $this->assertEquals(0, $result['bonus_points_awarded']);
        
        $result = $this->service->awardBonus(
            'trx-new-2',
            1,
            'minor-uuid',
            25.00,
            14
        );
        $this->assertEquals(5, $result['bonus_points_awarded']);
    }

    public function test_get_bonus_details_returns_correct_structure(): void
    {
        $partner = MerchantPartner::create([
            'id' => 1,
            'name' => 'Test Merchant',
            'is_active_for_minors' => true,
            'bonus_multiplier' => 2.0,
            'min_age_allowance' => 12,
        ]);

        $result = $this->service->getBonusDetails(1);
        
        $this->assertArrayHasKey('bonus_multiplier', $result);
        $this->assertArrayHasKey('min_age_allowance', $result);
        $this->assertArrayHasKey('is_active_for_minors', $result);
        $this->assertArrayHasKey('bonus_terms', $result);
    }
}