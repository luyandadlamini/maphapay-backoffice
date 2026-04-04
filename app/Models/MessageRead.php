<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageRead extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = ['thread_id', 'user_id', 'last_read_message_id', 'read_at'];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    /** @return BelongsTo<Thread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function setKeysForSaveQuery($query): Builder
    {
        return $query->where('thread_id', $this->getAttribute('thread_id'))
            ->where('user_id', $this->getAttribute('user_id'));
    }
}
