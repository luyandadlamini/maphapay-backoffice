<?php

declare(strict_types=1);

use App\Domain\Treasury\Models\AssetAllocation;
use App\Models\User;


describe('GraphQL Treasury API', function () {
    it('returns unauthorized without authentication', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ portfolio(id: 1) { id asset_class } }',
        ]);

        $response->assertOk();
        expect($response->json())->toHaveKey('errors');
    });

    it('queries portfolio by id with authentication', function () {
        $user = User::factory()->create();
        $allocation = AssetAllocation::create([
            'portfolio_id'   => 'test-portfolio-1',
            'asset_class'    => 'equities',
            'target_weight'  => 60.0,
            'current_weight' => 55.0,
            'drift'          => -5.0,
            'target_amount'  => 60000.00,
            'current_amount' => 55000.00,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    query ($id: ID!) {
                        portfolio(id: $id) {
                            id
                            asset_class
                            target_weight
                            current_weight
                            drift
                        }
                    }
                ',
                'variables' => ['id' => $allocation->id],
            ]);

        $response->assertOk();
        $data = $response->json('data.portfolio');
        expect($data['asset_class'])->toBe('equities');
    });

    it('paginates portfolios', function () {
        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            AssetAllocation::create([
                'portfolio_id'   => 'portfolio-' . $i,
                'asset_class'    => 'asset_' . $i,
                'target_weight'  => 30.0,
                'current_weight' => 28.0,
                'drift'          => -2.0,
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    {
                        portfolios(first: 10, page: 1) {
                            data {
                                id
                                asset_class
                                target_weight
                            }
                            paginatorInfo {
                                total
                            }
                        }
                    }
                ',
            ]);

        $response->assertOk();
        $data = $response->json('data.portfolios');
        expect($data['data'])->toBeArray();
        expect($data['paginatorInfo']['total'])->toBeGreaterThanOrEqual(3);
    });

    it('creates a portfolio via mutation', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    mutation ($input: CreatePortfolioInput!) {
                        createPortfolio(input: $input) {
                            id
                            asset_class
                            target_weight
                        }
                    }
                ',
                'variables' => [
                    'input' => [
                        'asset_class'   => 'fixed_income',
                        'target_weight' => 40.0,
                    ],
                ],
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->not->toHaveKey('errors');
        $data = $response->json('data.createPortfolio');
        expect($data['asset_class'])->toBe('fixed_income');
    });
});
