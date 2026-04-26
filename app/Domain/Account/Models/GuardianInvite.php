<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GuardianInvite extends Model
{
    use HasUuids;

    protected $connection = 'central';

    protected $table = 'guardian_invites';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'minor_account_uuid',
        'invited_by_user_uuid',
        'code',
        'expires_at',
        'claimed_at',
        'claimed_by_user_uuid',
        'status',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];
}
