<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Account\Models\MinorMerchantBonusTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MerchantPartner extends Model
{
    public const DEFAULT_BONUS_MULTIPLIER = 2.0;

    public const DEFAULT_MIN_AGE_ALLOWANCE = 0;

    public const DEFAULT_IS_ACTIVE_FOR_MINORS = true;

    protected $table = 'merchant_partners';

    protected $fillable = [
        'name',
        'category',
        'logo_url',
        'qr_endpoint',
        'api_key',
        'commission_rate',
        'payout_schedule',
        'is_active',
        'tenant_id',
        'bonus_multiplier',
        'min_age_allowance',
        'category_slugs',
        'is_active_for_minors',
        'bonus_terms',
        'updated_by',
    ];

    protected $casts = [
        'commission_rate'      => 'decimal:2',
        'bonus_multiplier'     => 'decimal:2',
        'category_slugs'       => 'array',
        'is_active'            => 'boolean',
        'is_active_for_minors' => 'boolean',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
    ];

    public function getBonusMultiplier(): float
    {
        return (float) ($this->bonus_multiplier ?? self::DEFAULT_BONUS_MULTIPLIER);
    }

    public function getMinAgeAllowance(): int
    {
        return (int) ($this->min_age_allowance ?? self::DEFAULT_MIN_AGE_ALLOWANCE);
    }

    public function isActiveForMinors(): bool
    {
        return (bool) ($this->is_active_for_minors ?? self::DEFAULT_IS_ACTIVE_FOR_MINORS);
    }

    /**
     * @param  array<string>  $categorySlugs
     */
    public function isEligibleForMinors(int $minorAge, ?array $categorySlugs = null): bool
    {
        if (! $this->isActiveForMinors()) {
            return false;
        }

        if ($minorAge < $this->getMinAgeAllowance()) {
            return false;
        }

        if ($categorySlugs !== null && $this->category_slugs !== null) {
            $intersection = array_intersect($categorySlugs, $this->category_slugs);
            if (empty($intersection)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return HasMany<MinorMerchantBonusTransaction, $this>
     */
    public function minorBonusTransactions(): HasMany
    {
        return $this->hasMany(MinorMerchantBonusTransaction::class, 'merchant_partner_id');
    }
}
