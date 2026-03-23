<?php

declare(strict_types=1);

use App\Domain\VirtualsAgent\DataObjects\AgentOnboardingRequest;
use App\Domain\VirtualsAgent\Enums\AgentStatus;
use App\Domain\VirtualsAgent\Events\VirtualsAgentActivated;
use App\Domain\VirtualsAgent\Events\VirtualsAgentRegistered;
use App\Domain\VirtualsAgent\Events\VirtualsAgentSuspended;
use App\Domain\VirtualsAgent\Models\VirtualsAgentProfile;
use App\Domain\VirtualsAgent\Services\AgentOnboardingService;
use App\Domain\VisaCli\Services\VisaCliSpendingLimitService;
use App\Models\User;
use Illuminate\Support\Facades\Event;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    config(['virtuals-agent.supported_chains' => ['base', 'polygon', 'arbitrum', 'ethereum']]);
    config(['virtuals-agent.default_daily_limit' => 50000]);
    config(['virtuals-agent.default_per_tx_limit' => 10000]);
    config(['virtuals-agent.max_daily_limit' => 10000000]);
    config(['virtuals-agent.max_per_tx_limit' => 1000000]);
    config(['visacli.spending_limits.daily' => 50000]);
    config(['visacli.spending_limits.per_tx' => 10000]);
    config(['visacli.spending_limits.auto_pay' => false]);

    $this->spendingLimitService = new VisaCliSpendingLimitService();
    $this->service = new AgentOnboardingService($this->spendingLimitService);
    $this->user = User::factory()->create();
});

it('onboards an agent successfully', function (): void {
    Event::fake([VirtualsAgentRegistered::class, VirtualsAgentActivated::class]);

    $request = new AgentOnboardingRequest(
        virtualsAgentId: 'test-agent-001',
        employerUserId: $this->user->id,
        agentName: 'Treasury Bot',
        agentDescription: 'Handles daily treasury operations',
        chain: 'base',
        dailyLimitCents: 25000,
        perTxLimitCents: 5000,
    );

    $profile = $this->service->onboardAgent($request);

    expect($profile)->toBeInstanceOf(VirtualsAgentProfile::class)
        ->and($profile->virtuals_agent_id)->toBe('test-agent-001')
        ->and($profile->employer_user_id)->toBe($this->user->id)
        ->and($profile->agent_name)->toBe('Treasury Bot')
        ->and($profile->agent_description)->toBe('Handles daily treasury operations')
        ->and($profile->status)->toBe(AgentStatus::ACTIVE)
        ->and($profile->chain)->toBe('base')
        ->and($profile->trustcert_subject_id)->toBe('agent:test-agent-001:employer:' . $this->user->id)
        ->and($profile->x402_spending_limit_id)->not->toBeNull();

    Event::assertDispatched(VirtualsAgentRegistered::class, function ($event) use ($profile) {
        return $event->agentProfileId === $profile->id
            && $event->virtualsAgentId === 'test-agent-001'
            && $event->employerUserId === $this->user->id;
    });

    Event::assertDispatched(VirtualsAgentActivated::class, function ($event) use ($profile) {
        return $event->agentProfileId === $profile->id
            && $event->virtualsAgentId === 'test-agent-001';
    });
});

it('prevents duplicate agent registration', function (): void {
    $request = new AgentOnboardingRequest(
        virtualsAgentId: 'duplicate-agent',
        employerUserId: $this->user->id,
        agentName: 'Dup Bot',
        chain: 'base',
    );

    $this->service->onboardAgent($request);

    try {
        $this->service->onboardAgent($request);
        $this->fail('Expected RuntimeException was not thrown.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('already registered');
    }
});

it('validates agent ID format rejects special characters', function (): void {
    $request = new AgentOnboardingRequest(
        virtualsAgentId: 'invalid agent!@#',
        employerUserId: $this->user->id,
        agentName: 'Bad Agent',
        chain: 'base',
    );

    try {
        $this->service->onboardAgent($request);
        $this->fail('Expected RuntimeException was not thrown.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Invalid Virtuals agent ID format');
    }
});

it('validates employer exists', function (): void {
    $request = new AgentOnboardingRequest(
        virtualsAgentId: 'orphan-agent',
        employerUserId: 999999,
        agentName: 'Orphan Bot',
        chain: 'base',
    );

    try {
        $this->service->onboardAgent($request);
        $this->fail('Expected RuntimeException was not thrown.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('not found');
    }
});

it('validates chain is supported', function (): void {
    $request = new AgentOnboardingRequest(
        virtualsAgentId: 'chain-agent',
        employerUserId: $this->user->id,
        agentName: 'Chain Bot',
        chain: 'solana',
    );

    try {
        $this->service->onboardAgent($request);
        $this->fail('Expected RuntimeException was not thrown.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Unsupported chain');
    }
});

it('validates spending limits are positive', function (): void {
    $request = new AgentOnboardingRequest(
        virtualsAgentId: 'negative-limit-agent',
        employerUserId: $this->user->id,
        agentName: 'Neg Bot',
        chain: 'base',
        dailyLimitCents: -100,
    );

    try {
        $this->service->onboardAgent($request);
        $this->fail('Expected RuntimeException was not thrown.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Daily limit must be positive');
    }
});

it('validates per-tx limit cannot exceed daily limit', function (): void {
    $request = new AgentOnboardingRequest(
        virtualsAgentId: 'over-limit-agent',
        employerUserId: $this->user->id,
        agentName: 'Over Bot',
        chain: 'base',
        dailyLimitCents: 1000,
        perTxLimitCents: 2000,
    );

    try {
        $this->service->onboardAgent($request);
        $this->fail('Expected RuntimeException was not thrown.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Per-transaction limit cannot exceed daily limit');
    }
});

it('validates daily limit cannot exceed system maximum', function (): void {
    $request = new AgentOnboardingRequest(
        virtualsAgentId: 'max-limit-agent',
        employerUserId: $this->user->id,
        agentName: 'Max Bot',
        chain: 'base',
        dailyLimitCents: 99999999,
    );

    try {
        $this->service->onboardAgent($request);
        $this->fail('Expected RuntimeException was not thrown.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Daily limit cannot exceed');
    }
});

it('suspends an active agent', function (): void {
    Event::fake([VirtualsAgentRegistered::class, VirtualsAgentActivated::class, VirtualsAgentSuspended::class]);

    $request = new AgentOnboardingRequest(
        virtualsAgentId: 'suspend-agent',
        employerUserId: $this->user->id,
        agentName: 'Suspend Bot',
        chain: 'base',
    );

    $profile = $this->service->onboardAgent($request);
    expect($profile->status)->toBe(AgentStatus::ACTIVE);

    $result = $this->service->suspendAgent($profile->id, 'Policy violation');
    expect($result)->toBeTrue();

    $profile->refresh();
    expect($profile->status)->toBe(AgentStatus::SUSPENDED);

    Event::assertDispatched(VirtualsAgentSuspended::class, function ($event) use ($profile) {
        return $event->agentProfileId === $profile->id
            && $event->reason === 'Policy violation';
    });
});

it('cannot suspend a deactivated agent', function (): void {
    $request = new AgentOnboardingRequest(
        virtualsAgentId: 'deactivated-agent',
        employerUserId: $this->user->id,
        agentName: 'Deactivated Bot',
        chain: 'base',
    );

    $profile = $this->service->onboardAgent($request);
    $profile->update(['status' => AgentStatus::DEACTIVATED]);

    $result = $this->service->suspendAgent($profile->id, 'Attempted suspension');
    expect($result)->toBeFalse();
});

it('returns null for non-existent agent', function (): void {
    $result = $this->service->getAgentProfile('non-existent-agent-id');
    expect($result)->toBeNull();
});
