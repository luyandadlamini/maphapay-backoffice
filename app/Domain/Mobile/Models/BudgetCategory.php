<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BudgetCategory extends Model
{
    protected $table = 'budget_categories';

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    protected $fillable = [
        'user_uuid',
        'name',
        'slug',
        'icon',
        'budget_amount',
        'sort_order',
        'is_system',
    ];

    protected $casts = [
        'budget_amount' => 'decimal:2',
        'sort_order'    => 'integer',
        'is_system'     => 'boolean',
    ];

    public const SLUG_FOOD = 'food';

    public const SLUG_BILLS = 'bills';

    public const SLUG_TRANSPORT = 'transport';

    public const SLUG_SHOPPING = 'shopping';

    public const SLUG_ENTERTAINMENT = 'entertainment';

    public const SLUG_HEALTH = 'health';

    public const SLUG_EDUCATION = 'education';

    public const SLUG_OTHER = 'other';

    public const DEFAULT_SLUGS = [
        self::SLUG_FOOD,
        self::SLUG_BILLS,
        self::SLUG_TRANSPORT,
        self::SLUG_SHOPPING,
        self::SLUG_ENTERTAINMENT,
        self::SLUG_HEALTH,
        self::SLUG_EDUCATION,
        self::SLUG_OTHER,
    ];

    /** @return BelongsTo<\App\Models\User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_uuid', 'uuid');
    }

    /** @return HasMany<BudgetCategoryTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(BudgetCategoryTransaction::class, 'category_id');
    }

    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }
}
