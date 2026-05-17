<?php

declare(strict_types=1);

namespace App\Domain\Shared\Traits;

use RuntimeException;
use Stancl\Tenancy\Tenancy;

trait RequiresTenantContext
{
    public static function bootRequiresTenantContext(): void
    {
        $check = static function (): void {
            $env = config('app.env', 'production');
            if (in_array($env, ['testing', 'local'], true)) {
                return;
            }

            if (! app(Tenancy::class)->initialized) {
                throw new RuntimeException(sprintf(
                    'Refusing to operate on %s without tenant context. '
                    . 'Initialize tenancy via account.context middleware or WithTenantContext::withAccountTenancy().',
                    static::class,
                ));
            }
        };

        static::saving($check);
    }
}
