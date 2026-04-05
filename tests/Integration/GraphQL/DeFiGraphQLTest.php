<?php

declare(strict_types=1);

use App\Domain\DeFi\Models\DeFiPosition;
use App\Models\User;


describe('GraphQL DeFi API', function () {
    it('returns unauthorized without authentication', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ defiPosition(id: "test") { id status } }',
        ]);

        $response->assertOk();
        expect($response->json())->toHaveKey('errors');
    });

    it('queries defi position by id with authentication', function () {
        $user = User::factory()->create();
        $position = DeFiPosition::create([
            'user_id'   => $user->id,
            'protocol'  => 'uniswap',
            'type'      => 'liquidity',
            'status'    => 'active',
            'chain'     => 'ethereum',
            'asset'     => 'ETH/USDC',
            'amount'    => '10.000000000000000000',
            'value_usd' => 25000.00,
            'apy'       => 5.25,
            'opened_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    query ($id: ID!) {
                        defiPosition(id: $id) {
                            id
                            protocol
                            type
                            status
                            asset
                        }
                    }
                ',
                'variables' => ['id' => $position->id],
            ]);

        $response->assertOk();
        $data = $response->json('data.defiPosition');
        expect($data['protocol'])->toBe('uniswap');
        expect($data['status'])->toBe('active');
    });

    it('paginates defi positions', function () {
        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            DeFiPosition::create([
                'user_id'   => $user->id,
                'protocol'  => 'aave',
                'type'      => 'lending',
                'status'    => 'active',
                'chain'     => 'ethereum',
                'asset'     => 'USDC',
                'amount'    => (string) (1000 * ($i + 1)),
                'opened_at' => now(),
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    {
                        defiPositions(first: 10, page: 1) {
                            data {
                                id
                                protocol
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
        $data = $response->json('data.defiPositions');
        expect($data['data'])->toBeArray();
        expect($data['paginatorInfo']['total'])->toBeGreaterThanOrEqual(3);
    });

    it('opens a defi position via mutation', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    mutation ($input: OpenPositionInput!) {
                        openPosition(input: $input) {
                            id
                            protocol
                            type
                            status
                            asset
                        }
                    }
                ',
                'variables' => [
                    'input' => [
                        'protocol' => 'aave',
                        'type'     => 'lending',
                        'chain'    => 'ethereum',
                        'asset'    => 'DAI',
                        'amount'   => 10000.0,
                    ],
                ],
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->not->toHaveKey('errors');
        $data = $response->json('data.openPosition');
        expect($data['status'])->toBe('active');
        expect($data['protocol'])->toBe('aave');
    });
});
