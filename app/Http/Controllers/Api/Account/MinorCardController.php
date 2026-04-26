<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Account;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorCardRequest;
use App\Domain\Account\Services\MinorAccountAccessService;
use App\Domain\Account\Services\MinorCardRequestService;
use App\Domain\Account\Services\MinorCardService;
use App\Domain\Account\Services\ScaVerificationService;
use App\Domain\CardIssuance\Enums\WalletType;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\Services\CardProvisioningService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\UnauthorizedException;

class MinorCardController extends Controller
{
    public function __construct(
        private readonly MinorAccountAccessService $accessService,
        private readonly MinorCardRequestService $requestService,
        private readonly MinorCardService $cardService,
        private readonly CardProvisioningService $cardProvisioning,
        private readonly ScaVerificationService $scaVerificationService,
    ) {
    }

    private function verifySca(User $user, ?string $scaToken, ?string $scaType, ?string $deviceId): void
    {
        if (! $scaToken) {
            throw new UnauthorizedException('SCA token is required for this operation.');
        }

        $scaMethod = $scaType ?? 'otp';

        $result = match ($scaMethod) {
            'otp'       => $this->scaVerificationService->verifyOtp($user->uuid, $scaToken),
            'biometric' => $this->scaVerificationService->verifyBiometric(
                $user->uuid,
                $deviceId ?? '',
                $scaToken
            ),
            default => throw new UnauthorizedException('Unsupported SCA method.'),
        };

        if (! $result) {
            throw new UnauthorizedException('SCA verification failed.');
        }
    }

    public function createRequest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'minor_account_uuid' => 'required_without:self_request|uuid|exists:accounts,uuid',
            'network'            => 'in:visa,mastercard',
            'requested_limits'   => 'nullable|array',
        ]);

        /** @var User $user */
        $user = $request->user();

        $minorUuid = $validated['minor_account_uuid'] ?? null;
        $minor = $minorUuid
            ? Account::where('uuid', $minorUuid)->firstOrFail()
            : Account::where('user_uuid', $user->uuid)->where('type', 'minor')->firstOrFail();

        $this->authorize('request', [MinorCardRequest::class, $minor]);

        $result = $this->requestService->createRequest(
            $user,
            $minor,
            $validated['network'] ?? 'visa',
            $validated['requested_limits'] ?? null,
        );

        return response()->json($result, 201);
    }

    public function approveRequest(string $id): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $minorCardRequest = MinorCardRequest::where('id', $id)->firstOrFail();
        $minorAccount = $minorCardRequest->minorAccount;

        if (! $minorAccount instanceof Account) {
            return response()->json(['message' => 'Minor account not found'], 404);
        }

        $this->authorize('approve', $minorCardRequest);

        $card = $this->cardService->createCardFromRequest($minorCardRequest);

        return response()->json(['request' => $minorCardRequest->refresh(), 'card' => $card]);
    }

    public function denyRequest(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['reason' => 'required|string|max:500']);

        /** @var User $user */
        $user = Auth::user();
        $minorCardRequest = MinorCardRequest::where('id', $id)->firstOrFail();
        $minorAccount = $minorCardRequest->minorAccount;

        if (! $minorAccount instanceof Account) {
            return response()->json(['message' => 'Minor account not found'], 404);
        }

        $this->authorize('deny', $minorCardRequest);

        $result = $this->requestService->deny($user, $minorCardRequest, $validated['reason']);

        return response()->json($result);
    }

    public function freeze(Request $request, string $cardId): JsonResponse
    {
        $validated = $request->validate([
            'sca_token' => 'required|string',
            'sca_type'  => 'nullable|string|in:otp,biometric',
            'device_id' => 'nullable|string',
        ]);

        /** @var User $user */
        $user = Auth::user();

        $this->verifySca(
            $user,
            $validated['sca_token'] ?? null,
            $validated['sca_type'] ?? null,
            $validated['device_id'] ?? null
        );

        $card = Card::where('issuer_card_token', $cardId)->first();
        if (! $card) {
            return response()->json(['message' => 'Card not found'], 404);
        }

        if ($card->minor_account_uuid) {
            $minorAccount = Account::where('uuid', $card->minor_account_uuid)->first();
            if ($minorAccount instanceof Account) {
                $this->authorize('freeze', [MinorCardRequest::class, $minorAccount]);
            }
        }

        $result = $this->cardService->freezeCard($user, $card);

        return response()->json($result);
    }

    public function unfreeze(Request $request, string $cardId): JsonResponse
    {
        $validated = $request->validate([
            'sca_token' => 'required|string',
            'sca_type'  => 'nullable|string|in:otp,biometric',
            'device_id' => 'nullable|string',
        ]);

        /** @var User $user */
        $user = Auth::user();

        $this->verifySca(
            $user,
            $validated['sca_token'] ?? null,
            $validated['sca_type'] ?? null,
            $validated['device_id'] ?? null
        );

        $card = Card::where('issuer_card_token', $cardId)->first();
        if (! $card) {
            return response()->json(['message' => 'Card not found'], 404);
        }

        if ($card->minor_account_uuid) {
            $minorAccount = Account::where('uuid', $card->minor_account_uuid)->first();
            if ($minorAccount instanceof Account) {
                $this->authorize('unfreeze', [MinorCardRequest::class, $minorAccount]);
            }
        }

        $result = $this->cardService->unfreezeCard($user, $card);

        return response()->json($result);
    }

    public function provision(Request $request, string $cardId): JsonResponse
    {
        $validated = $request->validate([
            'wallet_type'   => 'required|in:apple_pay,google_pay',
            'device_id'     => 'required|string',
            'sca_token'     => 'required|string',
            'sca_type'      => 'nullable|string|in:otp,biometric',
            'sca_device_id' => 'nullable|string',
        ]);

        /** @var User $user */
        $user = Auth::user();

        $this->verifySca(
            $user,
            $validated['sca_token'] ?? null,
            $validated['sca_type'] ?? null,
            $validated['sca_device_id'] ?? null
        );

        $card = Card::where('issuer_card_token', $cardId)->first();
        if (! $card) {
            return response()->json(['message' => 'Card not found'], 404);
        }

        $provisioningData = $this->cardProvisioning->getProvisioningData(
            userId: $user->uuid,
            cardToken: $card->issuer_card_token,
            walletType: WalletType::from($validated['wallet_type']),
            deviceId: $validated['device_id'],
            certificates: [],
        );

        return response()->json($provisioningData);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $minorAccountUuid = $request->query('minor_account_uuid');
        $minor = $minorAccountUuid
            ? Account::where('uuid', $minorAccountUuid)->firstOrFail()
            : Account::where('user_uuid', $user->uuid)->where('type', 'minor')->first();

        if (! $minor) {
            return response()->json(['message' => 'Minor account not found'], 404);
        }

        $this->authorize('view', [MinorCardRequest::class, $minor]);

        $cards = $this->cardService->listMinorCards($minor);

        return response()->json(['cards' => $cards]);
    }

    public function show(Request $request, string $cardId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $card = Card::where('issuer_card_token', $cardId)->first();
        if (! $card) {
            return response()->json(['message' => 'Card not found'], 404);
        }

        if ($card->minor_account_uuid) {
            $minorAccount = Account::where('uuid', $card->minor_account_uuid)->first();
            if ($minorAccount instanceof Account) {
                $this->authorize('view', [MinorCardRequest::class, $minorAccount]);
            }
        }

        return response()->json($card);
    }

    public function listRequests(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $minorAccountUuid = $request->query('minor_account_uuid');
        $minor = $minorAccountUuid
            ? Account::where('uuid', $minorAccountUuid)->firstOrFail()
            : Account::where('user_uuid', $user->uuid)->where('type', 'minor')->first();

        if (! $minor) {
            return response()->json(['message' => 'Minor account not found'], 404);
        }

        $this->authorize('view', [MinorCardRequest::class, $minor]);

        $requests = MinorCardRequest::where('minor_account_uuid', $minor->uuid)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['requests' => $requests]);
    }

    public function showRequest(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $minorCardRequest = MinorCardRequest::where('id', $id)->firstOrFail();
        $minorAccount = $minorCardRequest->minorAccount;

        if (! $minorAccount instanceof Account) {
            return response()->json(['message' => 'Minor account not found'], 404);
        }

        $this->authorize('view', $minorCardRequest);

        return response()->json($minorCardRequest);
    }
}
