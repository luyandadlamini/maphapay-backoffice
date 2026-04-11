<?php

declare(strict_types=1);

namespace App\Domain\Corporate\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorporatePayoutBatch extends Model
{
    use HasUuids;

    protected $table = 'corporate_payout_batches';

    protected $fillable = [
        'public_id',
        'corporate_profile_id',
        'status',
        'total_amount_minor',
        'asset_code',
        'label',
        'cut_off_at',
        'submitted_at',
        'approved_at',
        'executed_at',
        'settled_at',
        'submitted_by_id',
        'approved_by_id',
        'approval_request_id',
        'metadata',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'total_amount_minor' => 'integer',
        'cut_off_at'         => 'datetime',
        'submitted_at'       => 'datetime',
        'approved_at'        => 'datetime',
        'executed_at'        => 'datetime',
        'settled_at'         => 'datetime',
        'metadata'           => 'array',
    ];

    /**
     * @return BelongsTo<CorporateProfile, $this>
     */
    public function corporateProfile(): BelongsTo
    {
        return $this->belongsTo(CorporateProfile::class, 'corporate_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    /**
     * @return BelongsTo<CorporateActionApprovalRequest, $this>
     */
    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(CorporateActionApprovalRequest::class, 'approval_request_id');
    }

    /**
     * @return HasMany<CorporatePayoutBatchItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CorporatePayoutBatchItem::class, 'batch_id');
    }
}
