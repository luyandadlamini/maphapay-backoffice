<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MPP monetized resource configuration.
 *
 * Defines which API endpoints require MPP payment,
 * their pricing, and available payment rails.
 *
 * @property int    $id
 * @property string $method
 * @property string $path
 * @property int    $amount_cents
 * @property string $currency
 * @property array<string> $available_rails
 * @property string|null $description
 * @property string|null $mime_type
 * @property bool   $is_active
 * @property int|null $team_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class MppMonetizedResource extends Model
{
    protected $table = 'mpp_monetized_resources';

    protected $fillable = [
        'method',
        'path',
        'amount_cents',
        'currency',
        'available_rails',
        'description',
        'mime_type',
        'is_active',
        'team_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents'    => 'integer',
            'available_rails' => 'array',
            'is_active'       => 'boolean',
            'team_id'         => 'integer',
        ];
    }
}
