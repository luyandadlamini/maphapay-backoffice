<?php

declare(strict_types=1);

namespace App\Domain\AI\MCP\Tools\MachinePay;

use App\Domain\AI\Contracts\MCPToolInterface;
use App\Domain\AI\ValueObjects\ToolExecutionResult;
use App\Domain\MachinePay\Services\MppDiscoveryService;
use App\Domain\MachinePay\Services\MppRailResolverService;
use Exception;

/**
 * MCP Tool for discovering MPP-enabled resources.
 *
 * Allows AI agents to discover which APIs support MPP payments,
 * their pricing, available rails, and protocol configuration.
 */
class MppDiscoveryTool implements MCPToolInterface
{
    public function __construct(
        private readonly MppDiscoveryService $discovery,
        private readonly MppRailResolverService $railResolver,
    ) {
    }

    public function getName(): string
    {
        return 'mpp.discovery';
    }

    public function getCategory(): string
    {
        return 'machinepay';
    }

    public function getDescription(): string
    {
        return 'Discover MPP-enabled resources and payment configuration. '
            . 'Returns available payment rails, pricing for monetized endpoints, '
            . 'and protocol configuration for the Machine Payments Protocol.';
    }

    /** @return array<string, mixed> */
    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'include_resources' => [
                    'type'        => 'boolean',
                    'description' => 'Include monetized resource details (x-payment-info extensions).',
                    'default'     => true,
                ],
            ],
            'required' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function getOutputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'configuration' => ['type' => 'object'],
                'rails'         => ['type' => 'array'],
                'resources'     => ['type' => 'array'],
            ],
        ];
    }

    /** @return array<string> */
    public function getCapabilities(): array
    {
        return ['read', 'discovery', 'mpp-protocol'];
    }

    public function isCacheable(): bool
    {
        return true;
    }

    public function getCacheTtl(): int
    {
        return 300;
    }

    /** @param array<string, mixed> $parameters */
    public function validateInput(array $parameters): bool
    {
        return true;
    }

    public function authorize(?string $userId): bool
    {
        return true;
    }

    /** @param array<string, mixed> $parameters */
    public function execute(array $parameters, ?string $conversationId = null): ToolExecutionResult
    {
        try {
            $result = [
                'configuration' => $this->discovery->getWellKnownConfiguration(),
                'rails'         => $this->railResolver->getAvailableRailIds(),
            ];

            $includeResources = $parameters['include_resources'] ?? true;

            if ($includeResources) {
                $result['resources'] = $this->discovery->getPaymentInfoExtensions();
            }

            return ToolExecutionResult::success($result);
        } catch (Exception $e) {
            return ToolExecutionResult::failure('MPP discovery failed: ' . $e->getMessage());
        }
    }
}
