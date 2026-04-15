<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountProfileCompany extends Model
{
    use HasUuids, UsesTenantConnection;

    protected $table = 'account_profiles_company';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = [];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_uuid', 'uuid');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AccountProfileCompanyDocument::class, 'company_profile_id', 'id');
    }
}