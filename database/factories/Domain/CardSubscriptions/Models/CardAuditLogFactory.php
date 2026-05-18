<?php

declare(strict_types=1);

namespace Database\Factories\Domain\CardSubscriptions\Models;

use App\Domain\CardSubscriptions\Models\CardAuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CardAuditLog>
 */
class CardAuditLogFactory extends Factory
{
    protected $model = CardAuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id'    => null,
            'actor_type'   => 'system',
            'actor_id'     => null,
            'action'       => 'subscription.created',
            'entity_type'  => 'card_subscription',
            'entity_id'    => Str::uuid()->toString(),
            'before_state' => null,
            'after_state'  => ['status' => 'active'],
            'metadata'     => [],
            'ip_address'   => null,
            'device_id'    => null,
            'user_agent'   => null,
            'created_at'   => now(),
        ];
    }
}
