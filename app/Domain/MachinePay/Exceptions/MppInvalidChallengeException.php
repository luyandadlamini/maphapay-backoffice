<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\Exceptions;

/**
 * Thrown when an MPP challenge is malformed, expired, or has an invalid HMAC.
 */
class MppInvalidChallengeException extends MppException
{
    public static function missingField(string $field): self
    {
        return new self("Missing required challenge field: {$field}");
    }

    public static function invalidEncoding(string $detail): self
    {
        return new self("Invalid challenge encoding: {$detail}");
    }

    public static function expired(string $challengeId): self
    {
        return new self("Challenge '{$challengeId}' has expired.");
    }

    public static function invalidHmac(string $challengeId): self
    {
        return new self("Challenge '{$challengeId}' has an invalid HMAC signature.");
    }
}
