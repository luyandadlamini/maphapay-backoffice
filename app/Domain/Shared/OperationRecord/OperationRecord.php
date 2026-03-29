<?php

declare(strict_types=1);

namespace App\Domain\Shared\OperationRecord;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tracks exactly-once execution of money-moving operations.
 *
 * Keyed by (user_id, operation_type, idempotency_key). A completed record
 * with a cached result_payload prevents duplicate handler execution even
 * after the HTTP-layer idempotency cache (24 h TTL) has expired.
 *
 * @property string                    $id
 * @property int                       $user_id
 * @property string                    $operation_type
 * @property string                    $idempotency_key
 * @property string                    $payload_hash
 * @property string                    $status
 * @property array<string, mixed>|null $result_payload
 * @property \Carbon\Carbon            $created_at
 * @property \Carbon\Carbon            $updated_at
 */
class OperationRecord extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'id',
        'user_id',
        'operation_type',
        'idempotency_key',
        'payload_hash',
        'status',
        'result_payload',
    ];

    protected function casts(): array
    {
        return [
            'result_payload' => 'array',
        ];
    }
}
