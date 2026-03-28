<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Exceptions;

use RuntimeException;

/**
 * Thrown when an authorized transaction cannot be located for the requesting user.
 */
class TransactionNotFoundException extends RuntimeException
{
    public function getHttpStatus(): int
    {
        return 404;
    }
}
