<?php

declare(strict_types=1);

namespace App\Domain\Corporate\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorporatePayoutBatchItem extends Model
{
    use HasUuids;

    protected $table = 'corporate_payout_batch_items';

    protected $fillable = [
        'batch_id',
        'beneficiary_identifier',
        'amount_minor',
        'asset_code',
        'reference',
        'status',
        'error_reason',
        'executed_at',
        'settled_at',
        'metadata',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'amount_minor' => 'integer',
        'executed_at'  => 'datetime',
        'settled_at'   => 'datetime',
        'metadata'     => 'array',
    ];

    /**
     * @return BelongsTo<CorporatePayoutBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(CorporatePayoutBatch::class, 'batch_id');
    }
}
