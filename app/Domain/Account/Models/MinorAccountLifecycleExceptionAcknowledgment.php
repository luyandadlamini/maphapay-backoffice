<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $minor_account_lifecycle_exception_id
 * @property string $acknowledged_by_user_uuid
 * @property string $note
 */
class MinorAccountLifecycleExceptionAcknowledgment extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    public const UPDATED_AT = null;

    protected $table = 'minor_account_lifecycle_exception_acknowledgments';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MinorAccountLifecycleException, $this>
     */
    public function exception(): BelongsTo
    {
        return $this->belongsTo(MinorAccountLifecycleException::class, 'minor_account_lifecycle_exception_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_uuid', 'uuid');
    }
}
