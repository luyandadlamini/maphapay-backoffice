<?php

declare(strict_types=1);

use App\Models\User;


describe('GraphQL MobilePayment API', function () {
    it('rejects unauthenticated payment intent queries', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ paymentIntents(first: 10) { data { id } } }',
        ]);

        $response->assertOk();
        expect($response->json())->toHaveKey('errors');
    });

    it('queries payment intents with authentication', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '{ paymentIntents(first: 10) { data { id public_id asset status } paginatorInfo { total } } }',
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->not->toHaveKey('errors');
        expect($response->json('data.paymentIntents.paginatorInfo.total'))->toBe(0);
    });
});
