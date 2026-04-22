<?php

declare(strict_types=1);

namespace App\Domain\Corporate\Models;

use App\Domain\Commerce\Models\Merchant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessOnboardingCase extends Model
{
    use HasUuids;

    protected $fillable = [
        'public_id',
        'team_id',
        'corporate_profile_id',
        'merchant_id',
        'relationship_type',
        'status',
        'business_name',
        'business_type',
        'country',
        'contact_email',
        'requested_capabilities',
        'business_details',
        'evidence',
        'risk_assessment',
        'activation_requirements',
        'submitted_by_user_id',
        'reviewed_by_user_id',
        'approved_by_user_id',
        'approved_at',
        'last_decision_reason',
        'metadata',
    ];

    protected $casts = [
        'requested_capabilities'  => 'array',
        'business_details'        => 'array',
        'evidence'                => 'array',
        'risk_assessment'         => 'array',
        'activation_requirements' => 'array',
        'metadata'                => 'array',
        'approved_at'             => 'datetime',
    ];

    /**
     * @return BelongsTo<CorporateProfile, $this>
     */
    public function corporateProfile(): BelongsTo
    {
        return $this->belongsTo(CorporateProfile::class);
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return HasMany<BusinessOnboardingCaseStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(BusinessOnboardingCaseStatusHistory::class)
            ->orderBy('id');
    }
}
