<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use App\Models\MtnMomoTransaction;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MinorFamilyReconciliationException extends Model
{
    use HasUuids, UsesTenantConnection;

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected $table = 'minor_family_reconciliation_exceptions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurrence_count' => 'integer',
            'metadata' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'sla_due_at' => 'datetime',
            'sla_escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<MinorFamilyReconciliationExceptionAcknowledgment, $this>
     */
    public function acknowledgments(): HasMany
    {
        return $this->hasMany(MinorFamilyReconciliationExceptionAcknowledgment::class, 'minor_family_reconciliation_exception_id');
    }

    /**
     * @return BelongsTo<MtnMomoTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(MtnMomoTransaction::class, 'mtn_momo_transaction_id', 'id');
    }
}
