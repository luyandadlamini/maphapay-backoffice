<?php

declare(strict_types=1);

use App\Domain\CrossChain\Models\BridgeTransaction;
use App\Models\User;


describe('GraphQL CrossChain API', function () {
    it('returns unauthorized without authentication', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ bridgeTransaction(id: "test") { id status } }',
        ]);

        $response->assertOk();
        expect($response->json())->toHaveKey('errors');
    });

    it('queries bridge transaction by id with authentication', function () {
        $user = User::factory()->create();
        $tx = BridgeTransaction::create([
            'user_id'           => $user->id,
            'source_chain'      => 'ethereum',
            'dest_chain'        => 'polygon',
            'token'             => 'USDC',
            'amount'            => '1000.000000000000000000',
            'provider'          => 'wormhole',
            'status'            => 'pending',
            'recipient_address' => '0x1234567890abcdef1234567890abcdef12345678',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    query ($id: ID!) {
                        bridgeTransaction(id: $id) {
                            id
                            source_chain
                            dest_chain
                            token
                            status
                        }
                    }
                ',
                'variables' => ['id' => $tx->id],
            ]);

        $response->assertOk();
        $data = $response->json('data.bridgeTransaction');
        expect($data['token'])->toBe('USDC');
        expect($data['status'])->toBe('pending');
    });

    it('paginates bridge transactions', function () {
        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            BridgeTransaction::create([
                'user_id'           => $user->id,
                'source_chain'      => 'ethereum',
                'dest_chain'        => 'polygon',
                'token'             => 'ETH',
                'amount'            => (string) (1 + $i),
                'provider'          => 'wormhole',
                'status'            => 'pending',
                'recipient_address' => '0xabcdef',
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    {
                        bridgeTransactions(first: 10, page: 1) {
                            data {
                                id
                                source_chain
                                dest_chain
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
        $data = $response->json('data.bridgeTransactions');
        expect($data['data'])->toBeArray();
        expect($data['paginatorInfo']['total'])->toBeGreaterThanOrEqual(3);
    });

    it('initiates bridge transfer via mutation', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    mutation ($input: InitiateBridgeTransferInput!) {
                        initiateBridgeTransfer(input: $input) {
                            id
                            source_chain
                            dest_chain
                            token
                            status
                        }
                    }
                ',
                'variables' => [
                    'input' => [
                        'source_chain'      => 'ethereum',
                        'dest_chain'        => 'arbitrum',
                        'token'             => 'USDC',
                        'amount'            => 5000.0,
                        'recipient_address' => '0xabcdef1234567890abcdef1234567890abcdef12',
                    ],
                ],
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->not->toHaveKey('errors');
        $data = $response->json('data.initiateBridgeTransfer');
        expect($data['status'])->toBe('pending');
        expect($data['token'])->toBe('USDC');
    });
});
