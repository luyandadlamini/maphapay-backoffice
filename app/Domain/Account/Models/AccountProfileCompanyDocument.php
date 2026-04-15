<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountProfileCompanyDocument extends Model
{
    use HasUuids, UsesTenantConnection;

    protected $table = 'account_profiles_company_documents';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = [];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'verified_at' => 'datetime',
        'file_size' => 'integer',
    ];

    public const DOCUMENT_TYPES = [
        'certificate_of_incorporation' => 'Certificate of Incorporation',
        'form_j' => 'Form J (Directors List)',
        'memo_articles' => 'Memorandum & Articles of Association',
        'directors_id' => 'Directors Identity Documents',
        'trading_license' => 'Trading License',
        'proof_of_address' => 'Proof of Business Address',
        'bank_statement' => 'Bank Statement',
        'owners_id' => 'Beneficial Owners Identity',
        'tin_certificate' => 'TIN Certificate',
    ];

    public const REQUIRED_BY_TYPE = [
        'pty_ltd' => [
            'certificate_of_incorporation',
            'form_j',
            'memo_articles',
            'directors_id',
            'trading_license',
        ],
        'public' => [
            'certificate_of_incorporation',
            'form_j',
            'memo_articles',
            'directors_id',
            'trading_license',
        ],
        'sole_trader' => [
            'trading_license',
            'proof_of_address',
            'bank_statement',
        ],
        'informal' => [], // No documents required
    ];

    public function companyProfile(): BelongsTo
    {
        return $this->belongsTo(AccountProfileCompany::class, 'company_profile_id', 'id');
    }
}