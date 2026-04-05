<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BudgetCategoryTransaction extends Model
{
    protected $table = 'budget_category_transactions';

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    protected $fillable = [
        'user_uuid',
        'category_id',
        'transaction_uuid',
        'amount',
        'transaction_date',
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'transaction_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_uuid', 'uuid');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class, 'category_id');
    }
}
