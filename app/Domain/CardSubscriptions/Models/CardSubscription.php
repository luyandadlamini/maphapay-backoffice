<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Models;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorCardRequest;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\Shared\Traits\UsesTenantConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $subscriber_user_id
 * @property string|null $payer_user_id
 * @property string $card_plan_id
 * @property CardSubscriptionStatus $status
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $next_billing_date
 * @property int $failed_payment_count
 * @property Carbon|null $grace_period_ends_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $cancelled_at
 * @property bool $is_minor_subscription
 * @property string|null $guardian_user_id
 * @property string|null $minor_account_uuid
 * @property string|null $minor_card_request_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CardSubscription extends Model
{
    /** @use HasFactory<\Database\Factories\Domain\CardSubscriptions\Models\CardSubscriptionFactory> */
    use HasFactory;
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'card_subscriptions';

    protected $fillable = [
        'tenant_id',
        'subscriber_user_id',
        'payer_user_id',
        'card_plan_id',
        'status',
        'current_period_start',
        'current_period_end',
        'next_billing_date',
        'failed_payment_count',
        'grace_period_ends_at',
        'suspended_at',
        'cancelled_at',
        'is_minor_subscription',
        'guardian_user_id',
        'minor_account_uuid',
        'minor_card_request_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status'                => CardSubscriptionStatus::class,
            'current_period_start'  => 'datetime',
            'current_period_end'    => 'datetime',
            'next_billing_date'     => 'datetime',
            'failed_payment_count'  => 'integer',
            'grace_period_ends_at'  => 'datetime',
            'suspended_at'          => 'datetime',
            'cancelled_at'          => 'datetime',
            'is_minor_subscription' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subscriber_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_user_id');
    }

    /**
     * @return BelongsTo<CardPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(CardPlan::class, 'card_plan_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function minorAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'minor_account_uuid', 'uuid');
    }

    /**
     * @return BelongsTo<MinorCardRequest, $this>
     */
    public function minorCardRequest(): BelongsTo
    {
        return $this->belongsTo(MinorCardRequest::class, 'minor_card_request_id');
    }

    /**
     * @return HasMany<CardSubscriptionBillingAttempt, $this>
     */
    public function billingAttempts(): HasMany
    {
        return $this->hasMany(CardSubscriptionBillingAttempt::class, 'card_subscription_id');
    }

    /**
     * @return HasMany<Card, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(Card::class, 'card_subscription_id');
    }

    /**
     * @return HasMany<PhysicalCardOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(PhysicalCardOrder::class, 'card_subscription_id');
    }

    /**
     * @return MorphMany<CardFee, $this>
     */
    public function fees(): MorphMany
    {
        return $this->morphMany(CardFee::class, 'related_entity');
    }

    /**
     * @return MorphMany<CardAuditLog, $this>
     */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(CardAuditLog::class, 'entity');
    }
}
