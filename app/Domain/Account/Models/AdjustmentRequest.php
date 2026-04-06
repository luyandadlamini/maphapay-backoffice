<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdjustmentRequest extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'account_id',
        'requester_id',
        'reviewer_id',
        'type', // 'credit' or 'debit'
        'amount',
        'reason',
        'attachment_path',
        'status', // 'pending', 'approved', 'rejected'
        'reviewed_at',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'reviewed_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
