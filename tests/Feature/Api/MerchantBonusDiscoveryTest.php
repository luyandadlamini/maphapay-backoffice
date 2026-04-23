<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\MerchantPartner;
use App\Models\User;
use Tests\TestCase;

class MerchantBonusDiscoveryTest extends TestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        MerchantPartner::query()->delete();
    }

    public function test_merchants_list_includes_minor_bonus_when_flagged(): void
    {
        MerchantPartner::create([
            'name'                 => 'Test Store',
            'category'             => 'grocery',
            'is_active'            => true,
            'is_active_for_minors' => true,
            'bonus_multiplier'     => 2.0,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/commerce/partners?include_minor_bonus=true');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'display_name',
                        'bonus_multiplier',
                        'min_age_allowance',
                        'is_active_for_minors',
                    ],
                ],
            ]);
    }

    public function test_merchants_list_no_bonus_fields_by_default(): void
    {
        MerchantPartner::create([
            'name'                 => 'Test Store',
            'category'             => 'grocery',
            'is_active'            => true,
            'is_active_for_minors' => true,
            'bonus_multiplier'     => 2.0,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/commerce/partners');

        $response->assertStatus(200)
            ->assertJsonMissing(['bonus_multiplier']);
    }
}
