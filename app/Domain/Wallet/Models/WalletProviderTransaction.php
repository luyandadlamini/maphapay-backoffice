<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $provider_id
 * @property string $provider_request_id
 * @property string $type
 * @property string $status
 * @property string $currency
 * @property int $amount_minor
 * @property string|null $user_uuid
 * @property array<string, mixed>|null $payload
 * @property \Illuminate\Support\Carbon|null $settled_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class WalletProviderTransaction extends Model
{
    public const TYPE_COLLECT = 'collect';

    public const TYPE_DISBURSE = 'disburse';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESSFUL = 'successful';

    public const STATUS_FAILED = 'failed';

    protected $table = 'wallet_provider_transactions';

    protected $fillable = [
        'provider_id',
        'provider_request_id',
        'type',
        'status',
        'currency',
        'amount_minor',
        'user_uuid',
        'payload',
        'settled_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'amount_minor' => 'integer',
        'settled_at'   => 'datetime',
    ];
}
