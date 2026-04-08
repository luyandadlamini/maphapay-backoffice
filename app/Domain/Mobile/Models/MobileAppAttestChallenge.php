<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileAppAttestChallenge extends Model
{
    use HasUuids;

    public const PURPOSE_ENROLLMENT = 'enrollment';

    public const PURPOSE_ASSERTION = 'assertion';

    protected $fillable = [
        'mobile_device_id',
        'user_id',
        'mobile_app_attest_key_id',
        'purpose',
        'key_id',
        'challenge_hash',
        'expires_at',
        'consumed_at',
        'metadata',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'consumed_at' => 'datetime',
        'metadata'    => 'array',
    ];

    /**
     * @return BelongsTo<MobileDevice, $this>
     */
    public function mobileDevice(): BelongsTo
    {
        return $this->belongsTo(MobileDevice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MobileAppAttestKey, $this>
     */
    public function appAttestKey(): BelongsTo
    {
        return $this->belongsTo(MobileAppAttestKey::class, 'mobile_app_attest_key_id');
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
