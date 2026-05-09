<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Models;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Enums\PhysicalCardDeliveryMethod;
use App\Domain\CardSubscriptions\Enums\PhysicalCardOrderStatus;
use App\Domain\Shared\Traits\UsesTenantConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhysicalCardOrder extends Model
{
    /** @use HasFactory<\Database\Factories\Domain\CardSubscriptions\Models\PhysicalCardOrderFactory> */
    use HasFactory;
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'physical_card_orders';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'card_subscription_id',
        'card_id',
        'order_status',
        'delivery_method',
        'delivery_address',
        'collection_point_id',
        'issuance_fee',
        'delivery_fee',
        'tracking_reference',
        'requested_at',
        'paid_at',
        'approved_at',
        'production_at',
        'dispatched_at',
        'ready_for_collection_at',
        'delivered_at',
        'activated_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_status'            => PhysicalCardOrderStatus::class,
            'delivery_method'         => PhysicalCardDeliveryMethod::class,
            'delivery_address'        => 'array',
            'issuance_fee'            => 'decimal:2',
            'delivery_fee'            => 'decimal:2',
            'requested_at'            => 'datetime',
            'paid_at'                 => 'datetime',
            'approved_at'             => 'datetime',
            'production_at'           => 'datetime',
            'dispatched_at'           => 'datetime',
            'ready_for_collection_at' => 'datetime',
            'delivered_at'            => 'datetime',
            'activated_at'            => 'datetime',
            'cancelled_at'            => 'datetime',
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
     * @return BelongsTo<CardSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CardSubscription::class, 'card_subscription_id');
    }

    /**
     * @return BelongsTo<Card, $this>
     */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
