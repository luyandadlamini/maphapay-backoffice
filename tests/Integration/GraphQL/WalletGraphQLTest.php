<?php

declare(strict_types=1);

use App\Domain\Wallet\Models\MultiSigWallet;
use App\Models\User;

describe('GraphQL Wallet API', function () {
    it('returns unauthorized without authentication', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ wallet(id: "test-uuid") { id name } }',
        ]);

        $response->assertOk();
        expect($response->json())->toHaveKey('errors');
    });

    it('queries wallet by id with authentication', function () {
        $user = User::factory()->create();
        $wallet = MultiSigWallet::create([
            'user_id'             => $user->id,
            'name'                => 'My Multi-Sig Wallet',
            'chain'               => 'ethereum',
            'required_signatures' => 2,
            'total_signers'       => 3,
            'status'              => 'active',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    query ($id: ID!) {
                        wallet(id: $id) {
                            id
                            name
                            chain
                            status
                            required_signatures
                            total_signers
                        }
                    }
                ',
                'variables' => ['id' => $wallet->id],
            ]);

        $response->assertOk();
        $data = $response->json('data.wallet');
        expect($data['name'])->toBe('My Multi-Sig Wallet');
        expect($data['chain'])->toBe('ethereum');
        expect($data['status'])->toBe('active');
    });

    it('paginates wallets', function () {
        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            MultiSigWallet::create([
                'user_id'             => $user->id,
                'name'                => "Wallet {$i}",
                'chain'               => 'ethereum',
                'required_signatures' => 2,
                'total_signers'       => 3,
                'status'              => 'active',
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    {
                        wallets(first: 10, page: 1) {
                            data {
                                id
                                name
                                chain
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
        $data = $response->json('data.wallets');
        expect($data['data'])->toBeArray();
        expect($data['paginatorInfo']['total'])->toBeGreaterThanOrEqual(3);
    });

    it('creates a wallet via mutation', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    mutation ($input: CreateWalletInput!) {
                        createWallet(input: $input) {
                            id
                            name
                            chain
                            status
                            required_signatures
                            total_signers
                        }
                    }
                ',
                'variables' => [
                    'input' => [
                        'name'                => 'New Secure Wallet',
                        'chain'               => 'polygon',
                        'required_signatures' => 2,
                        'total_signers'       => 3,
                    ],
                ],
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->not->toHaveKey('errors');
        $data = $response->json('data.createWallet');
        expect($data['name'])->toBe('New Secure Wallet');
        expect($data['chain'])->toBe('polygon');
    });
});
