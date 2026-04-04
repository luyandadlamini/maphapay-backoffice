<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillSplit extends Model
{
    protected $fillable = [
        'message_id', 'thread_id', 'created_by', 'description',
        'total_amount', 'asset_code', 'split_method', 'status',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

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

    /** @return HasMany<BillSplitParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(BillSplitParticipant::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
