<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use App\Models\MtnMomoTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $minor_account_uuid
 * @property string $actor_user_uuid
 * @property string $source_account_uuid
 * @property string $status
 * @property string $provider_name
 * @property string $recipient_name
 * @property string $recipient_msisdn
 * @property string $amount
 * @property string $asset_code
 * @property string|null $note
 * @property string|null $provider_reference_id
 * @property string|null $mtn_momo_transaction_id
 * @property \Illuminate\Support\Carbon|null $wallet_refunded_at
 * @property string|null $failed_reason
 * @property string $idempotency_key
 */
class MinorFamilySupportTransfer extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    public const STATUS_PENDING_PROVIDER = 'pending_provider';

    public const STATUS_SUCCESSFUL = 'successful';

    public const STATUS_FAILED_REFUNDED = 'failed_refunded';

    public const STATUS_FAILED_UNRECONCILED = 'failed_unreconciled';

    protected $table = 'minor_family_support_transfers';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount'             => 'decimal:2',
            'wallet_refunded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function minorAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'minor_account_uuid', 'uuid');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'source_account_uuid', 'uuid');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_uuid', 'uuid');
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
        return in_array($this->status, [
            self::STATUS_FAILED_REFUNDED,
            self::STATUS_FAILED_UNRECONCILED,
        ], true);
    }

    public function isRefunded(): bool
    {
        return $this->wallet_refunded_at !== null || $this->status === self::STATUS_FAILED_REFUNDED;
    }

    public function isTerminal(): bool
    {
        return ! $this->isPendingProvider();
    }
}
