<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantPartner extends Model
{
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
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
    ];

    public function getBonusMultiplier(): float
    {
        return (float) ($this->bonus_multiplier ?? 2.0);
    }

    public function getMinAgeAllowance(): int
    {
        return (int) ($this->min_age_allowance ?? 0);
    }

    public function isActiveForMinors(): bool
    {
        return (bool) ($this->is_active_for_minors ?? true);
    }

    public function isEligibleForMinor(int $minorAge, ?array $categorySlugs = null): bool
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
}
