<?php

declare(strict_types=1);

namespace App\Filament\Admin\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only row for the Bank Operations Filament table (backed by a UNION subquery, not a physical table).
 *
 * @property int $id
 * @property string $custodian
 * @property string $status
 * @property float $overall_failure_rate
 * @property string $reconciliation_status
 */
final class BankOperationTableRow extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'int';

    /** @var list<string> */
    protected $guarded = [];

    protected $table = 'bank_operation_rows';
}
