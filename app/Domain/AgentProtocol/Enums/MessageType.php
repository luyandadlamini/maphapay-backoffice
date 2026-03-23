<?php

declare(strict_types=1);

namespace App\Domain\AgentProtocol\Enums;

/**
 * A2A Message Types following the Agent-to-Agent Protocol specification.
 */
enum MessageType: string
{
    // Request/Response patterns
    case REQUEST = 'request';
    case RESPONSE = 'response';

    // Event-driven patterns
    case EVENT = 'event';
    case NOTIFICATION = 'notification';

    // Payment-specific messages
    case PAYMENT_REQUEST = 'payment.request';
    case PAYMENT_RESPONSE = 'payment.response';
    case PAYMENT_CONFIRMATION = 'payment.confirmation';
    case PAYMENT_CANCELLATION = 'payment.cancellation';

    // Escrow messages
    case ESCROW_CREATE = 'escrow.create';
    case ESCROW_FUNDED = 'escrow.funded';
    case ESCROW_RELEASE = 'escrow.release';
    case ESCROW_DISPUTE = 'escrow.dispute';
    case ESCROW_RESOLVED = 'escrow.resolved';

    // Discovery and negotiation
    case DISCOVERY_QUERY = 'discovery.query';
    case DISCOVERY_RESPONSE = 'discovery.response';
    case CAPABILITY_ADVERTISEMENT = 'capability.advertisement';
    case PROTOCOL_NEGOTIATION = 'protocol.negotiation';
    case PROTOCOL_AGREEMENT = 'protocol.agreement';

    // Status and health
    case HEARTBEAT = 'heartbeat';
    case STATUS_INQUIRY = 'status.inquiry';
    case STATUS_REPORT = 'status.report';

    // AP2 Mandate messages
    case MANDATE_CREATE = 'mandate.create';
    case MANDATE_ACCEPT = 'mandate.accept';
    case MANDATE_EXECUTE = 'mandate.execute';
    case MANDATE_COMPLETE = 'mandate.complete';
    case MANDATE_REVOKE = 'mandate.revoke';
    case MANDATE_DISPUTE = 'mandate.dispute';

    // Error handling
    case ERROR = 'error';
    case ACKNOWLEDGMENT = 'acknowledgment';

    /**
     * Check if this message type requires a response.
     */
    public function requiresResponse(): bool
    {
        return match ($this) {
            self::REQUEST,
            self::PAYMENT_REQUEST,
            self::ESCROW_CREATE,
            self::MANDATE_CREATE,
            self::MANDATE_EXECUTE,
            self::DISCOVERY_QUERY,
            self::PROTOCOL_NEGOTIATION,
            self::STATUS_INQUIRY => true,
            default              => false,
        };
    }

    /**
     * Check if this message type is payment-related.
     */
    public function isPaymentRelated(): bool
    {
        return match ($this) {
            self::PAYMENT_REQUEST,
            self::PAYMENT_RESPONSE,
            self::PAYMENT_CONFIRMATION,
            self::PAYMENT_CANCELLATION,
            self::ESCROW_CREATE,
            self::ESCROW_FUNDED,
            self::ESCROW_RELEASE,
            self::ESCROW_DISPUTE,
            self::ESCROW_RESOLVED,
            self::MANDATE_CREATE,
            self::MANDATE_ACCEPT,
            self::MANDATE_EXECUTE,
            self::MANDATE_COMPLETE,
            self::MANDATE_REVOKE,
            self::MANDATE_DISPUTE => true,
            default               => false,
        };
    }

    /**
     * Get the expected response type for this message type.
     */
    public function getExpectedResponseType(): ?self
    {
        return match ($this) {
            self::REQUEST              => self::RESPONSE,
            self::PAYMENT_REQUEST      => self::PAYMENT_RESPONSE,
            self::DISCOVERY_QUERY      => self::DISCOVERY_RESPONSE,
            self::PROTOCOL_NEGOTIATION => self::PROTOCOL_AGREEMENT,
            self::STATUS_INQUIRY       => self::STATUS_REPORT,
            default                    => null,
        };
    }

    /**
     * Get the default timeout in seconds for this message type.
     */
    public function getDefaultTimeout(): int
    {
        return match ($this) {
            self::HEARTBEAT => 5,
            self::STATUS_INQUIRY,
            self::STATUS_REPORT => 10,
            self::DISCOVERY_QUERY,
            self::DISCOVERY_RESPONSE => 15,
            self::PROTOCOL_NEGOTIATION,
            self::PROTOCOL_AGREEMENT => 30,
            self::PAYMENT_REQUEST,
            self::ESCROW_CREATE,
            self::MANDATE_CREATE,
            self::MANDATE_EXECUTE => 60,
            default               => 30,
        };
    }

    /**
     * Check if this message type should be persisted.
     */
    public function shouldPersist(): bool
    {
        return match ($this) {
            self::HEARTBEAT,
            self::STATUS_INQUIRY,
            self::STATUS_REPORT => false,
            default             => true,
        };
    }
}
