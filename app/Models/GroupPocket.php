<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class GroupPocket extends Model
{
    protected $fillable = [
        'thread_id', 'created_by', 'name', 'category', 'color',
        'target_amount', 'current_amount', 'target_date',
        'is_completed', 'is_locked', 'status',
    ];

    protected $casts = [
        'target_amount'  => 'decimal:2',
        'current_amount' => 'decimal:2',
        'target_date'    => 'date',
        'is_completed'   => 'boolean',
        'is_locked'      => 'boolean',
    ];

    public const REGULATORY_MAX = 100000.00;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CLOSED = 'closed';

    /** @return BelongsTo<Thread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<GroupPocketContribution, $this> */
    public function contributions(): HasMany
    {
        return $this->hasMany(GroupPocketContribution::class);
    }

    /** @return HasMany<GroupPocketWithdrawalRequest, $this> */
    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(GroupPocketWithdrawalRequest::class);
    }

    /** @return HasManyThrough<ThreadParticipant, Thread, $this> */
    public function participants(): HasManyThrough
    {
        return $this->hasManyThrough(ThreadParticipant::class, Thread::class, 'id', 'thread_id', 'thread_id', 'id');
    }

    public function addFunds(string $amount): void
    {
        $this->current_amount = bcadd((string) $this->current_amount, $amount, 2);

        if (bccomp((string) $this->current_amount, (string) $this->target_amount, 2) >= 0) {
            $this->is_completed = true;
            $this->status = self::STATUS_COMPLETED;
        }

        $this->save();
    }

    public function deductFunds(string $amount): void
    {
        $this->current_amount = bcsub((string) $this->current_amount, $amount, 2);
        $this->is_completed = false;

        if ($this->status === self::STATUS_COMPLETED) {
            $this->status = self::STATUS_ACTIVE;
        }

        $this->save();
    }

    public function wouldExceedRegulatoryMax(string $amount): bool
    {
        return bccomp(
            bcadd((string) $this->current_amount, $amount, 2),
            (string) self::REGULATORY_MAX,
            2
        ) > 0;
    }
}
