<?php

declare(strict_types=1);

use App\Domain\VirtualsAgent\Enums\AgentStatus;
use App\Domain\VirtualsAgent\Models\VirtualsAgentProfile;
use App\Domain\VisaCli\Models\VisaCliSpendingLimit;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    config(['visacli.enabled' => true]);
    config(['visacli.spending_limits.daily' => 50000]);
    config(['visacli.spending_limits.per_tx' => 10000]);
    config(['visacli.spending_limits.auto_pay' => false]);
    config(['virtuals-agent.supported_chains' => ['base', 'polygon', 'arbitrum', 'ethereum']]);
    config(['virtuals-agent.default_daily_limit' => 50000]);
    config(['virtuals-agent.default_per_tx_limit' => 10000]);
    config(['virtuals-agent.max_daily_limit' => 10000000]);
    config(['virtuals-agent.max_per_tx_limit' => 1000000]);

    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user, ['read', 'write', 'delete']);
});

it('lists agents for authenticated user', function (): void {
    VirtualsAgentProfile::create([
        'virtuals_agent_id' => 'list-agent-1',
        'employer_user_id'  => $this->user->id,
        'agent_name'        => 'List Bot 1',
        'status'            => AgentStatus::ACTIVE,
        'chain'             => 'base',
    ]);

    VirtualsAgentProfile::create([
        'virtuals_agent_id' => 'list-agent-2',
        'employer_user_id'  => $this->user->id,
        'agent_name'        => 'List Bot 2',
        'status'            => AgentStatus::SUSPENDED,
        'chain'             => 'polygon',
    ]);

    $response = $this->getJson('/api/v1/virtuals-agents');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(2, 'data');
});

it('onboards a new agent via API', function (): void {
    $response = $this->postJson('/api/v1/virtuals-agents/onboard', [
        'virtuals_agent_id'  => 'api-onboard-agent',
        'agent_name'         => 'API Onboard Bot',
        'agent_description'  => 'Created via API test',
        'chain'              => 'base',
        'daily_limit_cents'  => 25000,
        'per_tx_limit_cents' => 5000,
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.virtualsAgentId', 'api-onboard-agent')
        ->assertJsonPath('data.agentName', 'API Onboard Bot')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.chain', 'base');

    $this->assertDatabaseHas('virtuals_agent_profiles', [
        'virtuals_agent_id' => 'api-onboard-agent',
        'employer_user_id'  => $this->user->id,
    ]);
});

it('rejects onboarding with invalid data', function (): void {
    $response = $this->postJson('/api/v1/virtuals-agents/onboard', [
        // Missing required fields
    ]);

    $response->assertStatus(422);
});

it('shows agent with spending summary', function (): void {
    $spendingLimit = VisaCliSpendingLimit::create([
        'agent_id'              => 'show-agent',
        'daily_limit'           => 50000,
        'per_transaction_limit' => 10000,
        'spent_today'           => 0,
        'auto_pay_enabled'      => false,
        'limit_resets_at'       => now()->addDay(),
    ]);

    $profile = VirtualsAgentProfile::create([
        'virtuals_agent_id'      => 'show-agent',
        'employer_user_id'       => $this->user->id,
        'agent_name'             => 'Show Bot',
        'status'                 => AgentStatus::ACTIVE,
        'chain'                  => 'base',
        'x402_spending_limit_id' => $spendingLimit->id,
    ]);

    $response = $this->getJson("/api/v1/virtuals-agents/{$profile->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.profile.virtualsAgentId', 'show-agent')
        ->assertJsonPath('data.profile.agentName', 'Show Bot')
        ->assertJsonStructure([
            'success',
            'data' => [
                'profile'  => ['id', 'virtualsAgentId', 'agentName', 'status'],
                'spending' => ['daily_limit', 'spent_today', 'remaining', 'last_transactions'],
            ],
        ]);
});

it('prevents accessing another users agent', function (): void {
    $otherUser = User::factory()->create();

    $profile = VirtualsAgentProfile::create([
        'virtuals_agent_id' => 'other-user-agent',
        'employer_user_id'  => $otherUser->id,
        'agent_name'        => 'Other Bot',
        'status'            => AgentStatus::ACTIVE,
        'chain'             => 'base',
    ]);

    $response = $this->getJson("/api/v1/virtuals-agents/{$profile->id}");

    $response->assertStatus(403)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

it('suspends an agent', function (): void {
    $profile = VirtualsAgentProfile::create([
        'virtuals_agent_id' => 'suspend-api-agent',
        'employer_user_id'  => $this->user->id,
        'agent_name'        => 'Suspend API Bot',
        'status'            => AgentStatus::ACTIVE,
        'chain'             => 'base',
    ]);

    $response = $this->putJson("/api/v1/virtuals-agents/{$profile->id}/suspend");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.message', 'Agent suspended successfully.');

    $profile->refresh();
    expect($profile->status)->toBe(AgentStatus::SUSPENDED);
});

it('activates a suspended agent', function (): void {
    $profile = VirtualsAgentProfile::create([
        'virtuals_agent_id' => 'activate-api-agent',
        'employer_user_id'  => $this->user->id,
        'agent_name'        => 'Activate API Bot',
        'status'            => AgentStatus::SUSPENDED,
        'chain'             => 'base',
    ]);

    $response = $this->putJson("/api/v1/virtuals-agents/{$profile->id}/activate");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.message', 'Agent activated successfully.');

    $profile->refresh();
    expect($profile->status)->toBe(AgentStatus::ACTIVE);
});

it('returns aGDP metrics', function (): void {
    VirtualsAgentProfile::create([
        'virtuals_agent_id' => 'agdp-agent',
        'employer_user_id'  => $this->user->id,
        'agent_name'        => 'AGDP Bot',
        'status'            => AgentStatus::ACTIVE,
        'chain'             => 'base',
    ]);

    $response = $this->getJson('/api/v1/virtuals-agents/agdp?period=24h');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'totalPaymentsCents',
                'totalTransactions',
                'activeAgents',
                'totalAgents',
                'period',
                'calculatedAt',
            ],
        ])
        ->assertJsonPath('data.activeAgents', 1)
        ->assertJsonPath('data.totalAgents', 1)
        ->assertJsonPath('data.period', '24h');
});
