<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Models;

use App\Domain\CardSubscriptions\Enums\CardFeeStatus;
use App\Domain\CardSubscriptions\Enums\CardFeeType;
use App\Domain\Shared\Traits\UsesTenantConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CardFee extends Model
{
    /** @use HasFactory<\Database\Factories\Domain\CardSubscriptions\Models\CardFeeFactory> */
    use HasFactory;
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'card_fees';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'related_entity_id',
        'related_entity_type',
        'fee_type',
        'amount',
        'currency',
        'status',
        'ledger_posting_id',
        'charged_at',
        'waived_at',
        'refunded_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fee_type'    => CardFeeType::class,
            'amount'      => 'decimal:2',
            'status'      => CardFeeStatus::class,
            'charged_at'  => 'datetime',
            'waived_at'   => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payer(): BelongsTo
    {
        return $this->user();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function relatedEntity(): MorphTo
    {
        return $this->morphTo('related_entity');
    }
}
