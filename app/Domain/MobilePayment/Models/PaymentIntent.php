<?php

declare(strict_types=1);

namespace App\Domain\MobilePayment\Models;

use App\Domain\Commerce\Models\Merchant;
use App\Domain\MobilePayment\Enums\PaymentAsset;
use App\Domain\MobilePayment\Enums\PaymentIntentStatus;
use App\Domain\MobilePayment\Enums\PaymentNetwork;
use App\Domain\MobilePayment\Events\PaymentIntentCancelled;
use App\Domain\MobilePayment\Events\PaymentIntentConfirmed;
use App\Domain\MobilePayment\Events\PaymentIntentFailed;
use App\Domain\MobilePayment\Exceptions\InvalidStateTransitionException;
use App\Models\User;
use Database\Factories\PaymentIntentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Payment Intent for mobile merchant payments.
 *
 * @property string $id
 * @property string $public_id
 * @property int $user_id
 * @property string $merchant_id
 * @property string $asset
 * @property string $network
 * @property string $amount
 * @property PaymentIntentStatus $status
 * @property bool $shield_enabled
 * @property array<string, mixed>|null $fees_estimate
 * @property string|null $tx_hash
 * @property string|null $tx_explorer_url
 * @property int $confirmations
 * @property int $required_confirmations
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $cancel_reason
 * @property string|null $idempotency_key
 * @property array<string, mixed>|null $metadata
 * @property \Carbon\Carbon $expires_at
 * @property \Carbon\Carbon|null $submitted_at
 * @property \Carbon\Carbon|null $confirmed_at
 * @property \Carbon\Carbon|null $failed_at
 * @property \Carbon\Carbon|null $cancelled_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class PaymentIntent extends Model
{
    /** @use HasFactory<PaymentIntentFactory> */
    use HasFactory;
    use HasUuids;

    protected $table = 'payment_intents';

    protected $fillable = [
        'public_id',
        'user_id',
        'merchant_id',
        'asset',
        'network',
        'amount',
        'status',
        'shield_enabled',
        'fees_estimate',
        'required_confirmations',
        'idempotency_key',
        'metadata',
        'expires_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'id',
        'user_id',
        'merchant_id',
        'idempotency_key',
        'metadata',
        'cancel_reason',
    ];

    protected $casts = [
        'status'                 => PaymentIntentStatus::class,
        'shield_enabled'         => 'boolean',
        'fees_estimate'          => 'array',
        'metadata'               => 'array',
        'confirmations'          => 'integer',
        'required_confirmations' => 'integer',
        'expires_at'             => 'datetime',
        'submitted_at'           => 'datetime',
        'confirmed_at'           => 'datetime',
        'failed_at'              => 'datetime',
        'cancelled_at'           => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Transition to a new status with guard check.
     *
     * @throws InvalidStateTransitionException
     */
    public function transitionTo(PaymentIntentStatus $newStatus): void
    {
        if (! $this->status->canTransitionTo($newStatus)) {
            throw new InvalidStateTransitionException($this->status, $newStatus);
        }

        $this->status = $newStatus;

        match ($newStatus) {
            PaymentIntentStatus::SUBMITTING => $this->submitted_at = now(),
            PaymentIntentStatus::CONFIRMED  => $this->confirmed_at = now(),
            PaymentIntentStatus::FAILED     => $this->failed_at = now(),
            PaymentIntentStatus::CANCELLED  => $this->cancelled_at = now(),
            default                         => null,
        };

        $this->save();

        // Dispatch domain events for projectors and broadcast
        match ($newStatus) {
            PaymentIntentStatus::CONFIRMED => event(new PaymentIntentConfirmed($this)),
            PaymentIntentStatus::FAILED    => event(new PaymentIntentFailed($this)),
            PaymentIntentStatus::CANCELLED => event(new PaymentIntentCancelled($this)),
            default                        => null,
        };
    }

    /**
     * Check if this intent has expired (lazy evaluation).
     */
    public function isExpired(): bool
    {
        if ($this->status->isFinal()) {
            return $this->status === PaymentIntentStatus::EXPIRED;
        }

        return $this->expires_at->isPast();
    }

    /**
     * Expire this intent if it's past its TTL and still active.
     */
    public function expireIfStale(): bool
    {
        if (! $this->isExpired() || $this->status->isFinal()) {
            return false;
        }

        $this->transitionTo(PaymentIntentStatus::EXPIRED);

        return true;
    }

    public function getNetworkEnum(): PaymentNetwork
    {
        return PaymentNetwork::from($this->network);
    }

    public function getAssetEnum(): PaymentAsset
    {
        return PaymentAsset::from($this->asset);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<PaymentIntent> $query
     * @return \Illuminate\Database\Eloquent\Builder<PaymentIntent>
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<PaymentIntent> $query
     * @return \Illuminate\Database\Eloquent\Builder<PaymentIntent>
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            PaymentIntentStatus::CREATED,
            PaymentIntentStatus::AWAITING_AUTH,
            PaymentIntentStatus::SUBMITTING,
            PaymentIntentStatus::PENDING,
        ]);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<PaymentIntent> $query
     * @return \Illuminate\Database\Eloquent\Builder<PaymentIntent>
     */
    public function scopeExpirable($query)
    {
        return $query->whereIn('status', [
            PaymentIntentStatus::CREATED,
            PaymentIntentStatus::AWAITING_AUTH,
        ])->where('expires_at', '<', now());
    }

    /**
     * Format for API response.
     *
     * @return array<string, mixed>
     */
    public function toApiResponse(): array
    {
        $response = [
            'intentId'   => $this->public_id,
            'merchantId' => $this->merchant?->public_id,
            'merchant'   => $this->merchant ? [
                'displayName' => $this->merchant->display_name,
                'iconUrl'     => $this->merchant->icon_url,
            ] : null,
            'asset'         => $this->asset,
            'network'       => $this->network,
            'amount'        => $this->amount,
            'status'        => strtoupper($this->status->value),
            'shieldEnabled' => $this->shield_enabled,
            'feesEstimate'  => $this->fees_estimate,
            'createdAt'     => $this->created_at->toIso8601String(),
            'expiresAt'     => $this->expires_at->toIso8601String(),
        ];

        // Always include tx, confirmations, and error per spec (even when null/zero)
        $response['tx'] = $this->tx_hash ? [
            'hash'        => $this->tx_hash,
            'explorerUrl' => $this->tx_explorer_url,
        ] : null;

        $response['confirmations'] = $this->confirmations ?? 0;
        $response['requiredConfirmations'] = $this->required_confirmations;

        $response['error'] = $this->error_code ? [
            'code'    => $this->error_code,
            'message' => $this->error_message,
        ] : null;

        return $response;
    }

    protected static function newFactory(): \Database\Factories\PaymentIntentFactory
    {
        return \Database\Factories\PaymentIntentFactory::new();
    }
}
