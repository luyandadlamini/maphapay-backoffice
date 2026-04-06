<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feature extends Model
{
    protected $table = 'features';

    protected $fillable = [
        'name',
        'scope',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    public function isActive(): bool
    {
        return $this->value === true || $this->value === 'true' || $this->value === 1;
    }

    public static function getFlags(): array
    {
        return [
            'send-money-enabled' => 'Send Money',
            'request-money-enabled' => 'Request Money',
            'exchange-enabled' => 'Exchange',
            'mtn-momo-enabled' => 'MTN MoMo',
            'virtual-cards-enabled' => 'Virtual Cards',
            'demo-mode-enabled' => 'Demo Mode',
            'kyc-required' => 'KYC Required',
            'maintenance-mode' => 'Maintenance Mode',
        ];
    }
}
