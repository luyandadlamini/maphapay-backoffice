<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Pocket extends Model
{
    use HasUlids;

    protected $table = 'pockets';

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    protected $fillable = [
        'uuid',
        'user_uuid',
        'name',
        'target_amount',
        'current_amount',
        'target_date',
        'category',
        'color',
        'is_completed',
    ];

    protected $casts = [
        'target_amount'  => 'decimal:2',
        'current_amount' => 'decimal:2',
        'target_date'    => 'date',
        'is_completed'   => 'boolean',
    ];

    public const CATEGORY_TRAVEL = 'travel';

    public const CATEGORY_TRANSPORT = 'transport';

    public const CATEGORY_TECH = 'tech';

    public const CATEGORY_EMERGENCY = 'emergency';

    public const CATEGORY_FOOD = 'food';

    public const CATEGORY_HEALTH = 'health';

    public const CATEGORY_EDUCATION = 'education';

    public const CATEGORY_GENERAL = 'general';

    public const CATEGORIES = [
        self::CATEGORY_TRAVEL,
        self::CATEGORY_TRANSPORT,
        self::CATEGORY_TECH,
        self::CATEGORY_EMERGENCY,
        self::CATEGORY_FOOD,
        self::CATEGORY_HEALTH,
        self::CATEGORY_EDUCATION,
        self::CATEGORY_GENERAL,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    /**
     * @return HasOne<PocketSmartRule, $this>
     */
    public function smartRule(): HasOne
    {
        return $this->hasOne(PocketSmartRule::class, 'pocket_id', 'uuid');
    }

    public function getProgressPercentageAttribute(): float
    {
        if ($this->target_amount <= 0) {
            return 0;
        }

        return min(100, ($this->current_amount / $this->target_amount) * 100);
    }

    public function getDaysLeftAttribute(): ?int
    {
        if (! $this->target_date) {
            return null;
        }

        return (int) now()->diffInDays($this->target_date, false);
    }

    public function markAsCompleted(): void
    {
        $this->update(['is_completed' => true]);
    }

    public function addFunds(float $amount): void
    {
        $this->current_amount = bcadd((string) $this->current_amount, (string) $amount, 2);

        if ($this->current_amount >= $this->target_amount) {
            $this->markAsCompleted();
        }

        $this->save();
    }

    public function withdrawFunds(float $amount): void
    {
        $newAmount = bcsub((string) $this->current_amount, (string) $amount, 2);
        $this->current_amount = (float) $newAmount;
        $this->is_completed = false;
        $this->save();
    }
}
