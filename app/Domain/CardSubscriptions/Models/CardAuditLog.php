<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Models;

use App\Domain\CardSubscriptions\Enums\CardActorType;
use App\Domain\Shared\Traits\UsesTenantConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardAuditLog extends Model
{
    /** @use HasFactory<\Database\Factories\Domain\CardSubscriptions\Models\CardAuditLogFactory> */
    use HasFactory;
    use HasUuids;
    use UsesTenantConnection;

    public const UPDATED_AT = null;

    protected $table = 'card_audit_logs';

    protected $fillable = [
        'tenant_id',
        'actor_type',
        'actor_id',
        'action',
        'entity_type',
        'entity_id',
        'before_state',
        'after_state',
        'metadata',
        'ip_address',
        'device_id',
        'user_agent',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_type'   => CardActorType::class,
            'before_state' => 'array',
            'after_state'  => 'array',
            'metadata'     => 'array',
            'created_at'   => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
