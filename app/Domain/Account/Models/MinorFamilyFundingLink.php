<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $minor_account_uuid
 * @property string $created_by_user_uuid
 * @property string $created_by_account_uuid
 * @property string $title
 * @property string|null $note
 * @property string $token
 * @property string $status
 * @property string $amount_mode
 * @property string|null $fixed_amount
 * @property string|null $target_amount
 * @property string $collected_amount
 * @property string $asset_code
 * @property array<int, string>|null $provider_options
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $last_funded_at
 */
class MinorFamilyFundingLink extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const AMOUNT_MODE_FIXED = 'fixed';

    public const AMOUNT_MODE_CAPPED = 'capped';

    public const DEFAULT_PROVIDER = 'mtn_momo';

    protected $table = 'minor_family_funding_links';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fixed_amount' => 'decimal:2',
            'target_amount' => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'provider_options' => 'array',
            'expires_at' => 'datetime',
            'last_funded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function minorAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'minor_account_uuid', 'uuid');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function createdByAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'created_by_account_uuid', 'uuid');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_uuid', 'uuid');
    }

    /**
     * @return HasMany<MinorFamilyFundingAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(MinorFamilyFundingAttempt::class, 'funding_link_uuid', 'id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->isExpired();
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED
            || ($this->expires_at !== null && $this->expires_at->isPast());
    }

    public function isFixedAmount(): bool
    {
        return $this->amount_mode === self::AMOUNT_MODE_FIXED;
    }

    public function isCapped(): bool
    {
        return $this->amount_mode === self::AMOUNT_MODE_CAPPED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isTerminal(): bool
    {
        return $this->isExpired() || $this->isCompleted() || $this->isCancelled();
    }

    public function canAcceptFunding(): bool
    {
        return $this->isActive();
    }

    public function remainingAmount(): ?string
    {
        if (! $this->isCapped() || $this->target_amount === null) {
            return null;
        }

        if (! $this->hasValidDecimalAmount($this->target_amount) || ! $this->hasValidDecimalAmount($this->collected_amount)) {
            return null;
        }

        $targetAmount = $this->normaliseNumericAmount($this->target_amount);
        $collectedAmount = $this->normaliseNumericAmount($this->collected_amount);

        if ($targetAmount === null || $collectedAmount === null) {
            return null;
        }

        $remaining = $targetAmount
            ->minus($collectedAmount)
            ->toScale(2, RoundingMode::DOWN);

        if ($remaining->isLessThan(BigDecimal::zero())) {
            return '0.00';
        }

        return $remaining->__toString();
    }

    public function supportsProvider(string $provider): bool
    {
        $provider = trim($provider);
        $options = $this->provider_options;

        if (! is_array($options) || $options === []) {
            return $provider === self::DEFAULT_PROVIDER;
        }

        return in_array($provider, $options, true);
    }

    private function hasValidDecimalAmount(?string $amount): bool
    {
        return is_string($amount) && preg_match('/^-?\d+(?:\.\d+)?$/', trim($amount)) === 1;
    }

    private function normaliseNumericAmount(?string $amount): ?BigDecimal
    {
        if (! $this->hasValidDecimalAmount($amount)) {
            return null;
        }

        return BigDecimal::of(trim((string) $amount));
    }
}
