<?php

declare(strict_types=1);

namespace App\Domain\Custodian\Models;

use App\Domain\Custodian\Enums\ProviderFinalityStatus;
use App\Domain\Custodian\Enums\ProviderOperationType;
use App\Domain\Custodian\Enums\ProviderReconciliationStatus;
use App\Domain\Custodian\Enums\ProviderSettlementStatus;
use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderOperation extends Model
{
    use UsesTenantConnection;
    use HasUuids;

    protected $table = 'provider_operations';

    protected $fillable = [
        'provider_family',
        'provider_name',
        'operation_type',
        'operation_key',
        'normalized_event_type',
        'provider_reference',
        'internal_reference',
        'finality_status',
        'settlement_status',
        'reconciliation_status',
        'settlement_reference',
        'reconciliation_reference',
        'ledger_posting_reference',
        'latest_webhook_id',
        'metadata',
    ];

    protected $casts = [
        'operation_type' => ProviderOperationType::class,
        'finality_status' => ProviderFinalityStatus::class,
        'settlement_status' => ProviderSettlementStatus::class,
        'reconciliation_status' => ProviderReconciliationStatus::class,
        'metadata' => 'array',
    ];

    /** @return HasMany<CustodianWebhook, $this> */
    public function webhooks(): HasMany
    {
        return $this->hasMany(CustodianWebhook::class, 'provider_operation_id');
    }
}
