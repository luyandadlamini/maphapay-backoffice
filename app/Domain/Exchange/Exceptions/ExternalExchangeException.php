<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Exceptions;

use Exception;
use Throwable;

class ExternalExchangeException extends Exception
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
