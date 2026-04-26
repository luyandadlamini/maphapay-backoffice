<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support;

use App\Domain\Analytics\Models\RevenueTarget;
use App\Models\User;
use App\Support\Backoffice\AdminActionGovernance;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Illuminate\Support\Facades\Auth;

/**
 * REQ-TGT-001: audit trail when revenue targets are written from Filament.
 */
final class RevenueTargetAudit
{
    public static function recordDeleted(RevenueTarget $target): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        $access = app(BackofficeWorkspaceAccess::class);
        $workspace = $access->canAccess('finance', $user)
            ? 'finance'
            : 'platform_administration';

        app(AdminActionGovernance::class)->auditDirectAction(
            workspace: $workspace,
            action: 'backoffice.revenue_target.deleted',
            reason: __('Revenue target soft-deleted via admin.'),
            auditable: $target,
            oldValues: $target->only([
                'id',
                'period_month',
                'stream_code',
                'amount',
                'currency',
                'notes',
                'created_by_user_id',
            ]),
            newValues: ['deleted_at' => $target->deleted_at?->toIso8601String()],
            metadata: [
                'actor_id'    => $user->id,
                'actor_email' => $user->email ?? null,
            ],
            tags: 'backoffice,finance,revenue_target'
        );
    }

    public static function recordSaved(RevenueTarget $target, string $verb): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }
        $access = app(BackofficeWorkspaceAccess::class);
        $workspace = $access->canAccess('finance', $user)
            ? 'finance'
            : 'platform_administration';

        app(AdminActionGovernance::class)->auditDirectAction(
            workspace: $workspace,
            action: 'backoffice.revenue_target.' . $verb,
            reason: __('Revenue target change via admin (Targets & forecasts).'),
            auditable: $target,
            oldValues: null,
            newValues: $target->only(
                [
                    'id',
                    'period_month',
                    'stream_code',
                    'amount',
                    'currency',
                    'notes',
                    'created_by_user_id',
                ]
            ),
            metadata: [
                'actor_id'    => $user->id,
                'actor_email' => $user->email ?? null,
            ],
            tags: 'backoffice,finance,revenue_target'
        );
    }
}
