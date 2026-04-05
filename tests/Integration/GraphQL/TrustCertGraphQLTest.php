<?php

declare(strict_types=1);

use App\Models\User;


describe('GraphQL TrustCert API', function () {
    it('rejects unauthenticated certificate queries', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ certificates(first: 10) { data { id } } }',
        ]);

        $response->assertOk();
        expect($response->json())->toHaveKey('errors');
    });

    it('queries certificates with authentication', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '{ certificates(first: 10) { data { id subject status credential_type } paginatorInfo { total } } }',
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->not->toHaveKey('errors');
        expect($response->json('data.certificates.paginatorInfo.total'))->toBe(0);
    });
});
