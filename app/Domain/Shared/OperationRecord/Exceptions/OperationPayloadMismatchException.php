<?php

declare(strict_types=1);

namespace App\Domain\Shared\OperationRecord\Exceptions;

use RuntimeException;

/**
 * Thrown when an idempotency key is reused with a different request payload.
 *
 * Maps to HTTP 409 Conflict at the controller layer.
 */
class OperationPayloadMismatchException extends RuntimeException
{
}
