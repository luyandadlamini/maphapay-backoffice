<?php

declare(strict_types=1);

use App\Models\User;


describe('GraphQL Authentication', function () {
    it('rejects unauthenticated queries to protected fields', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ account(id: 1) { id name } }',
        ]);

        $response->assertOk();
        $data = $response->json();
        expect($data)->toHaveKey('errors');
    });

    it('accepts authenticated queries', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '{ accounts(first: 1) { data { id } paginatorInfo { total } } }',
            ]);

        $response->assertOk();
        expect($response->json('data.accounts'))->not->toBeNull();
    });

    it('GraphQL endpoint is accessible', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ __typename }',
        ]);

        $response->assertOk();
        expect($response->json('data.__typename'))->toBe('Query');
    });
});
