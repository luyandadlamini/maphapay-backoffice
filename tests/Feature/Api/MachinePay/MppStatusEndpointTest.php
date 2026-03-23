<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('MPP Status Endpoints', function (): void {
    it('returns protocol status', function (): void {
        $response = $this->getJson('/api/v1/mpp/status');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'data' => [
                'enabled',
                'version',
                'available_rails',
                'mcp_enabled',
            ],
        ]);
    });

    it('returns supported rails', function (): void {
        $response = $this->getJson('/api/v1/mpp/supported-rails');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'data',
        ]);
    });

    it('returns well-known configuration', function (): void {
        $response = $this->getJson('/api/.well-known/mpp-configuration');

        $response->assertOk();
        $response->assertJsonStructure([
            'mpp_version',
            'issuer',
            'supported_rails',
            'endpoints',
            'mcp',
        ]);
    });

    it('requires auth for payment history', function (): void {
        $response = $this->getJson('/api/v1/mpp/payments');

        $response->assertUnauthorized();
    });

    it('returns payment stats when authenticated', function (): void {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->getJson('/api/v1/mpp/payments/stats');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'data' => [
                'total_payments',
                'settled',
                'failed',
            ],
        ]);
    });
});
