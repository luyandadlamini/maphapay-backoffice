<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantPartner extends Model
{
    protected $table = 'merchant_partners';

    protected $fillable = [
        'name',
        'category',
        'logo_url',
        'qr_endpoint',
        'api_key',
        'commission_rate',
        'payout_schedule',
        'is_active',
    ];

    protected $casts = [
        'commission_rate' => 'decimal:2',
        'is_active'       => 'boolean',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];
}
