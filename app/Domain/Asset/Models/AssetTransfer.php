<?php

declare(strict_types=1);

namespace App\Domain\Asset\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Canonical projection for aggregate-backed asset transfers.
 *
 * This is a read model only. Event-sourced transfer events remain in the event
 * store and must not share a table with projection records.
 */
class AssetTransfer extends Model
{
    use UsesTenantConnection;
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $table = 'asset_transfers';

    protected $fillable = [
        'uuid',
        'reference',
        'transfer_id',
        'hash',
        'from_account_uuid',
        'to_account_uuid',
        'from_asset_code',
        'to_asset_code',
        'from_amount',
        'to_amount',
        'exchange_rate',
        'status',
        'description',
        'failure_reason',
        'metadata',
        'initiated_at',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'from_amount'   => 'integer',
        'to_amount'     => 'integer',
        'exchange_rate' => 'decimal:10',
        'metadata'      => 'array',
        'initiated_at'  => 'datetime',
        'completed_at'  => 'datetime',
        'failed_at'     => 'datetime',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];
}
