<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pricing\PricingRuleResource\Pages;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Pricing\Enums\PricingRuleStatus;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\PricingRuleVersion;
use App\Filament\Admin\Resources\Pricing\PricingRuleResource;
use App\Support\Backoffice\AdminActionGovernance;
use BackedEnum;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPricingRule extends EditRecord
{
    protected static string $resource = PricingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var PricingRule $rule */
        $rule = $record;

        $statusBefore = $rule->status instanceof BackedEnum ? $rule->status->value : (string) $rule->status;
        $requestedStatus = $data['status'] ?? $statusBefore;

        $submittedApproval = false;

        if ($requestedStatus === PricingRuleStatus::Active->value && $statusBefore !== PricingRuleStatus::Active->value) {
            app(AdminActionGovernance::class)->submitApprovalRequest(
                workspace: 'pricing',
                action: 'pricing.rules.activate_rule',
                reason: 'Admin requested rule activation',
                targetType: PricingRule::class,
                targetIdentifier: (string) $rule->getKey(),
                payload: [
                    'product_id'    => $rule->product_id,
                    'formula'       => $statusBefore,
                    'status_before' => $statusBefore,
                ],
            );

            $data['status'] = PricingRuleStatus::PendingApproval->value;
            $submittedApproval = true;
        }

        $updated = parent::handleRecordUpdate($record, $data);

        $nextVersion = PricingRuleVersion::where('pricing_rule_id', $rule->getKey())->max('version') + 1;

        PricingRuleVersion::create([
            'pricing_rule_id' => $rule->getKey(),
            'version'         => $nextVersion,
            'config_snapshot' => $updated->config ?? [],
            'status_before'   => $statusBefore,
            'status_after'    => $data['status'] ?? $requestedStatus,
            'changed_by'      => auth()->user()?->uuid,
            'reason'          => $submittedApproval ? 'Activation requested' : 'Admin update',
        ]);

        AuditLog::log(
            action: 'pricing.rule.updated',
            auditable: $updated,
            oldValues: ['status' => $statusBefore],
            newValues: $data,
            tags: 'backoffice,pricing'
        );

        if ($submittedApproval) {
            Notification::make()
                ->title('Approval request submitted')
                ->body('Rule status set to pending approval. A reviewer must approve before it becomes active.')
                ->warning()
                ->send();
        }

        return $updated;
    }
}
