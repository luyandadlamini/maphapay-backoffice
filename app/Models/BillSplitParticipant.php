<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillSplitParticipant extends Model
{
    public $timestamps = false;

    protected $fillable = ['bill_split_id', 'user_id', 'amount', 'status', 'paid_at'];

    protected $casts = [
        'amount'  => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    /** @return BelongsTo<BillSplit, $this> */
    public function billSplit(): BelongsTo
    {
        return $this->belongsTo(BillSplit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
