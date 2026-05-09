<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Exceptions;

use App\Domain\CardSubscriptions\Enums\CardErrorCode;
use DomainException;

/**
 * @property-read CardErrorCode $code
 */
class EntitlementDeniedException extends DomainException
{
    public function __construct(public readonly CardErrorCode $cardErrorCode, string $message)
    {
        parent::__construct($message, 0);
    }

    public function code(): CardErrorCode
    {
        return $this->cardErrorCode;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'code') {
            return $this->cardErrorCode;
        }

        trigger_error(sprintf('Undefined property: %s::$%s', self::class, $name), E_USER_NOTICE);

        return null;
    }
}
