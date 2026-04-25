<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorFamilySupportTransfer;
use App\Domain\Account\Services\MinorAccountAccessService;
use App\Domain\Account\Services\MinorFamilyIntegrationService;
use App\Domain\Shared\OperationRecord\Exceptions\OperationInProgressException;
use App\Domain\Shared\OperationRecord\Exceptions\OperationPayloadMismatchException;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MinorFamilySupportTransferController extends Controller
{
    public function __construct(
        private readonly MinorAccountAccessService $accessService,
        private readonly MinorFamilyIntegrationService $integrationService,
    ) {
    }

    public function index(Request $request, string $minorAccountUuid): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $minorAccount = Account::query()->where('uuid', $minorAccountUuid)->firstOrFail();
        $this->accessService->authorizeView($actor, $minorAccount);

        $transfers = MinorFamilySupportTransfer::query()
            ->where('minor_account_uuid', $minorAccount->uuid)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (MinorFamilySupportTransfer $transfer): array => $this->serializeTransfer($transfer))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $transfers,
        ]);
    }

    public function store(Request $request, string $minorAccountUuid): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $minorAccount = Account::query()->where('uuid', $minorAccountUuid)->firstOrFail();

        $validated = $request->validate([
            'source_account_uuid' => ['required', 'string', 'uuid'],
            'provider' => ['required', 'string', 'max:50'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_msisdn' => ['required', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'asset_code' => ['required', 'string', 'max:10'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key') ?: $request->header('X-Idempotency-Key');

        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            return response()->json([
                'message' => ['Idempotency-Key header is required for family support transfer requests.'],
            ], 422);
        }

        $sourceAccount = Account::query()->where('uuid', $validated['source_account_uuid'])->firstOrFail();
        $this->authorize('useAsSource', $sourceAccount);
        $this->authorize('actAsGuardian', $minorAccount);

        try {
            $transfer = $this->integrationService->createOutboundSupportTransfer(
                $actor,
                $minorAccount,
                array_merge($validated, [
                    'idempotency_key' => $idempotencyKey,
                ]),
            );
        } catch (OperationPayloadMismatchException) {
            return response()->json([
                'error' => 'Idempotency key already used',
                'message' => 'The provided idempotency key has already been used with different request parameters',
                'error_code' => 'idempotency_key_payload_mismatch',
            ], 409);
        } catch (OperationInProgressException) {
            return response()->json([
                'error' => 'Idempotency operation in progress',
                'message' => 'An identical operation with this idempotency key is still in progress. Please retry shortly.',
                'error_code' => 'idempotency_operation_in_progress',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'data' => $this->serializeTransferSummary($transfer),
        ], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTransfer(MinorFamilySupportTransfer $transfer): array
    {
        return [
            'family_support_transfer_uuid' => $transfer->id,
            'status' => $transfer->status,
            'provider' => $transfer->provider_name,
            'recipient_name' => $transfer->recipient_name,
            'recipient_msisdn_masked' => $this->maskMsisdn($transfer->recipient_msisdn),
            'amount' => $transfer->amount,
            'asset_code' => $transfer->asset_code,
            'provider_reference_id' => $transfer->provider_reference_id,
            'created_at' => $transfer->created_at?->toIso8601String(),
            'settled_at' => $transfer->isPendingProvider() ? null : $transfer->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTransferSummary(MinorFamilySupportTransfer $transfer): array
    {
        return [
            'family_support_transfer_uuid' => $transfer->id,
            'minor_account_uuid' => $transfer->minor_account_uuid,
            'status' => $transfer->status,
            'provider' => $transfer->provider_name,
            'provider_reference_id' => $transfer->provider_reference_id,
            'amount' => $transfer->amount,
            'asset_code' => $transfer->asset_code,
            'created_at' => $transfer->created_at?->toIso8601String(),
        ];
    }

    private function maskMsisdn(?string $msisdn): string
    {
        $digits = preg_replace('/\D+/', '', (string) $msisdn) ?? '';

        if (strlen($digits) <= 6) {
            return $digits;
        }

        return substr($digits, 0, 5) . '****' . substr($digits, -2);
    }

    private function authenticatedUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user() ?? abort(401);

        return $user;
    }
}
