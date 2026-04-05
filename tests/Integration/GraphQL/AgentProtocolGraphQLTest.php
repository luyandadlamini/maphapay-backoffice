<?php

declare(strict_types=1);

use App\Domain\AgentProtocol\Models\Agent;
use App\Models\User;
use Illuminate\Support\Str;

describe('GraphQL AgentProtocol API', function () {
    it('returns unauthorized without authentication', function () {
        $response = $this->postJson('/graphql', [
            'query' => '{ agent(id: 1) { id name } }',
        ]);

        $response->assertOk();
        expect($response->json())->toHaveKey('errors');
    });

    it('queries agent by id with authentication', function () {
        $user = User::factory()->create();
        $agent = Agent::create([
            'agent_id'     => Str::uuid()->toString(),
            'name'         => 'Test Payment Agent',
            'type'         => 'payment',
            'status'       => 'active',
            'organization' => 'FinAegis',
            'capabilities' => ['payment', 'transfer'],
            'endpoints'    => ['primary' => 'https://agent.example.com/api'],
            'relay_score'  => 95.5,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    query ($id: ID!) {
                        agent(id: $id) {
                            id
                            name
                            status
                            type
                            relay_score
                        }
                    }
                ',
                'variables' => ['id' => $agent->id],
            ]);

        $response->assertOk();
        $data = $response->json('data.agent');
        expect($data['name'])->toBe('Test Payment Agent');
        expect($data['status'])->toBe('active');
    });

    it('paginates agents', function () {
        $user = User::factory()->create();
        for ($i = 0; $i < 3; $i++) {
            Agent::create([
                'agent_id'     => Str::uuid()->toString(),
                'name'         => "Agent {$i}",
                'type'         => 'standard',
                'status'       => 'active',
                'capabilities' => ['relay'],
                'endpoints'    => [],
                'relay_score'  => 80.0 + $i,
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    {
                        agents(first: 10, page: 1) {
                            data {
                                id
                                name
                                status
                                type
                            }
                            paginatorInfo {
                                total
                            }
                        }
                    }
                ',
            ]);

        $response->assertOk();
        $data = $response->json('data.agents');
        expect($data['data'])->toBeArray();
        expect($data['paginatorInfo']['total'])->toBeGreaterThanOrEqual(3);
    });

    it('registers an agent via mutation', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/graphql', [
                'query' => '
                    mutation ($input: RegisterAgentInput!) {
                        registerAgent(input: $input) {
                            id
                            name
                            status
                            type
                        }
                    }
                ',
                'variables' => [
                    'input' => [
                        'name'         => 'New Compliance Agent',
                        'type'         => 'compliance',
                        'capabilities' => ['kyc', 'aml'],
                    ],
                ],
            ]);

        $response->assertOk();
        $json = $response->json();
        expect($json)->not->toHaveKey('errors');
        $data = $response->json('data.registerAgent');
        expect($data['name'])->toBe('New Compliance Agent');
    });
});
