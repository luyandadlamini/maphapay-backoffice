<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pricing\PricingRuleResource\Pages;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Pricing\Models\PricingRuleVersion;
use App\Filament\Admin\Resources\Pricing\PricingRuleResource;
use BackedEnum;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePricingRule extends CreateRecord
{
    protected static string $resource = PricingRuleResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $record = parent::handleRecordCreation($data);

        PricingRuleVersion::create([
            'pricing_rule_id' => $record->getKey(),
            'version'         => 1,
            'config_snapshot' => $record->config ?? [],
            'status_before'   => '',
            'status_after'    => $record->status instanceof BackedEnum ? $record->status->value : (string) $record->status,
            'changed_by'      => auth()->user()?->uuid,
            'reason'          => 'Initial creation',
        ]);

        AuditLog::log(
            action: 'pricing.rule.created',
            auditable: $record,
            newValues: $data,
            tags: 'backoffice,pricing'
        );

        return $record;
    }
}
