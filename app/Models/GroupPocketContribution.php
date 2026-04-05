<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupPocketContribution extends Model
{
    protected $fillable = ['group_pocket_id', 'user_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    /** @return BelongsTo<GroupPocket, $this> */
    public function groupPocket(): BelongsTo
    {
        return $this->belongsTo(GroupPocket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
