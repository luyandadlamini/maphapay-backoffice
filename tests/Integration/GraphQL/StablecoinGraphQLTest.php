<?php

declare(strict_types=1);

use App\Domain\Stablecoin\Models\StablecoinReserve;
use App\Models\User;
use Illuminate\Support\Str;

describe('GraphQL Stablecoin API', function () {
    it('returns unauthorized without authentication', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ stablecoinReserve(id: 1) { id status } }',
        ]);

        $response->assertOk();
        expect($response->json())->toHaveKey('errors');
    });

    it('queries stablecoin reserve by id with authentication', function () {
        $user = User::factory()->create();
        $reserve = StablecoinReserve::create([
            'reserve_id'      => Str::uuid()->toString(),
            'pool_id'         => 'pool-1',
            'stablecoin_code' => 'USDC',
            'asset_code'      => 'USD',
            'amount'          => '1000000.000000000000000000',
            'value_usd'       => '1000000.00000000',
            'custodian_type'  => 'bank',
            'status'          => 'active',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    query ($id: ID!) {
                        stablecoinReserve(id: $id) {
                            id
                            stablecoin_code
                            status
                            amount
                        }
                    }
                ',
                'variables' => ['id' => $reserve->id],
            ]);

        $response->assertOk();
        $data = $response->json('data.stablecoinReserve');
        expect($data['stablecoin_code'])->toBe('USDC');
        expect($data['status'])->toBe('active');
    });

    it('paginates stablecoin reserves', function () {
        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            StablecoinReserve::create([
                'reserve_id'      => Str::uuid()->toString(),
                'pool_id'         => 'pool-' . $i,
                'stablecoin_code' => 'USDC',
                'asset_code'      => 'USD',
                'amount'          => '100000.000000000000000000',
                'value_usd'       => '100000.00000000',
                'custodian_type'  => 'bank',
                'status'          => 'active',
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    {
                        stablecoinReserves(first: 10, page: 1) {
                            data {
                                id
                                stablecoin_code
                                status
                            }
                            paginatorInfo {
                                total
                            }
                        }
                    }
                ',
            ]);

        $response->assertOk();
        $data = $response->json('data.stablecoinReserves');
        expect($data['data'])->toBeArray();
        expect($data['paginatorInfo']['total'])->toBeGreaterThanOrEqual(3);
    });

    it('mints stablecoin via mutation', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    mutation ($input: MintStablecoinInput!) {
                        mintStablecoin(input: $input) {
                            id
                            stablecoin_code
                            status
                            amount
                        }
                    }
                ',
                'variables' => [
                    'input' => [
                        'stablecoin_code' => 'USDT',
                        'amount'          => 500000.0,
                        'pool_id'         => 'pool-mint-1',
                    ],
                ],
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->not->toHaveKey('errors');
        $data = $response->json('data.mintStablecoin');
        expect($data['stablecoin_code'])->toBe('USDT');
        expect($data['status'])->toBe('active');
    });
});
