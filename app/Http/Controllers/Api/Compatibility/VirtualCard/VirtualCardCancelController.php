<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\VirtualCard;

use App\Domain\CardIssuance\Services\CardProvisioningService;
use App\Domain\Mobile\Services\HighRiskActionTrustPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VirtualCardCancelController extends Controller
{
    public function __construct(
        private readonly HighRiskActionTrustPolicy $trustPolicy,
    ) {
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $cardService = app(CardProvisioningService::class);
        $card = $cardService->getCard($id);

        if (! $card || ($card->metadata['user_id'] ?? null) !== $user->uuid) {
            return response()->json([
                'status'  => 'error',
                'message' => ['Virtual card not found'],
                'data'    => null,
            ], 404);
        }

        $trust = $this->trustPolicy->evaluate($user, $request, 'virtual_card.cancel');

        if (($trust['decision'] ?? 'allow') === 'deny') {
            return response()->json([
                'status'  => 'error',
                'message' => ['Request denied by mobile trust policy.'],
                'error'   => [
                    'code'            => 'TRUST_POLICY_DENY',
                    'message'         => 'Request denied by mobile trust policy.',
                    'trust_decision'  => $trust['decision'] ?? 'deny',
                    'trust_reason'    => $trust['reason'] ?? 'policy',
                    'trust_record_id' => $trust['record_id'] ?? null,
                ],
                'data' => null,
            ], 403);
        }

        if (in_array(($trust['decision'] ?? ''), ['step_up', 'degrade'], true)) {
            return response()->json([
                'status'  => 'error',
                'message' => ['Additional verification is required by mobile trust policy.'],
                'error'   => [
                    'code'            => 'TRUST_POLICY_STEP_UP',
                    'message'         => 'Additional verification is required by mobile trust policy.',
                    'trust_decision'  => $trust['decision'],
                    'trust_reason'    => $trust['reason'] ?? 'policy',
                    'trust_record_id' => $trust['record_id'] ?? null,
                ],
                'data' => null,
            ], 428);
        }

        $cardService->cancelCard($id, 'User requested cancellation');

        return response()->json([
            'status'  => 'success',
            'message' => 'Virtual card cancelled successfully',
            'data'    => [
                'card_id' => $id,
            ],
        ]);
    }
}
