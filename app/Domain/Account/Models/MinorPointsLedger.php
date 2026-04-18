<?php
declare(strict_types=1);
namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $minor_account_uuid
 * @property int    $points
 * @property string $source
 * @property string $description
 * @property string|null $reference_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class MinorPointsLedger extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    protected $table = 'minor_points_ledger';
    protected $guarded = [];

    protected $casts = [
        'points' => 'integer',
    ];

    /**
     * @return BelongsTo<Account, $this>
     */
    public function minorAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'minor_account_uuid', 'uuid');
    }
}
