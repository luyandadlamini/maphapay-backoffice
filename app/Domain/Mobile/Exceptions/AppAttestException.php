<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Exceptions;

use RuntimeException;

class AppAttestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
    ) {
        parent::__construct($message);
    }

    public static function invalidChallenge(string $reason): self
    {
        return new self($reason, 'INVALID_APP_ATTEST_CHALLENGE');
    }

    public static function enrollmentFailed(string $reason): self
    {
        return new self($reason, 'APP_ATTEST_ENROLLMENT_FAILED');
    }
}
