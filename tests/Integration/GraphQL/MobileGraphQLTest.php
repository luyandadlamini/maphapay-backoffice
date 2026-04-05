<?php

declare(strict_types=1);

use App\Domain\Mobile\Models\MobileDevice;
use App\Models\User;


describe('GraphQL Mobile API', function () {
    it('paginates mobile devices', function () {
        $user = User::factory()->create();
        MobileDevice::factory()->count(2)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '{ mobileDevices(first: 10) { data { id platform device_name biometric_enabled } paginatorInfo { total } } }',
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->not->toHaveKey('errors');
        expect($response->json('data.mobileDevices.paginatorInfo.total'))->toBe(2);
    });

    it('rejects unauthenticated mobile queries', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ mobileDevices(first: 10) { data { id } } }',
        ]);

        $response->assertOk();
        expect($response->json())->toHaveKey('errors');
    });
});
