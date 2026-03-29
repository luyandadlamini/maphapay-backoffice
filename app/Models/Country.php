<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ReturnTypeWillChange;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $dial_code
 * @property string|null $currency_code
 * @property string|null $currency_name
 * @property bool $is_active
 */
class Country extends Model
{
    protected $fillable = [
        'name',
        'code',
        'dial_code',
        'currency_code',
        'currency_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @phpstan-return HasMany<User, Country> */
    #[ReturnTypeWillChange]
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'country_code', 'code');
    }
}
