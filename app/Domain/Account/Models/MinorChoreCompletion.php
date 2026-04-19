<?php
declare(strict_types=1);
namespace App\Domain\Account\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $chore_id
 * @property string|null $submission_note
 * @property string $status
 * @property string|null $reviewed_by_account_uuid
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property string|null $rejection_reason
 * @property \Illuminate\Support\Carbon|null $payout_processed_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class MinorChoreCompletion extends Model
{
    use HasUuids;
    use UsesTenantConnection;

    protected $guarded = [];

    protected $casts = [
        'reviewed_at'         => 'datetime',
        'payout_processed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<MinorChore, self>
     */
    public function chore(): BelongsTo
    {
        return $this->belongsTo(MinorChore::class, 'chore_id', 'id');
    }
}
