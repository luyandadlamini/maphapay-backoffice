<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Thread extends Model
{
    protected $fillable = ['type', 'name', 'avatar_url', 'created_by', 'max_participants', 'settings'];

    protected $casts = [
        'settings'         => 'array',
        'max_participants' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ThreadParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ThreadParticipant::class);
    }

    /** @return HasMany<ThreadParticipant, $this> */
    public function activeParticipants(): HasMany
    {
        return $this->hasMany(ThreadParticipant::class)->whereNull('left_at');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return HasOne<Message, $this> */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /** @return HasMany<BillSplit, $this> */
    public function billSplits(): HasMany
    {
        return $this->hasMany(BillSplit::class);
    }

    /** @return HasMany<GroupPocket, $this> */
    public function groupPockets(): HasMany
    {
        return $this->hasMany(GroupPocket::class);
    }

    /** @return HasMany<MessageRead, $this> */
    public function reads(): HasMany
    {
        return $this->hasMany(MessageRead::class);
    }

    public function isGroup(): bool
    {
        return $this->type === 'group';
    }

    public function isDirect(): bool
    {
        return $this->type === 'direct';
    }
}
