<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MinorFamilyReconciliationExceptionAcknowledgment extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    public const UPDATED_AT = null;

    protected $table = 'minor_family_reconciliation_exception_acknowledgments';

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
     * @return BelongsTo<MinorFamilyReconciliationException, $this>
     */
    public function exception(): BelongsTo
    {
        return $this->belongsTo(MinorFamilyReconciliationException::class, 'minor_family_reconciliation_exception_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_uuid', 'uuid');
    }
}
