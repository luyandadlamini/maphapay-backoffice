<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasUuids;

    protected $table = 'ledger_entries';

    protected $fillable = [
        'ledger_posting_id',
        'account_uuid',
        'asset_code',
        'signed_amount',
        'entry_type',
        'metadata',
    ];

    protected $casts = [
        'signed_amount' => 'integer',
        'metadata'      => 'array',
    ];

    /** @return BelongsTo<LedgerPosting, $this> */
    public function posting(): BelongsTo
    {
        return $this->belongsTo(LedgerPosting::class, 'ledger_posting_id');
    }
}
