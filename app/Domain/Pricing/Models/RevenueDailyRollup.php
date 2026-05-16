<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Shared\Traits\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Daily aggregated fee revenue per (date, product_code, segment_id, currency).
 *
 * @property int      $id
 * @property string   $date
 * @property string   $product_code
 * @property int|null $segment_id
 * @property string   $currency
 * @property int      $gross_revenue_minor
 * @property int      $fee_count
 * @property int      $unique_users
 * @property int      $avg_fee_minor
 */
class RevenueDailyRollup extends Model
{
    use UsesTenantConnection;

    protected $table = 'revenue_daily_rollups';

    protected $fillable = [
        'date',
        'product_code',
        'segment_id',
        'currency',
        'gross_revenue_minor',
        'fee_count',
        'unique_users',
        'avg_fee_minor',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'date'                => 'date',
        'gross_revenue_minor' => 'integer',
        'fee_count'           => 'integer',
        'unique_users'        => 'integer',
        'avg_fee_minor'       => 'integer',
    ];
}
