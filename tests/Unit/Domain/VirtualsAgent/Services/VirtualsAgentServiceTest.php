<?php

declare(strict_types=1);

use App\Domain\VirtualsAgent\Enums\AgentStatus;
use App\Domain\VirtualsAgent\Services\AgentOnboardingService;
use App\Domain\VirtualsAgent\Services\VirtualsAgentService;
use App\Domain\VisaCli\DataObjects\VisaCliPaymentResult;
use App\Domain\VisaCli\Enums\VisaCliPaymentStatus;
use App\Domain\VisaCli\Services\VisaCliPaymentService;
use App\Domain\VisaCli\Services\VisaCliSpendingLimitService;
use App\Models\User;

uses(Tests\TestCase::class);

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

    $this->spendingLimitService = new VisaCliSpendingLimitService();
    $this->onboardingService = new AgentOnboardingService($this->spendingLimitService);

    /** @var VisaCliPaymentService&Mockery\MockInterface $paymentService */
    $this->paymentService = Mockery::mock(VisaCliPaymentService::class);

    $this->service = new VirtualsAgentService(
        $this->onboardingService,
        $this->paymentService,
    );
});

it('executes agent payment successfully', function (): void {
    $request = new App\Domain\VirtualsAgent\DataObjects\AgentOnboardingRequest(
        virtualsAgentId: 'payment-agent',
        employerUserId: $this->user->id,
        agentName: 'Payment Bot',
        chain: 'base',
    );

    $this->onboardingService->onboardAgent($request);

    $this->paymentService->shouldReceive('executePayment')
        ->once()
        ->andReturn(new VisaCliPaymentResult(
            paymentReference: 'ref_pay_001',
            status: VisaCliPaymentStatus::COMPLETED,
            amountCents: 500,
            currency: 'USD',
            url: 'https://api.example.com/resource',
        ));

    $result = $this->service->executeAgentPayment(
        virtualsAgentId: 'payment-agent',
        url: 'https://api.example.com/resource',
        amountCents: 500,
    );

    expect($result->paymentReference)->toBe('ref_pay_001')
        ->and($result->status)->toBe(VisaCliPaymentStatus::COMPLETED)
        ->and($result->amountCents)->toBe(500);
});

it('throws when agent not found', function (): void {
    $this->service->executeAgentPayment(
        virtualsAgentId: 'non-existent-agent',
        url: 'https://api.example.com',
        amountCents: 500,
    );
})->throws(RuntimeException::class, 'not found');

it('throws when agent not active', function (): void {
    $request = new App\Domain\VirtualsAgent\DataObjects\AgentOnboardingRequest(
        virtualsAgentId: 'suspended-pay-agent',
        employerUserId: $this->user->id,
        agentName: 'Suspended Pay Bot',
        chain: 'base',
    );

    $profile = $this->onboardingService->onboardAgent($request);
    $profile->update(['status' => AgentStatus::SUSPENDED]);

    $this->service->executeAgentPayment(
        virtualsAgentId: 'suspended-pay-agent',
        url: 'https://api.example.com',
        amountCents: 500,
    );
})->throws(RuntimeException::class, 'not active');

it('returns spending summary with limits and transactions', function (): void {
    $request = new App\Domain\VirtualsAgent\DataObjects\AgentOnboardingRequest(
        virtualsAgentId: 'summary-agent',
        employerUserId: $this->user->id,
        agentName: 'Summary Bot',
        chain: 'base',
        dailyLimitCents: 30000,
    );

    $this->onboardingService->onboardAgent($request);

    $this->paymentService->shouldReceive('getAgentPayments')
        ->once()
        ->with('summary-agent', 10)
        ->andReturn(new Illuminate\Database\Eloquent\Collection());

    $summary = $this->service->getAgentSpendingSummary('summary-agent');

    expect($summary)->toBeArray()
        ->and($summary)->toHaveKeys(['daily_limit', 'spent_today', 'remaining', 'last_transactions'])
        ->and($summary['daily_limit'])->toBe(30000)
        ->and($summary['spent_today'])->toBe(0)
        ->and($summary['remaining'])->toBe(30000)
        ->and($summary['last_transactions'])->toBe([]);
});
