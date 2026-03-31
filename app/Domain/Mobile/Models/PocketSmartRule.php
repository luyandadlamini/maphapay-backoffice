<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PocketSmartRule extends Model
{
    use HasUlids;

    protected $table = 'pocket_smart_rules';

    protected $fillable = [
        'pocket_id',
        'round_up_change',
        'auto_save_deposits',
        'auto_save_salary',
        'auto_save_amount',
        'auto_save_frequency',
        'lock_pocket',
        'notify_on_transfer',
    ];

    protected $casts = [
        'round_up_change'    => 'boolean',
        'auto_save_deposits' => 'boolean',
        'auto_save_salary'   => 'boolean',
        'lock_pocket'        => 'boolean',
        'notify_on_transfer' => 'boolean',
        'auto_save_amount'   => 'decimal:2',
    ];

    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    /**
     * @return BelongsTo<Pocket, $this>
     */
    public function pocket(): BelongsTo
    {
        return $this->belongsTo(Pocket::class, 'pocket_id', 'uuid');
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'round_up_change'     => false,
            'auto_save_deposits'  => false,
            'auto_save_salary'    => false,
            'auto_save_amount'    => 0,
            'auto_save_frequency' => self::FREQUENCY_MONTHLY,
            'lock_pocket'         => false,
            'notify_on_transfer'  => true,
        ];
    }
}
