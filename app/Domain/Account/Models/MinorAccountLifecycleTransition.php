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
 * @property string $transition_type
 * @property string $state
 * @property \Illuminate\Support\Carbon $effective_at
 * @property \Illuminate\Support\Carbon|null $executed_at
 * @property string|null $blocked_reason_code
 * @property array<string, mixed>|null $metadata
 */
class MinorAccountLifecycleTransition extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    public const TYPE_TIER_ADVANCE = 'tier_advance';
    public const TYPE_ADULT_TRANSITION_REVIEW = 'adult_transition_review';
    public const TYPE_ADULT_TRANSITION_CUTOFF = 'adult_transition_cutoff';
    public const TYPE_GUARDIAN_CONTINUITY = 'guardian_continuity';

    public const STATE_PENDING = 'pending';
    public const STATE_COMPLETED = 'completed';
    public const STATE_BLOCKED = 'blocked';

    protected $table = 'minor_account_lifecycle_transitions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'executed_at' => 'datetime',
            'metadata' => 'array',
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
     * @return HasMany<MinorAccountLifecycleException, $this>
     */
    public function exceptions(): HasMany
    {
        return $this->hasMany(MinorAccountLifecycleException::class, 'transition_id', 'id');
    }
}
