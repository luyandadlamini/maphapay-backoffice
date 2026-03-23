<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\DataObjects;

/**
 * RFC 9457 Problem Details error envelope for MPP.
 *
 * Used in 402 responses and error conditions per the MPP spec.
 * Maps to standard problem types: payment-required, payment-insufficient,
 * payment-expired, verification-failed, method-unsupported, etc.
 */
readonly class ProblemDetail
{
    /**
     * @param string                    $type       Problem type URI.
     * @param string                    $title      Short human-readable summary.
     * @param int                       $status     HTTP status code.
     * @param string|null               $detail     Detailed explanation.
     * @param string|null               $instance   URI reference to the specific occurrence.
     * @param array<string, mixed>|null $extensions Additional problem-specific fields.
     */
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public ?string $detail = null,
        public ?string $instance = null,
        public ?array $extensions = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'type'   => $this->type,
            'title'  => $this->title,
            'status' => $this->status,
        ];

        if ($this->detail !== null) {
            $result['detail'] = $this->detail;
        }

        if ($this->instance !== null) {
            $result['instance'] = $this->instance;
        }

        if ($this->extensions !== null) {
            $result = array_merge($result, $this->extensions);
        }

        return $result;
    }

    /**
     * Create a payment-required problem detail.
     */
    public static function paymentRequired(string $detail, ?string $challengeId = null): self
    {
        return new self(
            type: 'urn:ietf:params:mpp:error:payment-required',
            title: 'Payment Required',
            status: 402,
            detail: $detail,
            extensions: $challengeId ? ['challenge_id' => $challengeId] : null,
        );
    }

    /**
     * Create a verification-failed problem detail.
     */
    public static function verificationFailed(string $detail): self
    {
        return new self(
            type: 'urn:ietf:params:mpp:error:verification-failed',
            title: 'Payment Verification Failed',
            status: 402,
            detail: $detail,
        );
    }

    /**
     * Create a payment-expired problem detail.
     */
    public static function paymentExpired(string $challengeId): self
    {
        return new self(
            type: 'urn:ietf:params:mpp:error:payment-expired',
            title: 'Payment Challenge Expired',
            status: 402,
            detail: 'The payment challenge has expired. Request a new one.',
            extensions: ['challenge_id' => $challengeId],
        );
    }

    /**
     * Create a method-unsupported problem detail.
     */
    public static function railUnsupported(string $rail): self
    {
        return new self(
            type: 'urn:ietf:params:mpp:error:method-unsupported',
            title: 'Payment Rail Not Supported',
            status: 400,
            detail: "The payment rail '{$rail}' is not supported by this server.",
        );
    }

    /**
     * Create a settlement-failed problem detail.
     */
    public static function settlementFailed(string $detail): self
    {
        return new self(
            type: 'urn:ietf:params:mpp:error:settlement-failed',
            title: 'Settlement Failed',
            status: 402,
            detail: $detail,
        );
    }
}
