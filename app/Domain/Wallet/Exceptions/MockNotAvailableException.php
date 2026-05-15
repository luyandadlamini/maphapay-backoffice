<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Exceptions;

use RuntimeException;

final class MockNotAvailableException extends RuntimeException
{
    public static function disabled(): self
    {
        return new self('Mock wallet admin endpoints are disabled in this environment.');
    }
}
