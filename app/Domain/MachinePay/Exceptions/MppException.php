<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\Exceptions;

use RuntimeException;

/**
 * Base exception for Machine Payments Protocol errors.
 */
class MppException extends RuntimeException
{
}
