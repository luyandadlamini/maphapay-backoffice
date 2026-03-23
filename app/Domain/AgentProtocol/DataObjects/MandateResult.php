<?php

declare(strict_types=1);

namespace App\Domain\AgentProtocol\DataObjects;

use App\Domain\AgentProtocol\Enums\MandateStatus;

/**
 * Result of a mandate operation (creation, acceptance, execution, etc.).
 */
readonly class MandateResult
{
    /**
     * @param string              $mandateId         Mandate UUID.
     * @param MandateStatus       $status            Current status.
     * @param array<string>       $paymentReferences Associated payment references.
     * @param array<string,mixed> $receipts          Payment receipts.
     * @param string|null         $disputeInfo       Dispute details if disputed.
     */
    public function __construct(
        public string $mandateId,
        public MandateStatus $status,
        public array $paymentReferences = [],
        public array $receipts = [],
        public ?string $disputeInfo = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'mandate_id'         => $this->mandateId,
            'status'             => $this->status->value,
            'payment_references' => $this->paymentReferences ?: null,
            'receipts'           => $this->receipts ?: null,
            'dispute_info'       => $this->disputeInfo,
        ], static fn ($v) => $v !== null);
    }
}
