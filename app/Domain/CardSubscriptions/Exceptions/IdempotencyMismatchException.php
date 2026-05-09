<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Exceptions;

use DomainException;

class IdempotencyMismatchException extends DomainException
{
}
