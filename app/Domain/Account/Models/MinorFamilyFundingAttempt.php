<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use App\Models\MtnMomoTransaction;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $funding_link_uuid
 * @property string $minor_account_uuid
 * @property string $status
 * @property string $sponsor_name
 * @property string $sponsor_msisdn
 * @property string $amount
 * @property string $asset_code
 * @property string $provider_name
 * @property string|null $provider_reference_id
 * @property string|null $mtn_momo_transaction_id
 * @property \Illuminate\Support\Carbon|null $wallet_credited_at
 * @property string|null $failed_reason
 * @property string $dedupe_hash
 */
class MinorFamilyFundingAttempt extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    public const STATUS_PENDING_PROVIDER = 'pending_provider';

    public const STATUS_SUCCESSFUL = 'successful';

    public const STATUS_SUCCESSFUL_UNCREDITED = 'successful_uncredited';

    public const STATUS_CREDITED = 'credited';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_EXPIRED_PROVIDER_PENDING = 'expired_provider_pending';

    protected $table = 'minor_family_funding_attempts';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'wallet_credited_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MinorFamilyFundingLink, $this>
     */
    public function fundingLink(): BelongsTo
    {
        return $this->belongsTo(MinorFamilyFundingLink::class, 'funding_link_uuid', 'id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function minorAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'minor_account_uuid', 'uuid');
    }

    /**
     * @return BelongsTo<MtnMomoTransaction, $this>
     */
    public function mtnMomoTransaction(): BelongsTo
    {
        return $this->belongsTo(MtnMomoTransaction::class, 'mtn_momo_transaction_id', 'id');
    }

    public function isPendingProvider(): bool
    {
        return $this->status === self::STATUS_PENDING_PROVIDER;
    }

    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCESSFUL;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    public function isSuccessfulUncredited(): bool
    {
        return $this->status === self::STATUS_SUCCESSFUL_UNCREDITED;
    }

    public function isCredited(): bool
    {
        return $this->status === self::STATUS_CREDITED;
    }

    public function isExpiredProviderPending(): bool
    {
        return $this->status === self::STATUS_EXPIRED_PROVIDER_PENDING;
    }

    public function requiresCreditReconciliation(): bool
    {
        return $this->isSuccessfulUncredited();
    }
}
