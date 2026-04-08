<?php

declare(strict_types=1);

namespace App\Domain\Mobile\DataObjects;

use Carbon\CarbonInterface;

readonly class AppAttestIssuedChallenge
{
    public function __construct(
        public string $id,
        public string $plain_challenge,
        public string $purpose,
        public CarbonInterface $expires_at,
    ) {
    }
}
