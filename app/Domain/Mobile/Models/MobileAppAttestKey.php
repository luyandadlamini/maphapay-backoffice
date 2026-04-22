<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileAppAttestKey extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'mobile_device_id',
        'user_id',
        'key_id',
        'status',
        'attested_at',
        'last_assertion_at',
        'metadata',
    ];

    protected $casts = [
        'attested_at'       => 'datetime',
        'last_assertion_at' => 'datetime',
        'metadata'          => 'array',
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
}
