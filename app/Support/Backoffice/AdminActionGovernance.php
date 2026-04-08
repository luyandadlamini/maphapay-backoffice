<?php

declare(strict_types=1);

namespace App\Support\Backoffice;

use App\Domain\Compliance\Models\AuditLog;
use App\Models\AdminActionApprovalRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AdminActionGovernance
{
    public function auditDirectAction(
        string $workspace,
        string $action,
        string $reason,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $metadata = [],
        ?string $tags = null,
    ): AuditLog {
        return AuditLog::log(
            action: $action,
            auditable: $auditable,
            oldValues: $oldValues,
            newValues: $newValues,
            metadata: array_merge($metadata, [
                'workspace' => $workspace,
                'mode'      => 'direct_elevated',
                'reason'    => $reason,
            ]),
            tags: $tags,
        );
    }

    public function submitApprovalRequest(
        string $workspace,
        string $action,
        string $reason,
        ?string $targetType = null,
        ?string $targetIdentifier = null,
        array $payload = [],
        array $metadata = [],
    ): AdminActionApprovalRequest {
        return AdminActionApprovalRequest::create([
            'workspace'         => $workspace,
            'action'            => $action,
            'status'            => 'pending',
            'reason'            => $reason,
            'requester_id'      => Auth::id(),
            'target_type'       => $targetType,
            'target_identifier' => $targetIdentifier,
            'payload'           => $payload,
            'metadata'          => $metadata,
            'requested_at'      => now(),
        ]);
    }
}
