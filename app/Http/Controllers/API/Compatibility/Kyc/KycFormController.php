<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\Kyc;

use App\Domain\Compliance\Services\KycService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/kyc-form
 *
 * Returns the KYC status and — for users who need to verify — a dynamic form
 * definition that the mobile app's KycDynamicFormikForm component can render.
 *
 * Progressive KYC: users are always allowed into the app.
 * Feature-level gates (kyc_approved middleware) enforce limits when needed.
 *
 * Response shape:
 *   {
 *     status: 'success',
 *     data: {
 *       kyc_status,       // 'not_started' | 'pending' | 'in_review' | 'approved' | 'rejected' | 'expired'
 *       form_available,   // true when there is a form the user should fill in
 *       can_skip,         // true = user may dismiss and go to home screen
 *       message,
 *       form?,            // array of field definitions (present when form_available = true)
 *     }
 *   }
 */
class KycFormController extends Controller
{
    /** Document type definitions for form field rendering. */
    private const FIELD_DEFINITIONS = [
        'national_id'      => ['name' => 'National ID',         'instruction' => 'Upload a clear, unobstructed photo of your national identity card.'],
        'passport'         => ['name' => 'Passport',            'instruction' => 'Upload the bio-data page of your passport.'],
        'selfie'           => ['name' => 'Selfie with ID',      'instruction' => 'Take a clear selfie holding your identity document next to your face.'],
        'utility_bill'     => ['name' => 'Proof of Address',    'instruction' => 'Upload a utility bill or bank statement dated within the last 3 months.'],
        'bank_statement'   => ['name' => 'Bank Statement',      'instruction' => 'Upload a recent bank statement (within the last 3 months).'],
        'drivers_license'  => ['name' => "Driver's Licence",    'instruction' => "Upload the front of your driver's licence."],
        'residence_permit' => ['name' => 'Residence Permit',    'instruction' => 'Upload a copy of your valid residence permit.'],
        'proof_of_income'  => ['name' => 'Proof of Income',     'instruction' => 'Upload a recent payslip or letter of employment.'],
    ];

    public function __construct(private readonly KycService $kycService) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $status = $user->kyc_status ?? 'not_started';

        [$message, $formAvailable] = match ($status) {
            'approved'  => ['KYC verification complete.', false],
            'pending',
            'in_review' => ['Your verification is being reviewed. We will notify you once complete.', false],
            'rejected'  => ['Your verification was rejected. Please resubmit to unlock full access.', true],
            'expired'   => ['Your verification has expired. Please resubmit to unlock full access.', true],
            default     => ['Verify your identity to unlock higher limits and all features.', true],
        };

        $data = [
            'kyc_status'     => $status,
            'form_available' => $formAvailable,
            'can_skip'       => true,
            'message'        => $message,
        ];

        if ($formAvailable) {
            $requirements  = $this->kycService->getRequirements('basic');
            $data['form']  = array_values(array_map(
                fn (string $type) => $this->buildField($type),
                $requirements['documents'],
            ));
        }

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    /** @return array{label: string, name: string, type: string, is_required: string, extensions: string, instruction: string, options: array<never>} */
    private function buildField(string $type): array
    {
        $def = self::FIELD_DEFINITIONS[$type] ?? [
            'name'        => ucwords(str_replace('_', ' ', $type)),
            'instruction' => '',
        ];

        return [
            'label'       => $type,
            'name'        => $def['name'],
            'type'        => 'file',
            'is_required' => 'required',
            'extensions'  => 'jpg,jpeg,png,pdf',
            'instruction' => $def['instruction'],
            'options'     => [],
        ];
    }
}
