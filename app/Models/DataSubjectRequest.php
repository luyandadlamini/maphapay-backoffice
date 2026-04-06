<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataSubjectRequest extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_REJECTED = 'rejected';

    public const TYPE_DELETION = 'deletion';

    public const TYPE_EXPORT = 'export';

    public const TYPE_ACCESS = 'access';

    public const TYPE_RECTIFICATION = 'rectification';

    protected $fillable = [
        'user_id',
        'type',
        'status',
        'reason',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'fulfilled_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'fulfilled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_RECEIVED, self::STATUS_IN_REVIEW]);
    }

    public function canFulfill(): bool
    {
        return $this->isPending();
    }
}
