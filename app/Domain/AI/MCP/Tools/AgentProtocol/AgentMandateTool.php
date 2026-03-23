<?php

declare(strict_types=1);

namespace App\Domain\AI\MCP\Tools\AgentProtocol;

use App\Domain\AgentProtocol\DataObjects\CartMandate;
use App\Domain\AgentProtocol\DataObjects\IntentMandate;
use App\Domain\AgentProtocol\DataObjects\PaymentMandate;
use App\Domain\AgentProtocol\Services\MandateService;
use App\Domain\AI\Contracts\MCPToolInterface;
use App\Domain\AI\ValueObjects\ToolExecutionResult;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * MCP Tool for AP2 mandate operations.
 *
 * Enables AI agents to create, accept, and execute mandates
 * per the Google AP2 Agent Payments Protocol.
 */
class AgentMandateTool implements MCPToolInterface
{
    public function __construct(
        private readonly MandateService $mandateService,
    ) {
    }

    public function getName(): string
    {
        return 'agent_protocol.mandate';
    }

    public function getCategory(): string
    {
        return 'agent_protocol';
    }

    public function getDescription(): string
    {
        return 'Create, accept, execute, revoke, or dispute AP2 mandates (Cart, Intent, Payment). '
            . 'Cart mandates for human-present shopping, Intent mandates for autonomous agent actions, '
            . 'Payment mandates for direct payment authorization.';
    }

    /** @return array<string, mixed> */
    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['create_cart', 'create_intent', 'create_payment', 'accept', 'execute', 'revoke', 'dispute'],
                ],
                'mandate_id'        => ['type' => 'string', 'description' => 'Mandate UUID (for accept/execute/revoke/dispute)'],
                'mandate_data'      => ['type' => 'object', 'description' => 'Mandate payload (for create actions)'],
                'agent_did'         => ['type' => 'string'],
                'reason'            => ['type' => 'string', 'description' => 'Reason for revocation or dispute'],
                'payment_method'    => ['type' => 'string', 'description' => 'Payment method for execution (x402, mpp)'],
                'payment_reference' => ['type' => 'string', 'description' => 'Payment reference for execution'],
            ],
            'required' => ['action'],
        ];
    }

    /** @return array<string, mixed> */
    public function getOutputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'mandate_id' => ['type' => 'string'],
                'status'     => ['type' => 'string'],
                'type'       => ['type' => 'string'],
            ],
        ];
    }

    /** @return array<string> */
    public function getCapabilities(): array
    {
        return ['write', 'payment', 'ap2-protocol', 'mandate', 'agent-commerce'];
    }

    public function isCacheable(): bool
    {
        return false;
    }

    public function getCacheTtl(): int
    {
        return 0;
    }

    /** @param array<string, mixed> $parameters */
    public function validateInput(array $parameters): bool
    {
        return isset($parameters['action']) && is_string($parameters['action']);
    }

    public function authorize(?string $userId): bool
    {
        return true;
    }

    /** @param array<string, mixed> $parameters */
    public function execute(array $parameters, ?string $conversationId = null): ToolExecutionResult
    {
        $action = (string) ($parameters['action'] ?? '');

        try {
            $result = match ($action) {
                'create_cart' => $this->mandateService->createCartMandate(
                    CartMandate::fromArray((array) ($parameters['mandate_data'] ?? []))
                ),
                'create_intent' => $this->mandateService->createIntentMandate(
                    IntentMandate::fromArray((array) ($parameters['mandate_data'] ?? []))
                ),
                'create_payment' => $this->mandateService->createPaymentMandate(
                    PaymentMandate::fromArray((array) ($parameters['mandate_data'] ?? []))
                ),
                'accept' => $this->mandateService->acceptMandate(
                    (string) ($parameters['mandate_id'] ?? ''),
                    (string) ($parameters['agent_did'] ?? ''),
                ),
                'execute' => $this->mandateService->executeMandate(
                    (string) ($parameters['mandate_id'] ?? ''),
                    (string) ($parameters['payment_method'] ?? 'x402'),
                    (string) ($parameters['payment_reference'] ?? ''),
                ),
                'revoke' => $this->mandateService->revokeMandate(
                    (string) ($parameters['mandate_id'] ?? ''),
                    (string) ($parameters['agent_did'] ?? ''),
                    (string) ($parameters['reason'] ?? ''),
                ),
                'dispute' => $this->mandateService->disputeMandate(
                    (string) ($parameters['mandate_id'] ?? ''),
                    (string) ($parameters['agent_did'] ?? ''),
                    (string) ($parameters['reason'] ?? ''),
                ),
                default => throw new Exception("Unknown mandate action: {$action}"),
            };

            return ToolExecutionResult::success($result->toArray());
        } catch (Exception $e) {
            Log::warning('MCP: Mandate operation failed', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);

            return ToolExecutionResult::failure("Mandate {$action} failed: " . $e->getMessage());
        }
    }
}
