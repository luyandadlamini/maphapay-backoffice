<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerPosting extends Model
{
    use HasUuids;

    protected $table = 'ledger_postings';

    protected $fillable = [
        'authorized_transaction_id',
        'authorized_transaction_trx',
        'posting_type',
        'status',
        'asset_code',
        'transfer_reference',
        'money_request_id',
        'rule_version',
        'entries_hash',
        'metadata',
        'posted_at',
    ];

    protected $casts = [
        'metadata'  => 'array',
        'posted_at' => 'datetime',
    ];

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'ledger_posting_id');
    }
}
