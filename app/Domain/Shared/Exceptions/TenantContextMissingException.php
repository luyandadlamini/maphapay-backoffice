<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * Thrown when a TenantModel (or UsesTenantConnection consumer) is touched
 * outside an active tenant context in any non-testing environment.
 *
 * Catching this exception is almost always wrong. The correct fix is to
 * wrap the calling code in WithTenantContext::withAccountTenancy() or
 * ensure the HTTP route is behind the account.context middleware.
 */
final class TenantContextMissingException extends RuntimeException
{
    public static function forModel(string $modelClass): self
    {
        return new self(sprintf(
            'TenantContextMissingException: %s was queried without an active tenant context. '
            . 'Wrap this call in WithTenantContext::withAccountTenancy($accountUuid, ...) '
            . 'or route through the account.context middleware.',
            $modelClass,
        ));
    }
}
