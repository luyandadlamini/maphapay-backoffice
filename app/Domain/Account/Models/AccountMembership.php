<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use App\Models\User;
use Database\Factories\Domain\Account\Models\AccountMembershipFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountMembership extends Model
{
    /** @use HasFactory<AccountMembershipFactory> */
    use HasFactory;
    use HasUuids;

    public const PERSONAL_ACCOUNT_TYPES = ['personal', 'standard'];

    protected $connection = 'central';

    protected $table = 'account_memberships';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'permissions_override' => 'array',
        'capabilities'         => 'array',
        'joined_at'            => 'datetime',
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

    /**
     * @param  Builder<AccountMembership>  $query
     * @return Builder<AccountMembership>
     */
    public function scopePersonalWallet(Builder $query): Builder
    {
        return $query->whereIn('account_type', self::PERSONAL_ACCOUNT_TYPES);
    }
}
