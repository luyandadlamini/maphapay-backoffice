<?php

declare(strict_types=1);

namespace App\Domain\AgentProtocol\Models;

use App\Domain\AgentProtocol\Enums\MandateStatus;
use App\Domain\AgentProtocol\Enums\MandateType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AP2 Agent Mandate model.
 *
 * Tracks mandate lifecycle from draft through execution to completion.
 *
 * @property string $uuid
 * @property string $type
 * @property string $status
 * @property string $issuer_did
 * @property string $subject_did
 * @property array<string,mixed> $payload
 * @property string|null $vdc_hash
 * @property array<string>|null $payment_references
 * @property string|null $executed_at
 * @property string|null $completed_at
 * @property string|null $expires_at
 * @property int|null $amount_cents
 * @property string|null $currency
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class AgentMandate extends Model
{
    use HasUuids;

    protected $table = 'agent_mandates';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'type',
        'status',
        'issuer_did',
        'subject_did',
        'payload',
        'vdc_hash',
        'payment_references',
        'executed_at',
        'completed_at',
        'expires_at',
        'amount_cents',
        'currency',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload'            => 'array',
            'payment_references' => 'array',
            'amount_cents'       => 'integer',
        ];
    }

    public function getTypeEnum(): MandateType
    {
        return MandateType::from($this->type);
    }

    public function getStatusEnum(): MandateStatus
    {
        return MandateStatus::from($this->status);
    }

    public function isActive(): bool
    {
        return $this->getStatusEnum()->isActive();
    }

    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return strtotime($this->expires_at) < time();
    }

    /**
     * @return HasMany<MandatePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(MandatePayment::class, 'mandate_id', 'uuid');
    }
}
