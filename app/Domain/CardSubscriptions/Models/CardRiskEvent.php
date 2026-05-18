<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Models;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Enums\CardRiskEventStatus;
use App\Domain\CardSubscriptions\Enums\CardRiskSeverity;
use App\Domain\Shared\Traits\UsesTenantConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardRiskEvent extends Model
{
    /** @use HasFactory<\Database\Factories\Domain\CardSubscriptions\Models\CardRiskEventFactory> */
    use HasFactory;
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'card_risk_events';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'card_id',
        'event_type',
        'severity',
        'description',
        'metadata',
        'status',
        'assigned_to_admin_id',
        'resolved_at',
        'resolution_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'severity'    => CardRiskSeverity::class,
            'metadata'    => 'array',
            'status'      => CardRiskEventStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Card, $this>
     */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedToAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_admin_id');
    }
}
