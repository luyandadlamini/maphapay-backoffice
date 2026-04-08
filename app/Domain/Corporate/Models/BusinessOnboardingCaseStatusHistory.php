<?php

declare(strict_types=1);

namespace App\Domain\Corporate\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessOnboardingCaseStatusHistory extends Model
{
    protected $table = 'business_onboarding_case_status_history';

    protected $fillable = [
        'business_onboarding_case_id',
        'from_status',
        'to_status',
        'actor_user_id',
        'reason',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * @return BelongsTo<BusinessOnboardingCase, $this>
     */
    public function businessOnboardingCase(): BelongsTo
    {
        return $this->belongsTo(BusinessOnboardingCase::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
