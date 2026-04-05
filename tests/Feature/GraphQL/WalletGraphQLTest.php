<?php

declare(strict_types=1);

use App\Domain\Wallet\Models\MultiSigWallet;
use App\Models\User;


describe('GraphQL Wallet API', function () {
    it('queries wallet by id', function () {
        $user = User::factory()->create();
        $wallet = MultiSigWallet::create([
            'user_id'             => $user->id,
            'name'                => 'Test Wallet',
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
                        }
                    }
                ',
                'variables' => ['id' => $wallet->id],
            ]);

        $response->assertOk();
        $data = $response->json('data.wallet');
        expect($data)->not->toBeNull();
        expect($data['name'])->toBe('Test Wallet');
        expect($data['chain'])->toBe('ethereum');
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
                            }
                            paginatorInfo {
                                total
                                currentPage
                            }
                        }
                    }
                ',
            ]);

        $response->assertOk();
        $paginator = $response->json('data.wallets');
        expect($paginator['data'])->toHaveCount(3);
        expect($paginator['paginatorInfo']['total'])->toBeGreaterThanOrEqual(3);
    });

    it('rejects unauthenticated wallet queries', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ wallet(id: 1) { id name } }',
        ]);

        $response->assertOk();
        expect($response->json())->toHaveKey('errors');
    });
});
