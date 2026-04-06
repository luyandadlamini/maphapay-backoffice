<?php

declare(strict_types=1);

namespace App\Domain\FundManagement\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestFunding extends Model
{
    use HasUuids;

    protected $table = 'test_fundings';

    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'account_uuid',
        'user_uuid',
        'asset_code',
        'amount',
        'amount_formatted',
        'reason',
        'notes',
        'status',
        'performed_by',
        'performed_at',
        'completed_at',
    ];

    protected $casts = [
        'amount'           => 'integer',
        'amount_formatted' => 'float',
        'performed_at'     => 'datetime',
        'completed_at'     => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const REASON_TESTING = 'testing';

    public const REASON_REFUND = 'refund';

    public const REASON_COMPENSATION = 'compensation';

    public const REASON_ERROR_CORRECTION = 'error_correction';

    public const REASON_GOODWILL = 'goodwill';

    public const REASON_OTHER = 'other';

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
        if ($asset) {
            return $asset->formatAmount($this->amount);
        }

        return number_format($this->amount / 100, 2) . ' ' . $this->asset_code;
    }

    /**
     * @param  Builder<TestFunding>  $query
     * @return Builder<TestFunding>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * @param  Builder<TestFunding>  $query
     * @return Builder<TestFunding>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * @param  Builder<TestFunding>  $query
     * @return Builder<TestFunding>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }
}
