<?php
declare(strict_types=1);
namespace App\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorSpendApproval;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Http\Controllers\Controller;
use App\Policies\AccountPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MinorSpendApprovalController extends Controller
{
    public function __construct(
        private readonly AccountPolicy $accountPolicy,
        private readonly AuthorizedTransactionManager $authorizedTransactionManager,
    ) {
    }

    /** GET /api/minor-accounts/{uuid}/approvals */
    public function index(Request $request, string $minorAccountUuid): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $minorAccount = Account::query()->where('uuid', $minorAccountUuid)->firstOrFail();

        abort_unless($this->accountPolicy->viewMinor($user, $minorAccount), 403);

        $approvals = MinorSpendApproval::query()
            ->where('minor_account_uuid', $minorAccountUuid)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get(['id', 'amount', 'asset_code', 'note', 'merchant_category', 'status', 'expires_at', 'created_at']);

        return response()->json(['success' => true, 'data' => $approvals]);
    }

    /** POST /api/minor-accounts/approvals/{id}/approve */
    public function approve(Request $request, string $approvalId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $approval = MinorSpendApproval::query()->findOrFail($approvalId);

        $this->assertActionable($approval);
        $this->assertGuardian($user, $approval);

        // Initiate + immediately finalize (guardian approval = verification step)
        $txn = $this->authorizedTransactionManager->initiate(
            remark: 'send_money',
            payload: [
                'from_account_uuid' => $approval->from_account_uuid,
                'to_account_uuid'   => $approval->to_account_uuid,
                'amount'            => $approval->amount,
                'asset_code'        => $approval->asset_code,
                'note'              => $approval->note ?? '',
                'reference'         => (string) Str::uuid(),
            ],
            user: $user,
            verificationType: 'none',
            idempotencyKey: 'approval-' . $approvalId,
        );

        $result = $this->authorizedTransactionManager->finalize($txn);

        $approval->forceFill([
            'status'     => 'approved',
            'decided_at' => now(),
        ])->save();

        return response()->json([
            'success' => true,
            'data'    => ['status' => 'approved', 'transfer_reference' => $result['reference'] ?? null],
        ]);
    }

    /** POST /api/minor-accounts/approvals/{id}/decline */
    public function decline(Request $request, string $approvalId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $approval = MinorSpendApproval::query()->findOrFail($approvalId);

        $this->assertActionable($approval);
        $this->assertGuardian($user, $approval);

        $approval->forceFill([
            'status'     => 'declined',
            'decided_at' => now(),
        ])->save();

        return response()->json([
            'success' => true,
            'data'    => ['status' => 'declined', 'approval_id' => $approval->id],
        ]);
    }

    private function assertActionable(MinorSpendApproval $approval): void
    {
        if ($approval->status !== 'pending') {
            abort(422, 'This approval has already been decided.');
        }
        if ($approval->isExpired()) {
            abort(422, 'This approval request has expired.');
        }
    }

    private function assertGuardian(\App\Models\User $user, MinorSpendApproval $approval): void
    {
        $isGuardian = AccountMembership::query()
            ->forAccount($approval->minor_account_uuid)
            ->forUser($user->uuid)
            ->active()
            ->whereIn('role', ['guardian', 'co_guardian'])
            ->exists();

        abort_unless($isGuardian, 403);
    }
}
