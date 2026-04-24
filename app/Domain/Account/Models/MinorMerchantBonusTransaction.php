<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MinorMerchantBonusTransaction extends Model
{
    use UsesTenantConnection;
    protected $table = 'minor_merchant_bonus_transactions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'merchant_partner_id',
        'minor_account_uuid',
        'parent_transaction_uuid',
        'bonus_points_awarded',
        'multiplier_applied',
        'amount_szl',
        'status',
        'error_reason',
        'metadata',
    ];

    protected $casts = [
        'bonus_points_awarded' => 'integer',
        'multiplier_applied'  => 'decimal:2',
        'amount_szl'          => 'decimal:2',
        'metadata'            => 'array',
        'created_at'         => 'datetime',
        'updated_at'         => 'datetime',
    ];

    public function merchantPartner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\MerchantPartner::class, 'merchant_partner_id');
    }

    public static function findByParentTransaction(string $parentTransactionUuid): ?self
    {
        return static::where('parent_transaction_uuid', $parentTransactionUuid)->first();
    }
}