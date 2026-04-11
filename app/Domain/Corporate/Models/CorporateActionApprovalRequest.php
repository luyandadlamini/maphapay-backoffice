<?php

declare(strict_types=1);

namespace App\Domain\Corporate\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorporateActionApprovalRequest extends Model
{
    use HasUuids;

    protected $table = 'corporate_action_approval_requests';

    protected $fillable = [
        'corporate_profile_id',
        'action_type',
        'action_status',
        'requester_id',
        'reviewer_id',
        'target_type',
        'target_identifier',
        'evidence',
        'action_metadata',
        'reviewed_at',
        'review_reason',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'evidence'        => 'array',
        'action_metadata' => 'array',
        'reviewed_at'     => 'datetime',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
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
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
