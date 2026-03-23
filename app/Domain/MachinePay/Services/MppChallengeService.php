<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\Services;

use App\Domain\MachinePay\Contracts\ChallengeSignerInterface;
use App\Domain\MachinePay\DataObjects\MonetizedResourceConfig;
use App\Domain\MachinePay\DataObjects\MppChallenge;
use App\Domain\MachinePay\DataObjects\MppCredential;
use App\Domain\MachinePay\Exceptions\MppInvalidChallengeException;
use Illuminate\Support\Str;

/**
 * Generates and validates MPP payment challenges.
 *
 * Challenges use HMAC-SHA256 binding over seven positional slots
 * per the MPP spec to prevent tampering and replay attacks.
 */
class MppChallengeService implements ChallengeSignerInterface
{
    private readonly string $hmacKey;

    private readonly int $defaultExpiry;

    public function __construct()
    {
        $this->hmacKey = (string) config('machinepay.challenge.hmac_key', '');
        $this->defaultExpiry = (int) config('machinepay.challenge.default_expiry_seconds', 300);
    }

    /**
     * Generate a new challenge for a monetized resource.
     */
    public function generateChallenge(MonetizedResourceConfig $config, string $realm): MppChallenge
    {
        $challenge = new MppChallenge(
            id: 'ch_' . Str::random(24),
            realm: $realm,
            intent: 'charge',
            resourceId: $config->method . ':' . $config->path,
            amountCents: $config->amountCents,
            currency: $config->currency,
            availableRails: $config->availableRails,
            nonce: bin2hex(random_bytes(16)),
            expiresAt: gmdate('Y-m-d\TH:i:s\Z', time() + $this->defaultExpiry),
            description: $config->description,
        );

        $hmac = $this->signChallenge($challenge);

        return new MppChallenge(
            id: $challenge->id,
            realm: $challenge->realm,
            intent: $challenge->intent,
            resourceId: $challenge->resourceId,
            amountCents: $challenge->amountCents,
            currency: $challenge->currency,
            availableRails: $challenge->availableRails,
            nonce: $challenge->nonce,
            expiresAt: $challenge->expiresAt,
            hmac: $hmac,
            description: $challenge->description,
        );
    }

    /**
     * Validate a credential against its challenge.
     *
     * @throws MppInvalidChallengeException
     */
    public function validateCredential(MppCredential $credential, MppChallenge $challenge): void
    {
        if ($credential->challengeId !== $challenge->id) {
            throw new MppInvalidChallengeException('Credential does not match the challenge ID.');
        }

        if ($challenge->isExpired()) {
            throw MppInvalidChallengeException::expired($challenge->id);
        }

        if ($challenge->hmac !== null && ! $this->verifyChallenge($challenge, $challenge->hmac)) {
            throw MppInvalidChallengeException::invalidHmac($challenge->id);
        }

        if (! in_array($credential->rail, $challenge->availableRails, true)) {
            throw new MppInvalidChallengeException(
                "Rail '{$credential->rail}' is not accepted for challenge '{$challenge->id}'."
            );
        }
    }

    /**
     * Sign a challenge and return the HMAC-SHA256 digest.
     */
    public function signChallenge(MppChallenge $challenge): string
    {
        $input = $challenge->buildHmacInput();
        $key = $this->resolveHmacKey();

        return hash_hmac('sha256', $input, $key);
    }

    /**
     * Verify that a challenge's HMAC is valid.
     */
    public function verifyChallenge(MppChallenge $challenge, string $hmac): bool
    {
        $expected = $this->signChallenge($challenge);

        return hash_equals($expected, $hmac);
    }

    /**
     * Resolve the HMAC key with key separation.
     *
     * Uses a dedicated HMAC key if configured, otherwise derives a
     * domain-specific key from the app key to maintain key separation.
     */
    private function resolveHmacKey(): string
    {
        if ($this->hmacKey !== '') {
            return $this->hmacKey;
        }

        // Derive a dedicated key — never reuse app key directly
        return hash_hmac('sha256', 'mpp-challenge-signing', (string) config('app.key'));
    }
}
