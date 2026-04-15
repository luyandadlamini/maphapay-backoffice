<?php

namespace App\Domain\Account\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountMembership extends Model
{
    use HasUuids;

    protected $connection = 'central';
    protected $table = 'account_memberships';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = [];

    protected $casts = [
        'permissions_override' => 'array',
        'capabilities' => 'array',
        'joined_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForUser($query, string $userUuid)
    {
        return $query->where('user_uuid', $userUuid);
    }

    public function scopeForAccount($query, string $accountUuid)
    {
        return $query->where('account_uuid', $accountUuid);
    }
}
