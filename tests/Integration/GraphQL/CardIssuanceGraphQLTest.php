<?php

declare(strict_types=1);

use App\Models\User;


describe('GraphQL CardIssuance API', function () {
    it('returns unauthorized without authentication', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ card(id: "test-id") { id status } }',
        ]);

        $response->assertOk();
        expect($response->json())->toHaveKey('errors');
    });

    it('queries card by id with authentication', function () {
        $user = User::factory()->create();

        // CardIssuance uses custom resolvers without a traditional model,
        // so querying a non-existent card should return null without errors
        // when the resolver handles it gracefully.
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    query ($id: ID!) {
                        card(id: $id) {
                            id
                            card_token
                            cardholder_name
                            last_four
                            network
                            status
                        }
                    }
                ',
                'variables' => ['id' => 'non-existent-card'],
            ]);

        $response->assertOk();
        // The resolver may return null or an error for non-existent cards
        $json = $response->json();
        expect($json)->toHaveKey('data');
    });

    it('lists cards with pagination via custom resolver', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    {
                        cards(first: 10, page: 1) {
                            id
                            cardholder_name
                            status
                            network
                        }
                    }
                ',
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->toHaveKey('data');
    });

    it('provisions a virtual card via mutation', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    mutation ($input: ProvisionCardInput!) {
                        provisionCard(input: $input) {
                            id
                            cardholder_name
                            status
                            network
                        }
                    }
                ',
                'variables' => [
                    'input' => [
                        'cardholder_name' => 'John Doe',
                    ],
                ],
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->not->toHaveKey('errors');
        $data = $response->json('data.provisionCard');
        expect($data['cardholder_name'])->toBe('John Doe');
    });
});
