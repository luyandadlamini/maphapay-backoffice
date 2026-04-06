<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserBudget extends Model
{
    protected $table = 'user_budgets';

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    protected $fillable = [
        'user_uuid',
        'monthly_budget',
        'month',
        'year',
    ];

    protected $casts = [
        'monthly_budget' => 'decimal:2',
        'month'          => 'integer',
        'year'           => 'integer',
    ];

    /** @return BelongsTo<\App\Models\User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_uuid', 'uuid');
    }

    public static function getCurrentBudget(string $userUuid): ?self
    {
        return static::where('user_uuid', $userUuid)
            ->where('month', now()->month)
            ->where('year', now()->year)
            ->first();
    }
}
