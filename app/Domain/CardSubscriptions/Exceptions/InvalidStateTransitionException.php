<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Exceptions;

use DomainException;

class InvalidStateTransitionException extends DomainException
{
}
