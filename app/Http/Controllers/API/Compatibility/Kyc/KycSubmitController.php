<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\Kyc;

use App\Domain\Compliance\Services\KycService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KycSubmitController extends Controller
{
    public function __construct(private readonly KycService $kycService) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->kyc_status === 'approved') {
            return response()->json([
                'status' => 'error',
                'message' => 'Your identity is already verified.',
            ], 400);
        }

        $progress = $this->kycService->getKycProgress($user);

        return match ($progress['current_step']) {
            KycService::STEP_IDENTITY_TYPE => $this->submitIdentityType($request, $user),
            KycService::STEP_IDENTITY_DOCUMENT,
            KycService::STEP_SELFIE => $this->submitIdentityDocuments($request, $user),
            KycService::STEP_ADDRESS => $this->submitAddress($request, $user),
            KycService::STEP_ADDRESS_PROOF => $this->submitAddressProof($request, $user),
            'review' => $this->finalize($request, $user),
            default => $this->submitIdentityType($request, $user),
        };
    }

    public function submitIdentityType(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'identity_type' => ['required', Rule::in(['passport', 'national_id'])],
        ]);

        $identityType = $request->input('identity_type');
        $documents = [];

        $file = $request->file($identityType);
        if ($file) {
            $documents[] = ['type' => $identityType, 'file' => $file];
        }

        $selfie = $request->file('selfie');
        if ($selfie) {
            $documents[] = ['type' => 'selfie', 'file' => $selfie];
        }

        if (empty($documents)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Please upload your identity document and selfie.',
            ], 422);
        }

        $this->kycService->submitIdentityStep($user, $identityType, $documents);
        $progress = $this->kycService->getKycProgress($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Identity documents uploaded. Please continue with your address information.',
            'data' => [
                'kyc_status' => $progress['status'],
                'current_step' => $progress['current_step'],
                'steps_completed' => $progress['steps_completed'],
            ],
        ]);
    }

    public function submitIdentityDocuments(Request $request, User $user): JsonResponse
    {
        $identityType = $user->kyc_identity_type ?? 'national_id';
        $documents = [];

        $file = $request->file($identityType);
        if ($file) {
            $documents[] = ['type' => $identityType, 'file' => $file];
        }

        $selfie = $request->file('selfie');
        if ($selfie) {
            $documents[] = ['type' => 'selfie', 'file' => $selfie];
        }

        if (empty($documents)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Please upload your identity document and selfie.',
            ], 422);
        }

        $this->kycService->submitIdentityStep($user, $identityType, $documents);
        $progress = $this->kycService->getKycProgress($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Identity documents uploaded. Please continue with your address information.',
            'data' => [
                'kyc_status' => $progress['status'],
                'current_step' => $progress['current_step'],
                'steps_completed' => $progress['steps_completed'],
            ],
        ]);
    }

    public function submitAddress(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'address_line1' => 'required|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'required|string|max:100',
        ]);

        $this->kycService->submitAddressStep($user, $request->only([
            'address_line1',
            'address_line2',
            'city',
            'state',
            'postal_code',
            'country',
        ]));
        $progress = $this->kycService->getKycProgress($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Address information saved. Please upload your address proof.',
            'data' => [
                'kyc_status' => $progress['status'],
                'current_step' => $progress['current_step'],
                'steps_completed' => $progress['steps_completed'],
            ],
        ]);
    }

    public function submitAddressProof(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'proof_type' => ['required', Rule::in(['utility_bill', 'bank_statement'])],
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);

        $this->kycService->submitAddressProofStep($user, [
            ['type' => $request->input('proof_type'), 'file' => $request->file('file')],
        ]);
        $progress = $this->kycService->getKycProgress($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Address proof uploaded. Review your information and submit.',
            'data' => [
                'kyc_status' => $progress['status'],
                'current_step' => $progress['current_step'],
                'steps_completed' => $progress['steps_completed'],
                'can_finalize' => $progress['can_finalize'],
            ],
        ]);
    }

    public function finalize(Request $request, User $user): JsonResponse
    {
        if (! $this->kycService->canFinalize($user)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Please complete all required steps before submitting.',
            ], 400);
        }

        $this->kycService->finalizeKyc($user);

        return response()->json([
            'status' => 'success',
            'message' => 'Documents submitted. Your verification is under review.',
            'data' => ['kyc_status' => 'pending'],
        ]);
    }
}
