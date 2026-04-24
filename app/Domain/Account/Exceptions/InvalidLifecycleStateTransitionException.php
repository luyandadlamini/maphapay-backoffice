<?php

declare(strict_types=1);

namespace App\Domain\Account\Exceptions;

use RuntimeException;

class InvalidLifecycleStateTransitionException extends RuntimeException
{
}
