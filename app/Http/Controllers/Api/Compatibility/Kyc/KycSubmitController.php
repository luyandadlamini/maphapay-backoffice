<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Kyc;

use App\Domain\Compliance\Services\KycService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

class KycSubmitController extends Controller
{
    public function __construct(private readonly KycService $kycService)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->kyc_status === 'approved') {
            return response()->json([
                'status'  => 'error',
                'remark'  => 'kyc_submit',
                'message' => 'Your identity is already verified.',
                'data'    => [],
            ], 400);
        }

        if (in_array($user->kyc_status, ['pending', 'in_review'], true)) {
            return response()->json([
                'status'  => 'error',
                'remark'  => 'kyc_submit',
                'message' => 'Your verification is already being reviewed.',
                'data'    => [],
            ], 400);
        }

        try {
            $progress = $this->kycService->getKycProgress($user);

            return match ($progress['current_step']) {
                KycService::STEP_IDENTITY_TYPE => $this->submitIdentityType($request, $user),
                KycService::STEP_IDENTITY_DOCUMENT,
                KycService::STEP_SELFIE        => $this->submitIdentityDocuments($request, $user),
                KycService::STEP_ADDRESS       => $this->submitAddress($request, $user),
                KycService::STEP_ADDRESS_PROOF => $this->submitAddressProof($request, $user),
                'review'                       => $this->finalize($request, $user),
                default                        => $this->submitIdentityType($request, $user),
            };
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'status'  => 'error',
                'remark'  => 'kyc_submit',
                'message' => $e->getMessage(),
                'data'    => [],
            ], 422);
        } catch (Throwable $e) {
            Log::error('KYC compat submit failed', [
                'user_id' => $user->id,
                'step' => $user->kyc_current_step,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status'  => 'error',
                'remark'  => 'kyc_submit',
                'message' => 'Unable to process KYC submission right now. Please try again.',
                'data'    => [],
            ], 500);
        }
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

        if ($documents === []) {
            $this->kycService->selectIdentityType($user, $identityType);
            $user->refresh();
            $progress = $this->kycService->getKycProgress($user);

            return response()->json([
                'status'  => 'success',
                'remark'  => 'kyc_submit',
                'message' => 'Identity type saved. Please upload your document and selfie.',
                'data'    => [
                    'kyc_status'      => KycCompatStatus::normalizeForMobile((string) $progress['status']),
                    'current_step'    => $progress['current_step'],
                    'steps_completed' => $progress['steps_completed'],
                ],
            ]);
        }

        $this->kycService->submitIdentityStep($user, $identityType, $documents);
        $progress = $this->kycService->getKycProgress($user);

        return response()->json([
            'status'  => 'success',
            'remark'  => 'kyc_submit',
            'message' => 'Identity documents uploaded. Please continue with your address information.',
            'data'    => [
                'kyc_status'      => KycCompatStatus::normalizeForMobile((string) $progress['status']),
                'current_step'    => $progress['current_step'],
                'steps_completed' => $progress['steps_completed'],
            ],
        ]);
    }

    public function submitIdentityDocuments(Request $request, User $user): JsonResponse
    {
        $identityType = $user->kyc_identity_type;
        if (! in_array($identityType, ['passport', 'national_id'], true)) {
            return response()->json([
                'status'  => 'error',
                'remark'  => 'kyc_submit',
                'message' => 'Select an identity type before uploading documents.',
                'data'    => [],
            ], 422);
        }

        $documents = [];

        $file = $request->file($identityType);
        if ($file) {
            $documents[] = ['type' => $identityType, 'file' => $file];
        }

        $selfie = $request->file('selfie');
        if ($selfie) {
            $documents[] = ['type' => 'selfie', 'file' => $selfie];
        }

        if ($documents === []) {
            return response()->json([
                'status'  => 'error',
                'remark'  => 'kyc_submit',
                'message' => 'Please upload your identity document and selfie.',
                'data'    => [],
            ], 422);
        }

        $this->kycService->submitIdentityDocumentAndSelfie($user, $documents);
        $progress = $this->kycService->getKycProgress($user);

        return response()->json([
            'status'  => 'success',
            'remark'  => 'kyc_submit',
            'message' => 'Identity documents uploaded. Please continue with your address information.',
            'data'    => [
                'kyc_status'      => KycCompatStatus::normalizeForMobile((string) $progress['status']),
                'current_step'    => $progress['current_step'],
                'steps_completed' => $progress['steps_completed'],
            ],
        ]);
    }

    public function submitAddress(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'address_line1' => 'required|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city'          => 'required|string|max:100',
            'state'         => 'nullable|string|max:100',
            'postal_code'   => 'nullable|string|max:20',
            'country'       => 'required|string|max:100',
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
            'status'  => 'success',
            'remark'  => 'kyc_submit',
            'message' => 'Address information saved. Please upload your address proof.',
            'data'    => [
                'kyc_status'      => KycCompatStatus::normalizeForMobile((string) $progress['status']),
                'current_step'    => $progress['current_step'],
                'steps_completed' => $progress['steps_completed'],
            ],
        ]);
    }

    public function submitAddressProof(Request $request, User $user): JsonResponse
    {
        $proofFile = $request->file('address_proof') ?? $request->file('file');
        $request->validate([
            'proof_type'    => ['required', Rule::in(['utility_bill', 'bank_statement'])],
            'address_proof' => 'sometimes|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'file'          => 'sometimes|file|mimes:jpg,jpeg,png,pdf|max:10240',
        ]);
        if ($proofFile === null) {
            return response()->json([
                'status'  => 'error',
                'remark'  => 'kyc_submit',
                'message' => 'Please upload your address proof document.',
                'data'    => [],
            ], 422);
        }

        $this->kycService->submitAddressProofStep($user, [
            ['type' => $request->input('proof_type'), 'file' => $proofFile],
        ]);
        $progress = $this->kycService->getKycProgress($user);

        return response()->json([
            'status'  => 'success',
            'remark'  => 'kyc_submit',
            'message' => 'Address proof uploaded. Review your information and submit.',
            'data'    => [
                'kyc_status'      => KycCompatStatus::normalizeForMobile((string) $progress['status']),
                'current_step'    => $progress['current_step'],
                'steps_completed' => $progress['steps_completed'],
                'can_finalize'    => $progress['can_finalize'],
            ],
        ]);
    }

    public function finalize(Request $request, User $user): JsonResponse
    {
        if (! $this->kycService->canFinalize($user)) {
            return response()->json([
                'status'  => 'error',
                'remark'  => 'kyc_submit',
                'message' => 'Please complete all required steps before submitting.',
                'data'    => [],
            ], 400);
        }

        $this->kycService->finalizeKyc($user);

        return response()->json([
            'status'  => 'success',
            'remark'  => 'kyc_submit',
            'message' => 'Documents submitted. Your verification is under review.',
            'data'    => ['kyc_status' => 'pending'],
        ]);
    }
}
