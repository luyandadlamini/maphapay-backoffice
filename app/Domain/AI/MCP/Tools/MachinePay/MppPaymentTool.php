<?php

declare(strict_types=1);

namespace App\Domain\AI\MCP\Tools\MachinePay;

use App\Domain\AI\Contracts\MCPToolInterface;
use App\Domain\AI\ValueObjects\ToolExecutionResult;
use App\Domain\MachinePay\Services\MppClientService;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * MCP Tool for handling MPP (Machine Payments Protocol) payments.
 *
 * Allows AI agents to:
 * - Handle 402 challenges from MPP-enabled APIs
 * - Select payment rails (Stripe, Tempo, Lightning, Card)
 * - Generate credentials for retry
 *
 * Supports MCP transport binding with error code -32042.
 */
class MppPaymentTool implements MCPToolInterface
{
    public function __construct(
        private readonly MppClientService $clientService,
    ) {
    }

    public function getName(): string
    {
        return 'mpp.payment';
    }

    public function getCategory(): string
    {
        return 'machinepay';
    }

    public function getDescription(): string
    {
        return 'Handle Machine Payments Protocol (MPP) 402 challenges from external APIs. '
            . 'Parses WWW-Authenticate: Payment headers, selects payment rail (Stripe, Tempo, Lightning, Card), '
            . 'enforces spending limits, and returns Authorization: Payment credentials for retry.';
    }

    /** @return array<string, mixed> */
    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'www_authenticate_header' => [
                    'type'        => 'string',
                    'description' => 'The WWW-Authenticate: Payment header from the 402 response.',
                    'minLength'   => 1,
                ],
                'agent_id' => [
                    'type'        => 'string',
                    'description' => 'The agent identifier for spending limit enforcement.',
                    'minLength'   => 1,
                    'maxLength'   => 255,
                ],
            ],
            'required' => ['www_authenticate_header', 'agent_id'],
        ];
    }

    /** @return array<string, mixed> */
    public function getOutputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'headers' => [
                    'type'        => 'object',
                    'description' => 'Headers to attach to the retry request',
                    'properties'  => [
                        'Authorization' => ['type' => 'string'],
                    ],
                ],
                'payment' => [
                    'type'       => 'object',
                    'properties' => [
                        'challenge_id' => ['type' => 'string'],
                        'rail'         => ['type' => 'string'],
                        'amount_cents' => ['type' => 'integer'],
                        'currency'     => ['type' => 'string'],
                    ],
                ],
                'instructions' => ['type' => 'string'],
            ],
        ];
    }

    /** @return array<string> */
    public function getCapabilities(): array
    {
        return ['write', 'payment', 'mpp-protocol', 'spending-limits', 'agent-autonomous', 'multi-rail'];
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
        return isset($parameters['www_authenticate_header'], $parameters['agent_id'])
            && is_string($parameters['www_authenticate_header'])
            && is_string($parameters['agent_id'])
            && $parameters['www_authenticate_header'] !== ''
            && $parameters['agent_id'] !== '';
    }

    public function authorize(?string $userId): bool
    {
        return true;
    }

    /** @param array<string, mixed> $parameters */
    public function execute(array $parameters, ?string $conversationId = null): ToolExecutionResult
    {
        $header = $parameters['www_authenticate_header'] ?? '';
        $agentId = $parameters['agent_id'] ?? '';

        if (! is_string($header) || $header === '') {
            return ToolExecutionResult::failure('Missing required parameter: www_authenticate_header');
        }

        if (! is_string($agentId) || $agentId === '') {
            return ToolExecutionResult::failure('Missing required parameter: agent_id');
        }

        try {
            $result = $this->clientService->handlePaymentRequired($header, $agentId);
            $credential = $result['credential'];

            Log::info('MCP: MPP payment credential generated', [
                'agent_id'     => $agentId,
                'challenge_id' => $credential->challengeId,
                'rail'         => $credential->rail,
            ]);

            return ToolExecutionResult::success([
                'headers' => [
                    'Authorization' => $result['header'],
                ],
                'payment' => [
                    'challenge_id' => $credential->challengeId,
                    'rail'         => $credential->rail,
                ],
                'instructions' => 'Retry the original request with the Authorization header attached.',
            ]);
        } catch (Exception $e) {
            Log::warning('MCP: MPP payment failed', [
                'agent_id' => $agentId,
                'error'    => $e->getMessage(),
            ]);

            return ToolExecutionResult::failure('MPP payment failed: ' . $e->getMessage());
        }
    }
}
