<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\Kyc;

use App\Domain\Compliance\Services\KycService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KycFormController extends Controller
{
    private const FIELD_DEFINITIONS = [
        'national_id' => ['name' => 'National ID',        'instruction' => 'Upload a clear, unobstructed photo of your national identity card.'],
        'passport' => ['name' => 'Passport',            'instruction' => 'Upload the bio-data page of your passport.'],
        'selfie' => ['name' => 'Selfie with ID',     'instruction' => 'Take a clear selfie holding your identity document next to your face.'],
        'utility_bill' => ['name' => 'Utility Bill',       'instruction' => 'Upload a utility bill dated within the last 3 months.'],
        'bank_statement' => ['name' => 'Bank Statement',     'instruction' => 'Upload a recent bank statement (within the last 3 months).'],
        'drivers_license' => ['name' => "Driver's Licence",   'instruction' => "Upload the front of your driver's licence."],
    ];

    private const STEP_DEFINITIONS = [
        'identity_type' => [
            'title' => 'Identity Verification',
            'description' => 'Choose how you will verify your identity',
            'step_number' => 1,
        ],
        'identity_document' => [
            'title' => 'Upload Identity Document',
            'description' => 'Upload your selected identity document and a selfie',
            'step_number' => 2,
        ],
        'selfie' => [
            'title' => 'Selfie Verification',
            'description' => 'Take a clear selfie holding your identity document',
            'step_number' => 3,
        ],
        'address' => [
            'title' => 'Address Information',
            'description' => 'Enter your current residential address',
            'step_number' => 4,
        ],
        'address_proof' => [
            'title' => 'Address Proof',
            'description' => 'Upload a document proving your address',
            'step_number' => 5,
        ],
        'review' => [
            'title' => 'Review & Submit',
            'description' => 'Review your information and submit for verification',
            'step_number' => 6,
        ],
    ];

    public function __construct(private readonly KycService $kycService) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rawStatus = $user->kyc_status ?? 'not_started';
        $status = KycCompatStatus::normalizeForMobile($rawStatus);
        $progress = $this->kycService->getKycProgress($user);
        $progress['status'] = $status;

        [$message, $formAvailable] = match ($rawStatus) {
            'approved' => ['KYC verification complete.', false],
            'pending', 'in_review' => ['Your verification is being reviewed. We will notify you once complete.', false],
            'rejected', 'expired' => ['Your verification was rejected. Please resubmit to unlock full access.', true],
            'partial_identity' => ['Continue your verification to unlock higher limits and all features.', true],
            default => ['Verify your identity to unlock higher limits and all features.', true],
        };

        $data = [
            'kyc_status' => $status,
            'form_available' => $formAvailable,
            'can_skip' => true,
            'message' => $message,
            'progress' => $progress,
            'steps' => self::STEP_DEFINITIONS,
        ];

        if ($formAvailable) {
            $data['current_step_form'] = $this->buildStepForm($user, $progress['current_step'], $progress['steps_completed']);
        }

        return response()->json([
            'status' => 'success',
            'remark' => 'kyc_form',
            'data' => $data,
        ]);
    }

    private function buildStepForm(User $user, string $currentStep, array $stepsCompleted): array
    {
        return match ($currentStep) {
            KycService::STEP_IDENTITY_TYPE => $this->buildIdentityTypeStep($stepsCompleted),
            KycService::STEP_IDENTITY_DOCUMENT => $this->buildIdentityDocumentStep($user, $stepsCompleted),
            KycService::STEP_SELFIE => $this->buildSelfieStep($user),
            KycService::STEP_ADDRESS => $this->buildAddressStep($user, $stepsCompleted),
            KycService::STEP_ADDRESS_PROOF => $this->buildAddressProofStep($stepsCompleted),
            'review' => $this->buildReviewStep(),
            default => $this->buildIdentityTypeStep($stepsCompleted),
        };
    }

    private function buildIdentityTypeStep(array $stepsCompleted): array
    {
        return [
            'step' => KycService::STEP_IDENTITY_TYPE,
            'step_number' => 1,
            'title' => 'Identity Verification',
            'description' => 'Choose how you will verify your identity',
            'fields' => [
                [
                    'name' => 'identity_type',
                    'label' => 'Select Identity Type',
                    'type' => 'select',
                    'is_required' => true,
                    'options' => [
                        ['value' => 'passport', 'label' => 'Passport', 'description' => 'Valid passport document'],
                        ['value' => 'national_id', 'label' => 'National ID', 'description' => 'National identity card'],
                    ],
                ],
            ],
        ];
    }

    private function buildIdentityDocumentStep(User $user, array $stepsCompleted): array
    {
        $identityType = $user->kyc_identity_type ?? 'national_id';
        $fields = [];

        $fields[] = $this->buildFileField($identityType, 'Upload your '.($identityType === 'passport' ? 'passport' : 'national ID'), true);
        $fields[] = $this->buildFileField('selfie', 'Take a selfie holding your ID', true);

        return [
            'step' => KycService::STEP_IDENTITY_DOCUMENT,
            'step_number' => 2,
            'title' => 'Upload Identity Document',
            'description' => 'Upload your selected identity document and a selfie',
            'fields' => $fields,
        ];
    }

    private function buildSelfieStep(User $user): array
    {
        return [
            'step' => KycService::STEP_SELFIE,
            'step_number' => 3,
            'title' => 'Selfie Verification',
            'description' => 'Take a clear selfie holding your identity document',
            'fields' => [
                $this->buildFileField('selfie', 'Take a selfie holding your ID', true),
            ],
        ];
    }

    private function buildAddressStep(User $user, array $stepsCompleted): array
    {
        $existingAddress = $user->kyc_data['address'] ?? [];
        $fields = [
            [
                'name' => 'address_line1',
                'label' => 'Street Address',
                'type' => 'text',
                'is_required' => true,
                'placeholder' => '123 Main Street',
                'value' => $existingAddress['address_line1'] ?? null,
            ],
            [
                'name' => 'address_line2',
                'label' => 'Apartment, Suite, etc.',
                'type' => 'text',
                'is_required' => false,
                'placeholder' => 'Apt 4B',
                'value' => $existingAddress['address_line2'] ?? null,
            ],
            [
                'name' => 'city',
                'label' => 'City',
                'type' => 'text',
                'is_required' => true,
                'placeholder' => 'Mbabane',
                'value' => $existingAddress['city'] ?? null,
            ],
            [
                'name' => 'state',
                'label' => 'Region/State',
                'type' => 'text',
                'is_required' => false,
                'placeholder' => 'Hhohho',
                'value' => $existingAddress['state'] ?? null,
            ],
            [
                'name' => 'postal_code',
                'label' => 'Postal Code',
                'type' => 'text',
                'is_required' => false,
                'placeholder' => 'M100',
                'value' => $existingAddress['postal_code'] ?? null,
            ],
            [
                'name' => 'country',
                'label' => 'Country',
                'type' => 'text',
                'is_required' => true,
                'placeholder' => 'Eswatini',
                'value' => $existingAddress['country'] ?? null,
            ],
        ];

        return [
            'step' => KycService::STEP_ADDRESS,
            'step_number' => 4,
            'title' => 'Address Information',
            'description' => 'Enter your current residential address',
            'fields' => $fields,
        ];
    }

    private function buildAddressProofStep(array $stepsCompleted): array
    {
        return [
            'step' => KycService::STEP_ADDRESS_PROOF,
            'step_number' => 5,
            'title' => 'Address Proof',
            'description' => 'Upload a document proving your address',
            'fields' => [
                [
                    'name' => 'proof_type',
                    'label' => 'Select Document Type',
                    'type' => 'select',
                    'is_required' => true,
                    'options' => [
                        ['value' => 'utility_bill', 'label' => 'Utility Bill', 'description' => 'Electricity, water, or internet bill'],
                        ['value' => 'bank_statement', 'label' => 'Bank Statement', 'description' => 'Recent bank statement'],
                    ],
                ],
                $this->buildFileField('address_proof', 'Upload your document', true),
            ],
        ];
    }

    private function buildReviewStep(): array
    {
        return [
            'step' => 'review',
            'step_number' => 6,
            'title' => 'Review & Submit',
            'description' => 'Review your information and submit for verification',
            'fields' => [],
        ];
    }

    private function buildFileField(string $type, string $label, bool $required): array
    {
        $def = self::FIELD_DEFINITIONS[$type] ?? [
            'name' => ucwords(str_replace('_', ' ', $type)),
            'instruction' => '',
        ];

        return [
            'name' => $type,
            'label' => $def['name'] ?? $label,
            'type' => 'file',
            'is_required' => $required,
            'extensions' => 'jpg,jpeg,png,pdf',
            'instruction' => $def['instruction'],
        ];
    }
}
