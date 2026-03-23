<?php

declare(strict_types=1);

namespace App\Domain\AgentProtocol\DataObjects;

/**
 * AP2 Intent Mandate — autonomous agent intent authorization.
 *
 * Delegates spending authority to an agent with constraints.
 * Used for human-not-present scenarios like "buy when price drops below $100".
 */
readonly class IntentMandate
{
    /**
     * @param string              $intent        Natural language description of the intent.
     * @param int                 $budgetCents   Maximum spending budget in smallest currency unit.
     * @param string              $currency      ISO 4217 currency code.
     * @param string              $delegatorDid  User/delegator DID.
     * @param string              $agentDid      Agent DID receiving delegation.
     * @param array<string,mixed> $constraints   Additional constraints (merchants, SKUs, etc.).
     * @param string|null         $expiresAt     RFC 3339 expiry.
     * @param bool                $requiresRefundability Whether refund capability is required.
     */
    public function __construct(
        public string $intent,
        public int $budgetCents,
        public string $currency,
        public string $delegatorDid,
        public string $agentDid,
        public array $constraints = [],
        public ?string $expiresAt = null,
        public bool $requiresRefundability = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'intent'                 => $this->intent,
            'budget_cents'           => $this->budgetCents,
            'currency'               => $this->currency,
            'delegator_did'          => $this->delegatorDid,
            'agent_did'              => $this->agentDid,
            'constraints'            => $this->constraints ?: null,
            'expires_at'             => $this->expiresAt,
            'requires_refundability' => $this->requiresRefundability ?: null,
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            intent: (string) ($data['intent'] ?? ''),
            budgetCents: (int) ($data['budget_cents'] ?? 0),
            currency: (string) ($data['currency'] ?? 'USD'),
            delegatorDid: (string) ($data['delegator_did'] ?? ''),
            agentDid: (string) ($data['agent_did'] ?? ''),
            constraints: (array) ($data['constraints'] ?? []),
            expiresAt: isset($data['expires_at']) ? (string) $data['expires_at'] : null,
            requiresRefundability: (bool) ($data['requires_refundability'] ?? false),
        );
    }
}
