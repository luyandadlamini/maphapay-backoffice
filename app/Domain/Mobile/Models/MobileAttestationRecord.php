<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MobileAttestationRecord extends Model
{
    use HasUuids;

    protected $table = 'mobile_attestation_records';

    protected $fillable = [
        'user_id',
        'mobile_device_id',
        'action',
        'decision',
        'reason',
        'attestation_enabled',
        'attestation_verified',
        'device_type',
        'device_id',
        'payload_hash',
        'request_path',
        'metadata',
    ];

    protected $casts = [
        'attestation_enabled'  => 'boolean',
        'attestation_verified' => 'boolean',
        'metadata'             => 'array',
    ];
}
