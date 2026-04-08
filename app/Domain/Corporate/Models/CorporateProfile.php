<?php

declare(strict_types=1);

namespace App\Domain\Corporate\Models;

use App\Domain\Corporate\Enums\CorporateCapability;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorporateProfile extends Model
{
    use HasUuids;

    protected $fillable = [
        'team_id',
        'legal_name',
        'registration_number',
        'tax_id',
        'organization_type',
        'kyb_status',
        'operating_status',
        'contract_reference',
        'pricing_reference',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<CorporateCapabilityGrant, $this>
     */
    public function capabilityGrants(): HasMany
    {
        return $this->hasMany(CorporateCapabilityGrant::class);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function grantCapabilityToUser(
        User $user,
        CorporateCapability|string $capability,
        ?User $grantedBy = null,
        ?string $approvalThresholdAmount = null,
        array $metadata = [],
    ): CorporateCapabilityGrant {
        $capabilityValue = $capability instanceof CorporateCapability ? $capability->value : $capability;

        /** @var CorporateCapabilityGrant $grant */
        $grant = CorporateCapabilityGrant::query()->updateOrCreate(
            [
                'corporate_profile_id' => $this->id,
                'user_id' => $user->id,
                'capability' => $capabilityValue,
            ],
            [
                'granted_by_user_id' => $grantedBy?->id,
                'approval_threshold_amount' => $approvalThresholdAmount,
                'metadata' => $metadata === [] ? null : $metadata,
            ],
        );

        return $grant;
    }
}
