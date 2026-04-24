<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string                   $id
 * @property string                   $minor_account_uuid
 * @property string                   $guardian_account_uuid
 * @property string                   $from_account_uuid
 * @property string                   $to_account_uuid
 * @property string                   $amount
 * @property string                   $asset_code
 * @property string|null              $note
 * @property string                   $merchant_category
 * @property string                   $status  pending|approved|declined|cancelled
 * @property \Carbon\Carbon           $expires_at
 * @property \Carbon\Carbon|null      $decided_at
 * @property \Carbon\Carbon           $created_at
 * @property \Carbon\Carbon           $updated_at
 */
class MinorSpendApproval extends Model
{
    /** @use HasFactory<\Database\Factories\Domain\Account\MinorSpendApprovalFactory> */
    use HasFactory;
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'minor_spend_approvals';

    public $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Scope: only pending approvals that have not yet expired. */
    public function scopeActionable($query)
    {
        return $query->where('status', 'pending')
                     ->where('expires_at', '>', now());
    }

    protected static function newFactory(): \Database\Factories\Domain\Account\MinorSpendApprovalFactory
    {
        return \Database\Factories\Domain\Account\MinorSpendApprovalFactory::new();
    }
}
