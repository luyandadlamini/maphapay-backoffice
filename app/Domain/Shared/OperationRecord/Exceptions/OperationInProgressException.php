<?php

declare(strict_types=1);

namespace App\Domain\Shared\OperationRecord\Exceptions;

use RuntimeException;

class OperationInProgressException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('An identical operation is already in progress. Please retry shortly.');
    }
}
