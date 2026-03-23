<?php

declare(strict_types=1);

use App\Domain\VirtualsAgent\Services\AcpServiceProviderBridge;
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

    $this->agentService = new VirtualsAgentService(
        $this->onboardingService,
        $this->paymentService,
    );

    $this->bridge = new AcpServiceProviderBridge(
        $this->agentService,
        $this->onboardingService,
    );

    // Create and onboard a test agent
    $request = new App\Domain\VirtualsAgent\DataObjects\AgentOnboardingRequest(
        virtualsAgentId: 'acp-test-agent',
        employerUserId: $this->user->id,
        agentName: 'ACP Test Bot',
        chain: 'base',
    );
    $this->profile = $this->onboardingService->onboardAgent($request);
});

it('routes card_provisioning jobs', function (): void {
    $result = $this->bridge->handleAcpJob([
        'service_type' => 'card_provisioning',
        'agent_id'     => 'acp-test-agent',
        'job_id'       => 'job-001',
        'action'       => 'create_card',
    ]);

    expect($result['status'])->toBe('accepted')
        ->and($result['agent_id'])->toBe('acp-test-agent')
        ->and($result['message'])->toContain('Card provisioning');
});

it('routes payments jobs', function (): void {
    $this->paymentService->shouldReceive('executePayment')
        ->once()
        ->andReturn(new VisaCliPaymentResult(
            paymentReference: 'ref_acp_pay',
            status: VisaCliPaymentStatus::COMPLETED,
            amountCents: 1000,
            currency: 'USD',
            url: 'https://api.example.com/pay',
        ));

    $result = $this->bridge->handleAcpJob([
        'service_type' => 'payments',
        'agent_id'     => 'acp-test-agent',
        'job_id'       => 'job-002',
        'url'          => 'https://api.example.com/pay',
        'amount_cents' => 1000,
    ]);

    expect($result['status'])->toBe('completed')
        ->and($result['payment_reference'])->toBe('ref_acp_pay')
        ->and($result['amount_cents'])->toBe(1000);
});

it('routes compliance jobs', function (): void {
    $result = $this->bridge->handleAcpJob([
        'service_type' => 'compliance',
        'agent_id'     => 'acp-test-agent',
        'job_id'       => 'job-003',
    ]);

    expect($result['status'])->toBe('accepted')
        ->and($result['agent_id'])->toBe('acp-test-agent')
        ->and($result['trustcert_subject_id'])->not->toBeNull()
        ->and($result['message'])->toContain('Compliance');
});

it('rejects unknown service types', function (): void {
    $this->bridge->handleAcpJob([
        'service_type' => 'unknown_service',
        'agent_id'     => 'acp-test-agent',
        'job_id'       => 'job-004',
    ]);
})->throws(RuntimeException::class, 'Unknown ACP service type');

it('validates agent ID format', function (): void {
    $this->bridge->handleAcpJob([
        'service_type' => 'card_provisioning',
        'agent_id'     => 'invalid!@#agent',
        'job_id'       => 'job-005',
    ]);
})->throws(RuntimeException::class, 'Invalid agent ID format');

it('validates payment URL format', function (): void {
    $result = $this->bridge->handleAcpJob([
        'service_type' => 'payments',
        'agent_id'     => 'acp-test-agent',
        'job_id'       => 'job-006',
        'url'          => 'ftp://not-allowed.example.com',
        'amount_cents' => 500,
    ]);

    expect($result['status'])->toBe('error')
        ->and($result['message'])->toContain('Invalid payment URL');
});

it('returns service catalog with 5 entries', function (): void {
    $catalog = $this->bridge->getServiceCatalog();

    expect($catalog)->toBeArray()
        ->and($catalog)->toHaveCount(5);

    $serviceTypes = array_column($catalog, 'service_type');
    expect($serviceTypes)->toContain('card_provisioning')
        ->and($serviceTypes)->toContain('compliance')
        ->and($serviceTypes)->toContain('payments')
        ->and($serviceTypes)->toContain('shield')
        ->and($serviceTypes)->toContain('ramp');

    foreach ($catalog as $entry) {
        expect($entry)->toHaveKeys(['service_type', 'name', 'description', 'capabilities'])
            ->and($entry['capabilities'])->toBeArray()
            ->and($entry['capabilities'])->not->toBeEmpty();
    }
});
