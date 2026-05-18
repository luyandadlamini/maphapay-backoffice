<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base class for Eloquent models whose tables live in the *central* database.
 *
 * Central tables (users, tenants, account_memberships, idempotency_keys, etc.)
 * must never be queried on the per-tenant connection. Extending this class
 * pins the connection to `central` regardless of the current default.
 *
 * Use this for any model whose underlying table is not replicated per tenant.
 */
abstract class CentralModel extends Model
{
    protected $connection = 'central';
}
