<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\Models;

use App\Domain\Account\Models\Account;
use App\Domain\Shared\Traits\UsesTenantConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Persistent card model for tracking issued cards.
 *
 * @property string $id
 * @property string $user_id
 * @property string $cardholder_id
 * @property string $issuer_card_token
 * @property string $issuer
 * @property string $last4
 * @property string $network
 * @property string $status
 * @property string $currency
 * @property string|null $label
 * @property string|null $funding_source
 * @property int|null $spend_limit_cents
 * @property string|null $spend_limit_interval
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $expires_at
 * @property Carbon|null $frozen_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $kind
 * @property string|null $card_subscription_id
 * @property float|null $per_transaction_limit
 * @property float|null $daily_limit
 * @property float|null $monthly_limit
 * @property float|null $atm_daily_limit
 * @property float|null $atm_monthly_limit
 * @property bool $online_enabled
 * @property bool $international_enabled
 * @property bool $atm_enabled
 * @property bool $contactless_enabled
 * @property array<string, mixed>|null $blocked_mcc_groups
 */
class Card extends Model
{
    /** @use HasFactory<\Database\Factories\Domain\CardIssuance\Models\CardFactory> */
    use HasFactory;
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'cards';

    protected $fillable = [
        'user_id',
        'cardholder_id',
        'issuer_card_token',
        'issuer',
        'last4',
        'network',
        'status',
        'currency',
        'label',
        'funding_source',
        'spend_limit_cents',
        'spend_limit_interval',
        'metadata',
        'expires_at',
        'frozen_at',
        'cancelled_at',
        'minor_account_uuid',
        // Added by 2026_05_08_000001_alter_cards_add_monetisation_fields
        'tier',
        'kind',
        'lifecycle',
        'lifecycle_config',
        'is_default',
        'per_transaction_limit',
        'daily_limit',
        'monthly_limit',
        'atm_daily_limit',
        'atm_monthly_limit',
        'contactless_per_transaction_limit',
        'online_enabled',
        'international_enabled',
        'atm_enabled',
        'contactless_enabled',
        'blocked_mcc_groups',
        'card_subscription_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata'                          => 'encrypted:array',
            'spend_limit_cents'                 => 'integer',
            'expires_at'                        => 'datetime',
            'frozen_at'                         => 'datetime',
            'cancelled_at'                      => 'datetime',
            // Monetisation fields (2026_05_08_000001_alter_cards_add_monetisation_fields)
            'is_default'                        => 'boolean',
            'per_transaction_limit'             => 'decimal:2',
            'daily_limit'                       => 'decimal:2',
            'monthly_limit'                     => 'decimal:2',
            'atm_daily_limit'                   => 'decimal:2',
            'atm_monthly_limit'                 => 'decimal:2',
            'contactless_per_transaction_limit' => 'decimal:2',
            'online_enabled'                    => 'boolean',
            'international_enabled'             => 'boolean',
            'atm_enabled'                       => 'boolean',
            'contactless_enabled'               => 'boolean',
            'blocked_mcc_groups'                => 'array',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isFrozen(): bool
    {
        return $this->status === 'frozen';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Cardholder, $this>
     */
    public function cardholder(): BelongsTo
    {
        return $this->belongsTo(Cardholder::class);
    }

    public function getMaskedNumber(): string
    {
        return '**** **** **** ' . $this->last4;
    }

    /** @return BelongsTo<Account, self> */
    public function minorAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'minor_account_uuid', 'uuid');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\CardSubscriptions\Models\CardSubscription::class, 'card_subscription_id');
    }

    public function plan(): ?\App\Domain\CardSubscriptions\Models\CardPlan
    {
        return $this->subscription?->plan;
    }
}
