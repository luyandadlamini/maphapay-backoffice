<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use App\Filament\Admin\Support\RevenueTargetAudit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant-scoped revenue target (REQ-TGT-001): one row per calendar month + wallet stream.
 *
 * @property string $id
 * @property string $period_month
 * @property string $stream_code
 * @property string $amount
 * @property string $currency
 * @property string|null $notes
 * @property int|null $created_by_user_id
 */
class RevenueTarget extends Model
{
    use HasUuids;
    use SoftDeletes;
    use UsesTenantConnection;

    protected static function booted(): void
    {
        static::deleted(function (RevenueTarget $target): void {
            RevenueTargetAudit::recordDeleted($target);
        });
    }

    protected $table = 'revenue_targets';

    protected $fillable = [
        'period_month',
        'stream_code',
        'amount',
        'currency',
        'notes',
        'created_by_user_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'amount'     => 'decimal:2',
        'deleted_at' => 'datetime',
    ];
}
