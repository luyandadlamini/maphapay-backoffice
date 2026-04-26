<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $minor_account_uuid
 * @property string|null $transition_id
 * @property string $reason_code
 * @property string $status
 * @property string $source
 * @property int $occurrence_count
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon $first_seen_at
 * @property \Illuminate\Support\Carbon $last_seen_at
 * @property \Illuminate\Support\Carbon|null $sla_due_at
 * @property \Illuminate\Support\Carbon|null $sla_escalated_at
 * @property \Illuminate\Support\Carbon|null $resolved_at
 */
class MinorAccountLifecycleException extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected $table = 'minor_account_lifecycle_exceptions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurrence_count' => 'integer',
            'metadata'         => 'array',
            'first_seen_at'    => 'datetime',
            'last_seen_at'     => 'datetime',
            'sla_due_at'       => 'datetime',
            'sla_escalated_at' => 'datetime',
            'resolved_at'      => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function minorAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'minor_account_uuid', 'uuid');
    }

    /**
     * @return BelongsTo<MinorAccountLifecycleTransition, $this>
     */
    public function transition(): BelongsTo
    {
        return $this->belongsTo(MinorAccountLifecycleTransition::class, 'transition_id', 'id');
    }

    /**
     * @return HasMany<MinorAccountLifecycleExceptionAcknowledgment, $this>
     */
    public function acknowledgments(): HasMany
    {
        return $this->hasMany(MinorAccountLifecycleExceptionAcknowledgment::class, 'minor_account_lifecycle_exception_id');
    }
}
