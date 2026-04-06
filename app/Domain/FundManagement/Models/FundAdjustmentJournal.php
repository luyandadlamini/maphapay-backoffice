<?php

declare(strict_types=1);

namespace App\Domain\FundManagement\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundAdjustmentJournal extends Model
{
    use HasUuids;

    protected $table = 'fund_adjustment_journals';

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'account_uuid',
        'user_uuid',
        'asset_code',
        'adjustment_amount',
        'adjustment_type',
        'reason_category',
        'description',
        'supporting_document',
        'performed_by',
        'approved_by',
        'performed_at',
        'approved_at',
        'status',
    ];

    protected $casts = [
        'adjustment_amount' => 'integer',
        'performed_at'      => 'datetime',
        'approved_at'       => 'datetime',
    ];

    public const TYPE_CREDIT = 'credit';

    public const TYPE_DEBIT = 'debit';

    public const REASON_ERROR = 'error';

    public const REASON_GOODWILL = 'goodwill';

    public const REASON_REGULATORY = 'regulatory';

    public const REASON_REFUND = 'refund';

    public const REASON_OTHER = 'other';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVERSED = 'reversed';

    /** @return BelongsTo<\App\Domain\Account\Models\Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Account\Models\Account::class, 'account_uuid', 'uuid');
    }

    /** @return BelongsTo<\App\Models\User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_uuid', 'uuid');
    }

    public function getAssetAttribute(): ?\App\Domain\Asset\Models\Asset
    {
        return \App\Domain\Asset\Models\Asset::where('code', $this->asset_code)->first();
    }

    public function getFormattedAmountAttribute(): string
    {
        $asset = $this->asset;
        $prefix = $this->adjustment_type === self::TYPE_CREDIT ? '+' : '-';
        if ($asset) {
            return $prefix . $asset->formatAmount(abs($this->adjustment_amount));
        }

        return $prefix . number_format(abs($this->adjustment_amount) / 100, 2) . ' ' . $this->asset_code;
    }

    /**
     * @param  Builder<FundAdjustmentJournal>  $query
     * @return Builder<FundAdjustmentJournal>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * @param  Builder<FundAdjustmentJournal>  $query
     * @return Builder<FundAdjustmentJournal>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
