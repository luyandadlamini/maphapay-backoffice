<?php

declare(strict_types=1);

use App\Domain\VirtualsAgent\DataObjects\AgdpMetrics;
use App\Domain\VirtualsAgent\Enums\AgentStatus;
use App\Domain\VirtualsAgent\Models\VirtualsAgentProfile;
use App\Domain\VirtualsAgent\Services\AgdpReportingService;
use App\Domain\VisaCli\Models\VisaCliPayment;
use App\Models\User;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $this->service = new AgdpReportingService();
    $this->user = User::factory()->create();
});

it('returns zero metrics when no agents exist', function (): void {
    $metrics = $this->service->getMetrics('24h');

    expect($metrics)->toBeInstanceOf(AgdpMetrics::class)
        ->and($metrics->totalPaymentsCents)->toBe(0)
        ->and($metrics->totalTransactions)->toBe(0)
        ->and($metrics->activeAgents)->toBe(0)
        ->and($metrics->totalAgents)->toBe(0)
        ->and($metrics->period)->toBe('24h');
});

it('counts active agents correctly', function (): void {
    VirtualsAgentProfile::create([
        'virtuals_agent_id' => 'active-agent-1',
        'employer_user_id'  => $this->user->id,
        'agent_name'        => 'Active Bot 1',
        'status'            => AgentStatus::ACTIVE,
        'chain'             => 'base',
    ]);

    VirtualsAgentProfile::create([
        'virtuals_agent_id' => 'active-agent-2',
        'employer_user_id'  => $this->user->id,
        'agent_name'        => 'Active Bot 2',
        'status'            => AgentStatus::ACTIVE,
        'chain'             => 'base',
    ]);

    VirtualsAgentProfile::create([
        'virtuals_agent_id' => 'suspended-agent-1',
        'employer_user_id'  => $this->user->id,
        'agent_name'        => 'Suspended Bot',
        'status'            => AgentStatus::SUSPENDED,
        'chain'             => 'base',
    ]);

    $metrics = $this->service->getMetrics('24h');

    expect($metrics->activeAgents)->toBe(2)
        ->and($metrics->totalAgents)->toBe(3);
});

it('aggregates payment amounts for reporting period', function (): void {
    VirtualsAgentProfile::create([
        'virtuals_agent_id' => 'pay-agent',
        'employer_user_id'  => $this->user->id,
        'agent_name'        => 'Pay Bot',
        'status'            => AgentStatus::ACTIVE,
        'chain'             => 'base',
    ]);

    VisaCliPayment::create([
        'agent_id'          => 'pay-agent',
        'url'               => 'https://api.example.com/a',
        'amount_cents'      => 1000,
        'currency'          => 'USD',
        'status'            => 'completed',
        'payment_reference' => 'ref_agdp_1',
    ]);

    VisaCliPayment::create([
        'agent_id'          => 'pay-agent',
        'url'               => 'https://api.example.com/b',
        'amount_cents'      => 2500,
        'currency'          => 'USD',
        'status'            => 'completed',
        'payment_reference' => 'ref_agdp_2',
    ]);

    $metrics = $this->service->getMetrics('24h');

    expect($metrics->totalPaymentsCents)->toBe(3500)
        ->and($metrics->totalTransactions)->toBe(2);
});

it('returns per-agent contribution', function (): void {
    VirtualsAgentProfile::create([
        'virtuals_agent_id' => 'contrib-agent',
        'employer_user_id'  => $this->user->id,
        'agent_name'        => 'Contrib Bot',
        'status'            => AgentStatus::ACTIVE,
        'chain'             => 'base',
    ]);

    VisaCliPayment::create([
        'agent_id'          => 'contrib-agent',
        'url'               => 'https://api.example.com/x',
        'amount_cents'      => 750,
        'currency'          => 'USD',
        'status'            => 'completed',
        'payment_reference' => 'ref_contrib_1',
    ]);

    VisaCliPayment::create([
        'agent_id'          => 'contrib-agent',
        'url'               => 'https://api.example.com/y',
        'amount_cents'      => 1250,
        'currency'          => 'USD',
        'status'            => 'completed',
        'payment_reference' => 'ref_contrib_2',
    ]);

    $contribution = $this->service->getAgentContribution('contrib-agent');

    expect($contribution)->toBeArray()
        ->and($contribution['agent_id'])->toBe('contrib-agent')
        ->and($contribution['agent_name'])->toBe('Contrib Bot')
        ->and($contribution['status'])->toBe('active')
        ->and($contribution['total_payments'])->toBe(2)
        ->and($contribution['total_amount_cents'])->toBe(2000);
});
