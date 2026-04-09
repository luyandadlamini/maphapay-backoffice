<?php

declare(strict_types=1);

namespace App\Support\Reconciliation;

use Illuminate\Database\Eloquent\Model;

final class ReconciliationReportRecord extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $table = 'reconciliation_report_records';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $record = new self();
        $record->forceFill($attributes + [
            'id' => (string) ($attributes['date'] ?? md5(json_encode($attributes, JSON_THROW_ON_ERROR))),
        ]);
        $record->syncOriginal();

        return $record;
    }
}
