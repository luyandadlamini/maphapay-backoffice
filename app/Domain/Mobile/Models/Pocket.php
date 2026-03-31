<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Pocket extends Model
{
    use HasUuids;

    protected $table = 'pockets';

    protected $fillable = [
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
        'target_amount' => 'decimal:2',
        'current_amount' => 'decimal:2',
        'target_date' => 'date',
        'is_completed' => 'boolean',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_uuid', 'uuid');
    }

    public function smartRule(): HasOne
    {
        return $this->hasOne(PocketSmartRule::class, 'pocket_id');
    }

    public function getProgressPercentageAttribute(): float
    {
        if ($this->target_amount <= 0) {
            return 0;
        }

        return min(100, ($this->current_amount / $this->target_amount) * 100);
    }

    public function getDaysLeftAttribute(): int|null
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