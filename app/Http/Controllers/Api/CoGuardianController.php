<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\GuardianInvite;
use App\Domain\Account\Services\AccountMembershipService;
use App\Domain\Account\Services\ScaVerificationService;
use App\Http\Controllers\Controller;
use App\Policies\AccountPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\UnauthorizedException;

class CoGuardianController extends Controller
{
    public function __construct(
        private readonly AccountMembershipService $membershipService,
        private readonly AccountPolicy $accountPolicy,
        private readonly ScaVerificationService $scaService,
    ) {
    }

    private function verifySca(\App\Models\User $user, ?string $scaToken, ?string $scaType, ?string $deviceId): void
    {
        if (! $scaToken) {
            throw new UnauthorizedException('SCA token is required for this operation.');
        }

        $scaMethod = $scaType ?? 'otp';

        $result = match ($scaMethod) {
            'otp'       => $this->scaService->verifyOtp($user->uuid, $scaToken),
            'biometric' => $this->scaService->verifyBiometric(
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

    public function storeInvite(Request $request, string $minorAccountUuid): JsonResponse
    {
        $validated = $request->validate([
            'sca_token' => ['nullable', 'string'],
            'sca_type'  => ['nullable', 'string', 'in:otp,biometric'],
            'device_id' => ['nullable', 'string'],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();
        $minorAccount = Account::query()->where('uuid', $minorAccountUuid)->firstOrFail();

        abort_unless($this->accountPolicy->updateMinor($user, $minorAccount), 403);

        $scaToken = $validated['sca_token'] ?? null;

        if ($scaToken === null || $scaToken === '') {
            return response()->json([
                'success' => false,
                'message' => 'SCA token is required for this operation.',
            ], 428);
        }

        try {
            $this->verifySca(
                $user,
                $scaToken,
                $validated['sca_type'] ?? null,
                $validated['device_id'] ?? null
            );
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 428);
        }

        $invite = GuardianInvite::query()->create([
            'minor_account_uuid'   => $minorAccount->uuid,
            'invited_by_user_uuid' => $user->uuid,
            'code'                 => $this->generateUniqueCode(),
            'expires_at'           => now()->addHours(72),
            'status'               => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'code'       => $invite->code,
                'expires_at' => $invite->expires_at?->toISOString(),
            ],
        ]);
    }

    public function acceptInvite(Request $request, string $code): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        $invite = GuardianInvite::query()
            ->where('code', strtoupper($code))
            ->first();

        if ($invite === null) {
            return response()->json([
                'success' => false,
                'message' => 'Invite code not found.',
            ], 404);
        }

        if ($invite->status !== 'pending' || $invite->claimed_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This invite code has already been claimed.',
            ], 422);
        }

        if ($invite->expires_at !== null && $invite->expires_at->isPast()) {
            $invite->forceFill(['status' => 'expired'])->save();

            return response()->json([
                'success' => false,
                'message' => 'This invite code has expired.',
            ], 422);
        }

        $guardianMembership = AccountMembership::query()
            ->forAccount($invite->minor_account_uuid)
            ->active()
            ->where('role', 'guardian')
            ->first();

        if ($guardianMembership === null) {
            return response()->json([
                'success' => false,
                'message' => 'Primary guardian membership not found for this account.',
            ], 422);
        }

        $account = Account::query()->where('uuid', $invite->minor_account_uuid)->firstOrFail();

        $membership = $this->membershipService->createGuardianMembership(
            $user,
            (string) $guardianMembership->tenant_id,
            $account,
            'co_guardian',
        );

        $invite->forceFill([
            'status'               => 'claimed',
            'claimed_at'           => now(),
            'claimed_by_user_uuid' => $user->uuid,
        ])->save();

        return response()->json([
            'success' => true,
            'data'    => [
                'account_uuid' => $membership->account_uuid,
                'user_uuid'    => $membership->user_uuid,
                'role'         => $membership->role,
                'status'       => $membership->status,
                'account_type' => $membership->account_type,
            ],
        ]);
    }

    private function generateUniqueCode(): string
    {
        do {
            $random = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $code = 'GD' . $random . strtoupper(substr(bin2hex(random_bytes(1)), 0, 2));
        } while (GuardianInvite::query()->where('code', $code)->exists());

        return $code;
    }
}
