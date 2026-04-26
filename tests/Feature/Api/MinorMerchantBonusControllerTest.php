<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\MerchantPartner;
use Tests\TestCase;

class MinorMerchantBonusControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MerchantPartner::query()->delete();
    }

    public function test_internal_bonus_endpoint_requires_api_key(): void
    {
        $response = $this->postJson('/api/internal/minor-merchant-bonus/award', [
            'transaction_uuid'    => 'trx-123',
            'merchant_partner_id' => 1,
            'minor_account_uuid'  => 'minor-123',
            'amount_szl'          => 25.00,
        ]);

        $response->assertStatus(401);
    }

    public function test_internal_bonus_endpoint_awards_points(): void
    {
        $partner = MerchantPartner::create([
            'name'                 => 'Test Store',
            'category'             => 'grocery',
            'is_active'            => true,
            'is_active_for_minors' => true,
            'bonus_multiplier'     => 2.0,
        ]);

        $response = $this->postJson('/api/internal/minor-merchant-bonus/award', [
            'transaction_uuid'    => 'trx-new',
            'merchant_partner_id' => $partner->id,
            'minor_account_uuid'  => 'minor-123',
            'amount_szl'          => 25.00,
        ], ['X-Internal-Api-Key' => config('app.internal_api_key')]);

        $response->assertStatus(200)
            ->assertJsonPath('data.bonus_points_awarded', 5);
    }
}
