<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use App\Domain\Account\Constants\MinorCardConstants;
use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MinorCardRequest extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'minor_card_requests';

    protected $fillable = [
        'minor_account_uuid',
        'requested_by_user_uuid',
        'request_type',
        'status',
        'requested_network',
        'requested_daily_limit',
        'requested_monthly_limit',
        'requested_single_limit',
        'denial_reason',
        'approved_by_user_uuid',
        'approved_at',
        'expires_at',
    ];

    protected $casts = [
        'requested_daily_limit' => 'decimal:2',
        'requested_monthly_limit' => 'decimal:2',
        'requested_single_limit' => 'decimal:2',
        'approved_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** @return BelongsTo<Account, self> */
    public function minorAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'minor_account_uuid');
    }

    public function isPending(): bool
    {
        return $this->status === MinorCardConstants::STATUS_PENDING_APPROVAL;
    }

    public function canBeApproved(): bool
    {
        return $this->isPending()
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, MinorCardConstants::VALID_TRANSITIONS[$this->status] ?? [], true);
    }

    public function transitionTo(string $newStatus): void
    {
        $currentStatus = $this->status;

        if (! $this->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Invalid state transition from [{$currentStatus}] to [{$newStatus}]. Valid transitions: "
                . implode(', ', MinorCardConstants::VALID_TRANSITIONS[$currentStatus] ?? [])
            );
        }

        $this->status = $newStatus;
        $this->save();
    }
}
