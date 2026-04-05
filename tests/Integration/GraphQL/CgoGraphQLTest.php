<?php

declare(strict_types=1);

use App\Domain\Cgo\Models\CgoInvestment;
use App\Domain\Cgo\Models\CgoPricingRound;
use App\Models\User;
use Illuminate\Support\Str;

describe('GraphQL CGO API', function () {
    it('returns unauthorized without authentication', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ investment(id: 1) { id status } }',
        ]);

        $response->assertOk();
        expect($response->json())->toHaveKey('errors');
    });

    it('queries investment by id with authentication', function () {
        $user = User::factory()->create();
        $round = CgoPricingRound::create([
            'round_number'         => 1,
            'name'                 => 'Seed Round',
            'share_price'          => 10.0000,
            'max_shares_available' => 10000.0000,
            'shares_sold'          => 0.0000,
            'total_raised'         => 0.00,
            'is_active'            => true,
        ]);

        $investment = CgoInvestment::create([
            'uuid'                 => Str::uuid()->toString(),
            'user_id'              => $user->id,
            'round_id'             => $round->id,
            'amount'               => 5000.00,
            'currency'             => 'USD',
            'share_price'          => 10.0000,
            'shares_purchased'     => 500.0000,
            'ownership_percentage' => 5.000000,
            'tier'                 => 'silver',
            'status'               => 'pending',
            'payment_method'       => 'stripe',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    query ($id: ID!) {
                        investment(id: $id) {
                            id
                            amount
                            status
                            tier
                            currency
                        }
                    }
                ',
                'variables' => ['id' => $investment->id],
            ]);

        $response->assertOk();
        $data = $response->json('data.investment');
        expect($data['status'])->toBe('pending');
        expect($data['tier'])->toBe('silver');
        expect($data['currency'])->toBe('USD');
    });

    it('paginates investments', function () {
        $user = User::factory()->create();
        $round = CgoPricingRound::create([
            'round_number'         => 1,
            'name'                 => 'Seed Round',
            'share_price'          => 10.0000,
            'max_shares_available' => 10000.0000,
            'shares_sold'          => 0.0000,
            'total_raised'         => 0.00,
            'is_active'            => true,
        ]);

        for ($i = 0; $i < 3; $i++) {
            CgoInvestment::create([
                'uuid'             => Str::uuid()->toString(),
                'user_id'          => $user->id,
                'round_id'         => $round->id,
                'amount'           => 1000.00 + ($i * 500),
                'currency'         => 'USD',
                'share_price'      => 10.0000,
                'shares_purchased' => 100.0000 + ($i * 50),
                'tier'             => 'bronze',
                'status'           => 'pending',
                'payment_method'   => 'stripe',
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    {
                        investments(first: 10, page: 1) {
                            data {
                                id
                                amount
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
        $data = $response->json('data.investments');
        expect($data['data'])->toBeArray();
        expect($data['paginatorInfo']['total'])->toBeGreaterThanOrEqual(3);
    });

    it('creates an investment via mutation', function () {
        $user = User::factory()->create();
        CgoPricingRound::create([
            'round_number'         => 1,
            'name'                 => 'Active Round',
            'share_price'          => 15.0000,
            'max_shares_available' => 5000.0000,
            'shares_sold'          => 0.0000,
            'total_raised'         => 0.00,
            'is_active'            => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    mutation ($input: CreateInvestmentInput!) {
                        createInvestment(input: $input) {
                            id
                            amount
                            status
                            currency
                        }
                    }
                ',
                'variables' => [
                    'input' => [
                        'amount'         => 2500.0,
                        'currency'       => 'USD',
                        'payment_method' => 'stripe',
                    ],
                ],
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->not->toHaveKey('errors');
        $data = $response->json('data.createInvestment');
        expect($data['status'])->toBe('pending');
    });
});
