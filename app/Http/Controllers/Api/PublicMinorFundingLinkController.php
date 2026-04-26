<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Account\Models\MinorFamilyFundingAttempt;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Domain\Account\Services\MinorFamilyIntegrationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PublicMinorFundingLinkController extends Controller
{
    public function __construct(
        private readonly MinorFamilyIntegrationService $integrationService,
    ) {
    }

    public function show(string $token): JsonResponse
    {
        if (strlen($token) !== 64) {
            return $this->notFoundResponse();
        }

        $link = MinorFamilyFundingLink::query()
            ->where('token', hash('sha256', $token))
            ->first();

        if ($link === null) {
            return $this->notFoundResponse();
        }

        if ($this->isTerminalOrInactive($link)) {
            return $this->terminalResponseFor($link);
        }

        $link->loadMissing('minorAccount.user');

        return response()->json([
            'success' => true,
            'data'    => [
                'funding_link_uuid' => $link->id,
                'display_name'      => $this->displayNameFor($link),
                'title'             => $link->title,
                'note'              => $link->note,
                'provider_options'  => $link->provider_options ?? [MinorFamilyFundingLink::DEFAULT_PROVIDER],
                'amount_mode'       => $link->amount_mode,
                'remaining_amount'  => $link->remainingAmount() ?? $link->fixed_amount,
                'asset_code'        => $link->asset_code,
                'expires_at'        => $link->expires_at?->toIso8601String(),
            ],
        ]);
    }

    public function requestToPay(Request $request, string $token): JsonResponse
    {
        if (strlen($token) !== 64) {
            return $this->notFoundResponse();
        }

        $link = MinorFamilyFundingLink::query()
            ->where('token', hash('sha256', $token))
            ->first();

        if ($link === null) {
            return $this->notFoundResponse();
        }

        if ($this->isTerminalOrInactive($link)) {
            return $this->terminalResponseFor($link);
        }

        $validated = $request->validate([
            'sponsor_name'   => ['required', 'string', 'max:255'],
            'sponsor_msisdn' => ['required', 'string', 'max:50'],
            'amount'         => ['required', 'numeric', 'gt:0'],
            'asset_code'     => ['required', 'string', 'max:10'],
        ]);

        if ($this->normaliseAssetCode($validated['asset_code']) !== $this->normaliseAssetCode($link->asset_code)) {
            throw ValidationException::withMessages([
                'asset_code' => ['Requested asset code must match the funding link asset code.'],
            ]);
        }

        try {
            $attempt = $this->integrationService->createPublicFundingAttempt($link, array_merge($validated, [
                'provider' => MinorFamilyFundingLink::DEFAULT_PROVIDER,
            ]));
        } catch (ValidationException $exception) {
            $link->refresh();

            if ($this->isTerminalOrInactive($link)) {
                return $this->terminalResponseFor($link);
            }

            throw $exception;
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'funding_attempt_uuid'  => $attempt->id,
                'funding_link_uuid'     => $attempt->funding_link_uuid,
                'status'                => $attempt->status,
                'provider'              => $attempt->provider_name,
                'provider_reference_id' => $attempt->provider_reference_id,
                'amount'                => $attempt->amount,
                'asset_code'            => $attempt->asset_code,
                'expires_at'            => $link->expires_at?->toIso8601String(),
            ],
        ], 202);
    }

    public function attemptStatus(string $token, string $attemptUuid): JsonResponse
    {
        if (strlen($token) !== 64) {
            return $this->notFoundResponse();
        }

        $link = MinorFamilyFundingLink::query()
            ->where('token', hash('sha256', $token))
            ->first();

        if ($link === null) {
            return $this->notFoundResponse();
        }

        $attempt = MinorFamilyFundingAttempt::query()
            ->where('id', $attemptUuid)
            ->where('funding_link_uuid', $link->id)
            ->first();

        if ($attempt === null) {
            return $this->notFoundResponse();
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'funding_attempt_uuid' => $attempt->id,
                'status'               => $attempt->status,
                'provider'             => $attempt->provider_name,
                'amount'               => $attempt->amount,
                'asset_code'           => $attempt->asset_code,
                'credited_at'          => $attempt->wallet_credited_at?->toIso8601String(),
            ],
        ]);
    }

    private function terminalResponseFor(MinorFamilyFundingLink $link): JsonResponse
    {
        if ($link->isExpired()) {
            return response()->json([
                'success'    => false,
                'error_code' => 'funding_link_expired',
                'message'    => 'Funding link has expired.',
            ], 410);
        }

        return $this->notFoundResponse();
    }

    private function isTerminalOrInactive(MinorFamilyFundingLink $link): bool
    {
        return $link->isExpired() || $link->status !== MinorFamilyFundingLink::STATUS_ACTIVE;
    }

    private function normaliseAssetCode(mixed $assetCode): string
    {
        return strtoupper(trim((string) $assetCode));
    }

    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'success'    => false,
            'error_code' => 'funding_link_not_found',
            'message'    => 'Funding link not found.',
        ], 404);
    }

    private function displayNameFor(MinorFamilyFundingLink $link): string
    {
        $name = trim((string) ($link->minorAccount->user->name ?? ''));
        if ($name === '') {
            return 'Minor beneficiary';
        }

        $parts = preg_split('/\s+/', $name);
        if (! is_array($parts) || $parts === []) {
            return $name;
        }

        $firstName = trim((string) $parts[0]);
        $lastPart = trim((string) end($parts));

        if ($firstName === '' || $lastPart === '' || $firstName === $lastPart) {
            return $name;
        }

        return sprintf('%s %s.', $firstName, strtoupper(substr($lastPart, 0, 1)));
    }
}
