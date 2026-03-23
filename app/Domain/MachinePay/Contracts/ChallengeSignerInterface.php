<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\Contracts;

use App\Domain\MachinePay\DataObjects\MppChallenge;

/**
 * Interface for HMAC-SHA256 challenge signing and verification.
 *
 * MPP challenges are bound using HMAC-SHA256 over seven positional
 * slots: realm|method|intent|request|expires|digest|opaque.
 */
interface ChallengeSignerInterface
{
    /**
     * Sign a challenge and return the HMAC digest.
     */
    public function signChallenge(MppChallenge $challenge): string;

    /**
     * Verify that a challenge's HMAC is valid.
     */
    public function verifyChallenge(MppChallenge $challenge, string $hmac): bool;
}
