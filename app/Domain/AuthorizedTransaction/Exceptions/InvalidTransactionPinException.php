<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Exceptions;

use RuntimeException;

/**
 * Thrown when the submitted PIN does not match the user's stored transaction PIN hash.
 */
class InvalidTransactionPinException extends RuntimeException
{
    public function getHttpStatus(): int
    {
        return 422;
    }
}
