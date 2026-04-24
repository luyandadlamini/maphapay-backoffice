<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class MinorCardLimit extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'minor_card_limits';

    protected $fillable = [
        'minor_account_uuid',
        'daily_limit',
        'monthly_limit',
        'single_transaction_limit',
        'is_active',
    ];

    protected $casts = [
        'daily_limit'              => 'decimal:2',
        'monthly_limit'            => 'decimal:2',
        'single_transaction_limit' => 'decimal:2',
        'is_active'                => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $limit) {
            $limit->validateHierarchy();
        });

        static::updating(function (self $limit) {
            $limit->validateHierarchy();
        });
    }

    public function validateHierarchy(): void
    {
        $validator = Validator::make(
            ['daily_limit' => $this->daily_limit, 'monthly_limit' => $this->monthly_limit, 'single_transaction_limit' => $this->single_transaction_limit],
            [
                'daily_limit'              => 'required|numeric|min:0.01',
                'monthly_limit'            => 'required|numeric|min:0.01',
                'single_transaction_limit' => 'required|numeric|min:0.01',
            ],
            [
                'daily_limit.min'              => 'Daily limit must be greater than 0',
                'monthly_limit.min'            => 'Monthly limit must be greater than 0',
                'single_transaction_limit.min' => 'Single transaction limit must be greater than 0',
            ]
        );

        if ($validator->fails()) {
            throw new InvalidArgumentException($validator->errors()->first());
        }

        if ((float) $this->daily_limit > (float) $this->monthly_limit) {
            throw new InvalidArgumentException('Daily limit cannot exceed monthly limit');
        }

        // Uses daysInMonth; fallback multiplier is config('minor_family.card_limit_period_days', 30)
        $maxSingleMonthly = (float) $this->single_transaction_limit * now()->daysInMonth;
        if ((float) $this->monthly_limit > $maxSingleMonthly) {
            throw new InvalidArgumentException('Monthly limit cannot exceed single transaction limit multiplied by days in current month');
        }
    }

    public function isValid(): bool
    {
        try {
            $this->validateHierarchy();

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** @return BelongsTo<Account, self> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'minor_account_uuid');
    }
}
