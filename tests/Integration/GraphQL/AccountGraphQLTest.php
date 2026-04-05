<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Models\User;
use Illuminate\Support\Str;


describe('GraphQL Account API', function () {
    it('returns unauthorized without authentication', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ account(id: 1) { id name } }',
        ]);

        $response->assertOk();
        expect($response->json())->toHaveKey('errors');
    });

    it('queries account by id with authentication', function () {
        $user = User::factory()->create();
        $account = Account::create([
            'uuid'      => Str::uuid()->toString(),
            'name'      => 'Test Savings Account',
            'balance'   => 0,
            'frozen'    => false,
            'user_uuid' => $user->uuid,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    query ($id: ID!) {
                        account(id: $id) {
                            id
                            name
                            balance
                            frozen
                        }
                    }
                ',
                'variables' => ['id' => $account->id],
            ]);

        $response->assertOk();
        $data = $response->json('data.account');
        expect($data['name'])->toBe('Test Savings Account');
        expect($data['frozen'])->toBeFalse();
    });

    it('paginates accounts', function () {
        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            Account::create([
                'uuid'      => Str::uuid()->toString(),
                'name'      => "Account {$i}",
                'balance'   => 0,
                'frozen'    => false,
                'user_uuid' => $user->uuid,
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    {
                        accounts(first: 10, page: 1) {
                            data {
                                id
                                name
                                balance
                            }
                            paginatorInfo {
                                total
                            }
                        }
                    }
                ',
            ]);

        $response->assertOk();
        $data = $response->json('data.accounts');
        expect($data['data'])->toBeArray();
        expect($data['paginatorInfo']['total'])->toBeGreaterThanOrEqual(3);
    });

    it('creates an account via mutation', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    mutation ($input: CreateAccountInput!) {
                        createAccount(input: $input) {
                            id
                            name
                            balance
                            frozen
                        }
                    }
                ',
                'variables' => [
                    'input' => [
                        'name' => 'New Investment Account',
                    ],
                ],
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->not->toHaveKey('errors');
        $data = $response->json('data.createAccount');
        expect($data['name'])->toBe('New Investment Account');
        expect($data['frozen'])->toBeFalse();
    });
});
