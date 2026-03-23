<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\Services;

use App\Domain\MachinePay\DataObjects\MppChallenge;
use App\Domain\MachinePay\DataObjects\MppCredential;
use App\Domain\MachinePay\Exceptions\MppInvalidChallengeException;
use App\Domain\MachinePay\Exceptions\MppSettlementException;
use Illuminate\Support\Facades\Log;

/**
 * Verifies MPP payment credentials without settling.
 *
 * Validates HMAC integrity, nonce freshness, challenge expiry,
 * and delegates to the appropriate payment rail for proof verification.
 */
class MppVerificationService
{
    public function __construct(
        private readonly MppChallengeService $challengeService,
        private readonly MppRailResolverService $railResolver,
    ) {
    }

    /**
     * Verify a credential against the challenge.
     *
     * @return array{valid: bool, reason: string|null}
     */
    public function verify(MppCredential $credential, MppChallenge $challenge): array
    {
        try {
            $this->challengeService->validateCredential($credential, $challenge);
        } catch (MppInvalidChallengeException $e) {
            Log::warning('MPP: Challenge validation failed', [
                'challenge_id' => $challenge->id,
                'error'        => $e->getMessage(),
            ]);

            return ['valid' => false, 'reason' => $e->getMessage()];
        }

        // Delegate to the payment rail for proof verification
        $rail = $this->railResolver->resolve($credential->rail);

        if ($rail === null) {
            return ['valid' => false, 'reason' => "Rail '{$credential->rail}' is not available."];
        }

        try {
            $isValid = $rail->verifyPayment($credential);
        } catch (MppSettlementException $e) {
            Log::warning('MPP: Rail verification failed', [
                'rail'         => $credential->rail,
                'challenge_id' => $challenge->id,
                'error'        => $e->getMessage(),
            ]);

            return ['valid' => false, 'reason' => $e->getMessage()];
        }

        if (! $isValid) {
            return ['valid' => false, 'reason' => 'Payment proof rejected by the rail adapter.'];
        }

        return ['valid' => true, 'reason' => null];
    }
}
