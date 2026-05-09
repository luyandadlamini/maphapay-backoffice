<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Models;

use App\Domain\CardSubscriptions\Enums\CardDisputeReason;
use App\Domain\CardSubscriptions\Enums\CardDisputeStatus;
use App\Domain\Shared\Traits\UsesTenantConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardDispute extends Model
{
    /** @use HasFactory<\Database\Factories\Domain\CardSubscriptions\Models\CardDisputeFactory> */
    use HasFactory;
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'card_disputes';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'card_transaction_id',
        'reason',
        'status',
        'user_description',
        'evidence',
        'disputed_amount',
        'currency',
        'processor_dispute_id',
        'submitted_at',
        'processor_acknowledged_at',
        'resolved_at',
        'resolution_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason'                    => CardDisputeReason::class,
            'status'                    => CardDisputeStatus::class,
            'evidence'                  => 'array',
            'disputed_amount'           => 'decimal:2',
            'submitted_at'              => 'datetime',
            'processor_acknowledged_at' => 'datetime',
            'resolved_at'               => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
