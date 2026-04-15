<?php

declare(strict_types=1);

namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AccountAuditLog extends Model
{
    use HasUuids, UsesTenantConnection;

    protected $table = 'account_audit_logs';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];
}
