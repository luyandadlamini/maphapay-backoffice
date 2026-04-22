<?php

declare(strict_types=1);

namespace App\Domain\Corporate\Models;

use App\Domain\Corporate\Enums\CorporateCapability;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorporateCapabilityGrant extends Model
{
    use HasUuids;

    protected $fillable = [
        'corporate_profile_id',
        'user_id',
        'capability',
        'granted_by_user_id',
        'approval_threshold_amount',
        'metadata',
    ];

    protected $casts = [
        'capability'                => CorporateCapability::class,
        'approval_threshold_amount' => 'decimal:2',
        'metadata'                  => 'array',
    ];

    /**
     * @return BelongsTo<CorporateProfile, $this>
     */
    public function corporateProfile(): BelongsTo
    {
        return $this->belongsTo(CorporateProfile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
