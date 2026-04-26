<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Domain\Account\Services\MinorAccountAccessService;
use App\Domain\Account\Services\MinorFamilyIntegrationService;
use App\Domain\Shared\OperationRecord\Exceptions\OperationInProgressException;
use App\Domain\Shared\OperationRecord\Exceptions\OperationPayloadMismatchException;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MinorFamilyFundingLinkController extends Controller
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
        $this->accessService->authorizeGuardian($actor, $minorAccount);

        $links = MinorFamilyFundingLink::query()
            ->where('minor_account_uuid', $minorAccount->uuid)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (MinorFamilyFundingLink $link): array => $this->serializeFundingLink($link))
            ->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'minor_account_uuid' => $minorAccount->uuid,
                'funding_links'      => $links,
            ],
        ]);
    }

    public function store(Request $request, string $minorAccountUuid): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $minorAccount = Account::query()->where('uuid', $minorAccountUuid)->firstOrFail();

        $validated = $request->validate([
            'created_by_account_uuid' => ['nullable', 'string', 'uuid'],
            'title'                   => ['required', 'string', 'max:255'],
            'note'                    => ['nullable', 'string', 'max:1000'],
            'amount_mode'             => ['required', 'string', 'in:fixed,capped'],
            'fixed_amount'            => ['nullable', 'numeric', 'gt:0'],
            'target_amount'           => ['nullable', 'numeric', 'gt:0'],
            'asset_code'              => ['nullable', 'string', 'max:10'],
            'provider_options'        => ['nullable', 'array'],
            'provider_options.*'      => ['string'],
            'expires_at'              => ['nullable', 'date'],
        ]);

        $this->accessService->authorizeGuardian(
            $actor,
            $minorAccount,
            $validated['created_by_account_uuid'] ?? null,
        );

        try {
            $link = $this->integrationService->createFundingLink(
                $actor,
                $minorAccount,
                array_merge($validated, array_filter([
                    'idempotency_key' => $request->header('Idempotency-Key') ?: $request->header('X-Idempotency-Key'),
                ], static fn (?string $value): bool => is_string($value) && trim($value) !== '')),
            );
        } catch (OperationPayloadMismatchException) {
            return response()->json([
                'error'      => 'Idempotency key already used',
                'message'    => 'The provided idempotency key has already been used with different request parameters',
                'error_code' => 'idempotency_key_payload_mismatch',
            ], 409);
        } catch (OperationInProgressException) {
            return response()->json([
                'error'      => 'Idempotency operation in progress',
                'message'    => 'An identical operation with this idempotency key is still in progress. Please retry shortly.',
                'error_code' => 'idempotency_operation_in_progress',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'data'    => $this->serializeFundingLinkSummary($link),
        ], 201);
    }

    public function expire(Request $request, string $minorAccountUuid, string $fundingLinkUuid): JsonResponse
    {
        $actor = $this->authenticatedUser($request);
        $minorAccount = Account::query()->where('uuid', $minorAccountUuid)->firstOrFail();
        $this->accessService->authorizeGuardian($actor, $minorAccount);

        $link = MinorFamilyFundingLink::query()
            ->where('id', $fundingLinkUuid)
            ->where('minor_account_uuid', $minorAccount->uuid)
            ->firstOrFail();

        $link = $this->integrationService->expireFundingLink(
            $actor,
            $minorAccount,
            $link,
        );

        return response()->json([
            'success' => true,
            'data'    => $this->serializeFundingLinkSummary($link),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFundingLink(MinorFamilyFundingLink $link): array
    {
        return [
            'id'                      => $link->id,
            'minor_account_uuid'      => $link->minor_account_uuid,
            'created_by_user_uuid'    => $link->created_by_user_uuid,
            'created_by_account_uuid' => $link->created_by_account_uuid,
            'title'                   => $link->title,
            'note'                    => $link->note,
            'token'                   => $link->token,
            'status'                  => $link->status,
            'amount_mode'             => $link->amount_mode,
            'fixed_amount'            => $link->fixed_amount,
            'target_amount'           => $link->target_amount,
            'collected_amount'        => $link->collected_amount,
            'asset_code'              => $link->asset_code,
            'provider_options'        => $link->provider_options,
            'expires_at'              => $link->expires_at?->toIso8601String(),
            'last_funded_at'          => $link->last_funded_at?->toIso8601String(),
            'created_at'              => $link->created_at?->toIso8601String(),
            'updated_at'              => $link->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFundingLinkSummary(MinorFamilyFundingLink $link): array
    {
        return [
            'funding_link_uuid'  => $link->id,
            'minor_account_uuid' => $link->minor_account_uuid,
            'status'             => $link->status,
            'token'              => $link->token,
            'public_url'         => "https://pay.maphapay.com/minor-support/{$link->token}",
            'provider_options'   => $link->provider_options ?? [MinorFamilyFundingLink::DEFAULT_PROVIDER],
            'fixed_amount'       => $link->fixed_amount,
            'target_amount'      => $link->target_amount,
            'collected_amount'   => $link->collected_amount,
            'expires_at'         => $link->expires_at?->toIso8601String(),
        ];
    }

    private function authenticatedUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user() ?? abort(401);

        return $user;
    }
}
