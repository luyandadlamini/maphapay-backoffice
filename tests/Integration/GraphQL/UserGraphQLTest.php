<?php

declare(strict_types=1);

use App\Domain\User\Models\UserProfile;
use App\Models\User;


describe('GraphQL User API', function () {
    it('returns unauthorized without authentication', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ userProfile { id status } }',
        ]);

        $response->assertOk();
        expect($response->json())->toHaveKey('errors');
    });

    it('queries user profile with authentication', function () {
        $user = User::factory()->create();
        UserProfile::create([
            'user_id'      => $user->id,
            'email'        => $user->email,
            'first_name'   => 'John',
            'last_name'    => 'Doe',
            'phone_number' => '+1234567890',
            'country'      => 'US',
            'city'         => 'New York',
            'status'       => 'active',
            'is_verified'  => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    {
                        userProfile {
                            id
                            email
                            first_name
                            last_name
                            country
                            status
                            is_verified
                        }
                    }
                ',
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->not->toHaveKey('errors');
        $data = $response->json('data.userProfile');
        expect($data['first_name'])->toBe('John');
        expect($data['last_name'])->toBe('Doe');
        expect($data['country'])->toBe('US');
    });

    it('updates user profile via mutation', function () {
        $user = User::factory()->create();
        UserProfile::create([
            'user_id'     => $user->id,
            'email'       => $user->email,
            'first_name'  => 'Jane',
            'last_name'   => 'Smith',
            'country'     => 'CA',
            'city'        => 'Toronto',
            'status'      => 'active',
            'is_verified' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    mutation ($input: UpdateProfileInput!) {
                        updateProfile(input: $input) {
                            id
                            first_name
                            last_name
                            country
                            city
                        }
                    }
                ',
                'variables' => [
                    'input' => [
                        'first_name'   => 'Janet',
                        'last_name'    => 'Johnson',
                        'phone_number' => '+9876543210',
                        'country'      => 'GB',
                        'city'         => 'London',
                    ],
                ],
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->not->toHaveKey('errors');
        $data = $response->json('data.updateProfile');
        expect($data['first_name'])->toBe('Janet');
        expect($data['last_name'])->toBe('Johnson');
        expect($data['country'])->toBe('GB');
    });
});
