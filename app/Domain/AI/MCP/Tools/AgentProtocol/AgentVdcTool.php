<?php

declare(strict_types=1);

namespace App\Domain\AI\MCP\Tools\AgentProtocol;

use App\Domain\AgentProtocol\DataObjects\VerifiableDigitalCredential;
use App\Domain\AgentProtocol\Enums\VdcType;
use App\Domain\AgentProtocol\Services\VdcService;
use App\Domain\AI\Contracts\MCPToolInterface;
use App\Domain\AI\ValueObjects\ToolExecutionResult;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * MCP Tool for AP2 Verifiable Digital Credential operations.
 *
 * Enables AI agents to issue and verify VDCs for mandate authorization.
 */
class AgentVdcTool implements MCPToolInterface
{
    public function __construct(
        private readonly VdcService $vdcService,
    ) {
    }

    public function getName(): string
    {
        return 'agent_protocol.vdc';
    }

    public function getCategory(): string
    {
        return 'agent_protocol';
    }

    public function getDescription(): string
    {
        return 'Issue and verify Verifiable Digital Credentials (VDCs) for AP2 mandates. '
            . 'VDCs provide cryptographic proof of mandate authorization using SD-JWT-VC.';
    }

    /** @return array<string, mixed> */
    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['issue', 'verify'],
                ],
                'mandate_id' => ['type' => 'string', 'description' => 'Mandate UUID (for issue)'],
                'vdc_type'   => ['type' => 'string', 'enum' => ['cart_vdc', 'intent_vdc', 'payment_vdc']],
                'issuer_did' => ['type' => 'string', 'description' => 'Issuer DID (for issue)'],
                'vdc_data'   => ['type' => 'object', 'description' => 'VDC payload (for verify)'],
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
                'vdc'   => ['type' => 'object'],
                'valid' => ['type' => 'boolean'],
                'hash'  => ['type' => 'string'],
            ],
        ];
    }

    /** @return array<string> */
    public function getCapabilities(): array
    {
        return ['write', 'ap2-protocol', 'vdc', 'cryptographic-verification'];
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
            return match ($action) {
                'issue'  => $this->issueVdc($parameters),
                'verify' => $this->verifyVdc($parameters),
                default  => ToolExecutionResult::failure("Unknown VDC action: {$action}"),
            };
        } catch (Exception $e) {
            Log::warning('MCP: VDC operation failed', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);

            return ToolExecutionResult::failure("VDC {$action} failed: " . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function issueVdc(array $parameters): ToolExecutionResult
    {
        $mandateId = (string) ($parameters['mandate_id'] ?? '');
        $vdcTypeStr = (string) ($parameters['vdc_type'] ?? 'payment_vdc');
        $issuerDid = (string) ($parameters['issuer_did'] ?? '');

        $vdcType = VdcType::tryFrom($vdcTypeStr);

        if ($vdcType === null) {
            return ToolExecutionResult::failure("Invalid VDC type: {$vdcTypeStr}");
        }

        $vdc = $this->vdcService->issueCredential($mandateId, $vdcType, $issuerDid);

        return ToolExecutionResult::success([
            'vdc'  => $vdc->toArray(),
            'hash' => $vdc->computeHash(),
        ]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function verifyVdc(array $parameters): ToolExecutionResult
    {
        $vdcData = (array) ($parameters['vdc_data'] ?? []);
        $vdc = VerifiableDigitalCredential::fromArray($vdcData);

        $isValid = $this->vdcService->verifyCredential($vdc);

        return ToolExecutionResult::success([
            'valid' => $isValid,
            'hash'  => $vdc->computeHash(),
        ]);
    }
}
