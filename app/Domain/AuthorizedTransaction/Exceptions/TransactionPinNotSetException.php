<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Exceptions;

use RuntimeException;

/**
 * Thrown when a user attempts PIN verification but has not set a transaction PIN.
 */
class TransactionPinNotSetException extends RuntimeException
{
    public function getHttpStatus(): int
    {
        return 422;
    }
}
