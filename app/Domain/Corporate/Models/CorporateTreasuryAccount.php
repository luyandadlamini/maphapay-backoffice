<?php

declare(strict_types=1);

namespace App\Domain\Corporate\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorporateTreasuryAccount extends Model
{
    use HasUuids;

    protected $table = 'corporate_treasury_accounts';

    protected $fillable = [
        'corporate_profile_id',
        'treasury_account_id',
        'account_type',
        'asset_code',
        'label',
        'is_active',
        'metadata',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
        'metadata'  => 'array',
    ];

    /**
     * @return BelongsTo<CorporateProfile, $this>
     */
    public function corporateProfile(): BelongsTo
    {
        return $this->belongsTo(CorporateProfile::class, 'corporate_profile_id');
    }

    /**
     * @param Builder<static> $query
     */
    public function scopeTreasury(Builder $query): void
    {
        $query->where('account_type', 'treasury');
    }

    /**
     * @param Builder<static> $query
     */
    public function scopeSpend(Builder $query): void
    {
        $query->where('account_type', 'spend');
    }

    /**
     * @param Builder<static> $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
