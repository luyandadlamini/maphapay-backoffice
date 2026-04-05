<?php

declare(strict_types=1);

use App\Domain\FinancialInstitution\Models\FinancialInstitutionPartner;
use App\Models\User;

describe('GraphQL FinancialInstitution API', function () {
    it('returns unauthorized without authentication', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ partner(id: "test-uuid") { id status } }',
        ]);

        $response->assertOk();
        expect($response->json())->toHaveKey('errors');
    });

    it('queries partner by id with authentication', function () {
        $user = User::factory()->create();
        $partner = FinancialInstitutionPartner::create([
            'institution_name'   => 'Test Bank Corp',
            'legal_name'         => 'Test Bank Corporation Ltd',
            'institution_type'   => 'bank',
            'country'            => 'US',
            'status'             => 'active',
            'tier'               => 'enterprise',
            'sandbox_enabled'    => true,
            'production_enabled' => false,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    query ($id: ID!) {
                        partner(id: $id) {
                            id
                            institution_name
                            status
                            tier
                            country
                        }
                    }
                ',
                'variables' => ['id' => $partner->id],
            ]);

        $response->assertOk();
        $data = $response->json('data.partner');
        expect($data['institution_name'])->toBe('Test Bank Corp');
        expect($data['status'])->toBe('active');
        expect($data['tier'])->toBe('enterprise');
    });

    it('paginates partners', function () {
        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            FinancialInstitutionPartner::create([
                'institution_name'   => "Partner Bank {$i}",
                'legal_name'         => "Partner Bank {$i} Ltd",
                'institution_type'   => 'fintech',
                'country'            => 'GB',
                'status'             => 'active',
                'tier'               => 'starter',
                'sandbox_enabled'    => true,
                'production_enabled' => false,
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    {
                        partners(first: 10, page: 1) {
                            data {
                                id
                                institution_name
                                status
                                tier
                            }
                            paginatorInfo {
                                total
                            }
                        }
                    }
                ',
            ]);

        $response->assertOk();
        $data = $response->json('data.partners');
        expect($data['data'])->toBeArray();
        expect($data['paginatorInfo']['total'])->toBeGreaterThanOrEqual(3);
    });

    it('onboards a partner via mutation', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    mutation ($input: OnboardPartnerInput!) {
                        onboardPartner(input: $input) {
                            id
                            institution_name
                            status
                            country
                        }
                    }
                ',
                'variables' => [
                    'input' => [
                        'institution_name' => 'New FinTech Partner',
                        'legal_name'       => 'New FinTech Partner Inc',
                        'institution_type' => 'payment_processor',
                        'country'          => 'DE',
                        'tier'             => 'growth',
                    ],
                ],
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->not->toHaveKey('errors');
        $data = $response->json('data.onboardPartner');
        expect($data['institution_name'])->toBe('New FinTech Partner');
    });
});
