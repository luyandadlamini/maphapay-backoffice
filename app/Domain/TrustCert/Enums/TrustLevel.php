<?php

declare(strict_types=1);

namespace App\Domain\TrustCert\Enums;

/**
 * Trust levels for issuers and credentials.
 */
enum TrustLevel: string
{
    case UNKNOWN = 'unknown';
    case BASIC = 'basic';
    case VERIFIED = 'verified';
    case HIGH = 'high';
    case ULTIMATE = 'ultimate';

    public function label(): string
    {
        return match ($this) {
            self::UNKNOWN  => 'Unknown',
            self::BASIC    => 'Basic',
            self::VERIFIED => 'Verified',
            self::HIGH     => 'High',
            self::ULTIMATE => 'Ultimate',
        };
    }

    public function numericValue(): int
    {
        return match ($this) {
            self::UNKNOWN  => 0,
            self::BASIC    => 1,
            self::VERIFIED => 2,
            self::HIGH     => 3,
            self::ULTIMATE => 4,
        };
    }

    public function meetsMinimum(TrustLevel $required): bool
    {
        return $this->numericValue() >= $required->numericValue();
    }

    /**
     * Get trust requirements for this level.
     *
     * @return array<int, string>
     */
    public function requirements(): array
    {
        return match ($this) {
            self::UNKNOWN  => ['Email or phone verification'],
            self::BASIC    => ['Government-issued ID', 'Selfie verification'],
            self::VERIFIED => ['Level 1 requirements', 'Proof of address'],
            self::HIGH     => ['Level 2 requirements', 'Source of funds documentation'],
            self::ULTIMATE => ['Level 3 requirements', 'Full KYB / accredited investor verification'],
        };
    }

    /**
     * Get required document types for this level.
     *
     * @return array<int, string>
     */
    public function documents(): array
    {
        return match ($this) {
            self::UNKNOWN  => [],
            self::BASIC    => ['id_front', 'id_back', 'selfie'],
            self::VERIFIED => ['id_front', 'id_back', 'selfie', 'proof_of_address'],
            self::HIGH     => ['id_front', 'id_back', 'selfie', 'proof_of_address', 'source_of_funds'],
            self::ULTIMATE => ['id_front', 'id_back', 'selfie', 'proof_of_address', 'source_of_funds'],
        };
    }

    /**
     * Get description for this level.
     */
    public function description(): string
    {
        return match ($this) {
            self::UNKNOWN  => 'No verification required',
            self::BASIC    => 'Basic identity verification',
            self::VERIFIED => 'Full identity and address verification',
            self::HIGH     => 'Full verification with source of funds',
            self::ULTIMATE => 'Complete KYB / accredited investor verification',
        };
    }
}
