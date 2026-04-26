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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata'          => 'encrypted:array',
            'spend_limit_cents' => 'integer',
            'expires_at'        => 'datetime',
            'frozen_at'         => 'datetime',
            'cancelled_at'      => 'datetime',
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
}
