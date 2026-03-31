<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\Kyc;

use App\Domain\Compliance\Services\KycService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/kyc-submit
 *
 * Accepts flat multipart/form-data from the mobile KycDynamicFormikForm
 * (fields keyed by document type, e.g. national_id=<file>&selfie=<file>)
 * and delegates to KycService::submitKyc().
 */
class KycSubmitController extends Controller
{
    private const DOCUMENT_TYPES = [
        'national_id',
        'passport',
        'selfie',
        'utility_bill',
        'bank_statement',
        'drivers_license',
        'residence_permit',
        'proof_of_income',
    ];

    public function __construct(private readonly KycService $kycService) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->kyc_status === 'approved') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Your identity is already verified.',
            ], 400);
        }

        $rules = [];
        foreach (self::DOCUMENT_TYPES as $type) {
            $rules[$type] = 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240';
        }
        $request->validate($rules);

        $documents = [];
        foreach (self::DOCUMENT_TYPES as $type) {
            $file = $request->file($type);
            if ($file !== null) {
                $documents[] = ['type' => $type, 'file' => $file];
            }
        }

        if (empty($documents)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'At least one document is required.',
            ], 422);
        }

        $this->kycService->submitKyc($user, $documents);

        return response()->json([
            'status'  => 'success',
            'message' => 'Documents submitted. Your verification is under review.',
            'data'    => ['kyc_status' => 'pending'],
        ]);
    }
}
