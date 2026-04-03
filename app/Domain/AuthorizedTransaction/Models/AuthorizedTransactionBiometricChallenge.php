<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Models;

use App\Domain\Mobile\Models\MobileDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorizedTransactionBiometricChallenge extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    public const CHALLENGE_TTL_SECONDS = 300;

    protected $table = 'authorized_transaction_biometric_challenges';

    protected $fillable = [
        'authorized_transaction_id',
        'mobile_device_id',
        'user_id',
        'challenge',
        'status',
        'ip_address',
        'expires_at',
        'verified_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(AuthorizedTransaction::class, 'authorized_transaction_id');
    }

    public function mobileDevice(): BelongsTo
    {
        return $this->belongsTo(MobileDevice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function markAsVerified(): void
    {
        $this->update([
            'status' => self::STATUS_VERIFIED,
            'verified_at' => now(),
        ]);
    }

    public function markAsFailed(): void
    {
        $this->update(['status' => self::STATUS_FAILED]);
    }

    public function markAsExpired(): void
    {
        $this->update(['status' => self::STATUS_EXPIRED]);
    }

    public static function createForTransaction(
        AuthorizedTransaction $transaction,
        MobileDevice $device,
        ?string $ipAddress = null,
    ): self {
        return self::create([
            'authorized_transaction_id' => $transaction->id,
            'mobile_device_id' => $device->id,
            'user_id' => $transaction->user_id,
            'challenge' => bin2hex(random_bytes(32)),
            'status' => self::STATUS_PENDING,
            'ip_address' => $ipAddress,
            'expires_at' => now()->addSeconds(self::CHALLENGE_TTL_SECONDS),
        ]);
    }
}
