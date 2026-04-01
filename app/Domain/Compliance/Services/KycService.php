<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Compliance\Models\KycDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class KycService
{
    private const KYC_STORAGE_DISK = 'private';

    public const STEP_IDENTITY_TYPE = 'identity_type';

    public const STEP_IDENTITY_DOCUMENT = 'identity_document';

    public const STEP_ADDRESS = 'address';

    public const STEP_ADDRESS_PROOF = 'address_proof';

    public const STEP_SELFIE = 'selfie';

    public const IDENTITY_TYPE_PASSPORT = 'passport';

    public const IDENTITY_TYPE_NATIONAL_ID = 'national_id';

    public const ALL_STEPS = [
        self::STEP_IDENTITY_TYPE,
        self::STEP_IDENTITY_DOCUMENT,
        self::STEP_SELFIE,
        self::STEP_ADDRESS,
        self::STEP_ADDRESS_PROOF,
    ];

    /**
     * Submit KYC documents for a user (legacy method for backward compatibility).
     */
    public function submitKyc(User $user, array $documents): void
    {
        DB::transaction(
            function () use ($user, $documents) {
                // Update user KYC status
                $user->update(
                    [
                        'kyc_status'       => 'pending',
                        'kyc_submitted_at' => now(),
                    ]
                );

                // Store documents
                foreach ($documents as $document) {
                    $this->storeDocument($user, $document);
                }

                // Log the action
                AuditLog::log(
                    'kyc.submitted',
                    $user,
                    null,
                    ['documents'      => count($documents)],
                    ['document_types' => array_column($documents, 'type')],
                    'kyc,compliance'
                );
            }
        );
    }

    /**
     * Save identity type only (step 1). Next step is document + selfie upload.
     */
    public function selectIdentityType(User $user, string $identityType): void
    {
        if (! in_array($identityType, [self::IDENTITY_TYPE_PASSPORT, self::IDENTITY_TYPE_NATIONAL_ID], true)) {
            throw new InvalidArgumentException('Invalid identity type. Must be passport or national_id.');
        }

        DB::transaction(function () use ($user, $identityType): void {
            $stepsCompleted = $this->kycStepsCompletedList($user);

            $user->update([
                'kyc_identity_type'   => $identityType,
                'kyc_current_step'    => self::STEP_IDENTITY_DOCUMENT,
                'kyc_steps_completed' => array_unique(array_merge($stepsCompleted, [self::STEP_IDENTITY_TYPE])),
            ]);

            AuditLog::log(
                'kyc.identity_type_selected',
                $user,
                null,
                ['identity_type' => $identityType],
                null,
                'kyc,compliance'
            );
        });
    }

    /**
     * Upload identity document + selfie after {@see selectIdentityType} (steps 2–3).
     *
     * @param list<array{type: string, file: \Illuminate\Http\UploadedFile}> $documents
     */
    public function submitIdentityDocumentAndSelfie(User $user, array $documents): void
    {
        $identityType = $user->kyc_identity_type;
        if (! is_string($identityType) || ! in_array($identityType, [self::IDENTITY_TYPE_PASSPORT, self::IDENTITY_TYPE_NATIONAL_ID], true)) {
            throw new InvalidArgumentException('Identity type must be selected before uploading documents.');
        }

        $stepsCompleted = $this->kycStepsCompletedList($user);
        if (! in_array(self::STEP_IDENTITY_TYPE, $stepsCompleted, true)) {
            throw new InvalidArgumentException('Identity type step must be completed before uploading documents.');
        }

        $validDocumentTypes = [self::STEP_SELFIE, $identityType];
        foreach ($documents as $document) {
            if (! in_array($document['type'], $validDocumentTypes, true)) {
                throw new InvalidArgumentException("Invalid document type for identity step: {$document['type']}");
            }
        }

        DB::transaction(function () use ($user, $identityType, $documents): void {
            $stepsCompletedInner = $this->kycStepsCompletedList($user);

            $user->update([
                'kyc_current_step'    => self::STEP_ADDRESS,
                'kyc_status'          => 'partial_identity',
                'kyc_steps_completed' => array_unique(array_merge($stepsCompletedInner, [self::STEP_IDENTITY_DOCUMENT, self::STEP_SELFIE])),
            ]);

            foreach ($documents as $document) {
                $this->storeDocument($user, $document);
            }

            AuditLog::log(
                'kyc.identity_step_completed',
                $user,
                null,
                ['identity_type'  => $identityType, 'documents' => count($documents)],
                ['document_types' => array_column($documents, 'type')],
                'kyc,compliance'
            );
        });
    }

    /**
     * One-shot: select identity type and upload documents in a single request (backward compatible).
     *
     * @param list<array{type: string, file: \Illuminate\Http\UploadedFile}> $documents
     */
    public function submitIdentityStep(User $user, string $identityType, array $documents): void
    {
        if (! in_array($identityType, [self::IDENTITY_TYPE_PASSPORT, self::IDENTITY_TYPE_NATIONAL_ID], true)) {
            throw new InvalidArgumentException('Invalid identity type. Must be passport or national_id.');
        }

        $validDocumentTypes = [self::STEP_SELFIE, $identityType];
        foreach ($documents as $document) {
            if (! in_array($document['type'], $validDocumentTypes, true)) {
                throw new InvalidArgumentException("Invalid document type for identity step: {$document['type']}");
            }
        }

        DB::transaction(function () use ($user, $identityType, $documents): void {
            $stepsCompleted = $this->kycStepsCompletedList($user);

            $user->update([
                'kyc_identity_type'   => $identityType,
                'kyc_current_step'    => self::STEP_ADDRESS,
                'kyc_status'          => 'partial_identity',
                'kyc_steps_completed' => array_unique(array_merge($stepsCompleted, [self::STEP_IDENTITY_TYPE, self::STEP_IDENTITY_DOCUMENT, self::STEP_SELFIE])),
            ]);

            foreach ($documents as $document) {
                $this->storeDocument($user, $document);
            }

            AuditLog::log(
                'kyc.identity_step_completed',
                $user,
                null,
                ['identity_type'  => $identityType, 'documents' => count($documents)],
                ['document_types' => array_column($documents, 'type')],
                'kyc,compliance'
            );
        });
    }

    /**
     * @return list<string>
     */
    private function kycStepsCompletedList(User $user): array
    {
        $raw = $user->kyc_steps_completed;
        if (! is_array($raw)) {
            return [];
        }

        $steps = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $steps[] = $item;
            }
        }

        return $steps;
    }

    /**
     * Submit address step - user provides address information.
     */
    public function submitAddressStep(User $user, array $addressData): void
    {
        $requiredFields = ['address_line1', 'city', 'country'];
        foreach ($requiredFields as $field) {
            if (empty($addressData[$field])) {
                throw new InvalidArgumentException("Address field '{$field}' is required.");
            }
        }

        $auditMeta = ['city' => $addressData['city'], 'country' => $addressData['country']];

        DB::transaction(function () use ($user, $addressData) {
            $rawKycData = $user->kyc_data;
            $kycData = is_array($rawKycData) ? $rawKycData : [];
            $kycData['address'] = [
                'address_line1' => $addressData['address_line1'],
                'address_line2' => $addressData['address_line2'] ?? null,
                'city'          => $addressData['city'],
                'state'         => $addressData['state'] ?? null,
                'postal_code'   => $addressData['postal_code'] ?? null,
                'country'       => $addressData['country'],
            ];

            $stepsCompleted = $this->kycStepsCompletedList($user);
            $user->update([
                'kyc_data'            => $kycData,
                'kyc_current_step'    => self::STEP_ADDRESS_PROOF,
                'kyc_steps_completed' => array_unique(array_merge($stepsCompleted, [self::STEP_ADDRESS])),
            ]);
        });

        $this->logKycAuditSafely(
            'kyc.address_step_completed',
            $user,
            null,
            null,
            $auditMeta,
            'kyc,compliance'
        );
    }

    /**
     * Audit logging should never block KYC progression.
     */
    private function logKycAuditSafely(
        string $action,
        ?User $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?string $tags = null
    ): void {
        try {
            AuditLog::log($action, $auditable, $oldValues, $newValues, $metadata, $tags);
        } catch (Throwable $e) {
            Log::warning('KYC audit logging failed (continuing)', [
                'action' => $action,
                'user_id' => $auditable?->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Submit address proof step - user uploads utility bill or bank statement.
     */
    public function submitAddressProofStep(User $user, array $documents): void
    {
        $validTypes = [self::STEP_ADDRESS_PROOF];
        foreach ($documents as $document) {
            if (! in_array($document['type'], ['utility_bill', 'bank_statement'])) {
                throw new InvalidArgumentException('Address proof must be utility_bill or bank_statement.');
            }
        }

        DB::transaction(function () use ($user, $documents) {
            $stepsCompleted = $this->kycStepsCompletedList($user);

            foreach ($documents as $document) {
                $this->storeDocument($user, [
                    'type'    => self::STEP_ADDRESS_PROOF,
                    'file'    => $document['file'],
                    'subtype' => $document['type'],
                ]);
            }

            $user->update([
                'kyc_current_step'    => 'review',
                'kyc_steps_completed' => array_unique(array_merge($stepsCompleted, [self::STEP_ADDRESS_PROOF])),
            ]);

            AuditLog::log(
                'kyc.address_proof_step_completed',
                $user,
                null,
                ['documents'      => count($documents)],
                ['document_types' => array_column($documents, 'type')],
                'kyc,compliance'
            );
        });
    }

    /**
     * Finalize KYC submission - all steps complete, submit for review.
     */
    public function finalizeKyc(User $user): void
    {
        $stepsCompleted = $this->kycStepsCompletedList($user);
        $requiredSteps = [self::STEP_IDENTITY_TYPE, self::STEP_IDENTITY_DOCUMENT, self::STEP_SELFIE, self::STEP_ADDRESS, self::STEP_ADDRESS_PROOF];

        foreach ($requiredSteps as $step) {
            if (! in_array($step, $stepsCompleted)) {
                throw new InvalidArgumentException("Cannot finalize KYC: step '{$step}' is not complete.");
            }
        }

        DB::transaction(function () use ($user) {
            $user->update([
                'kyc_status'       => 'pending',
                'kyc_submitted_at' => now(),
                'kyc_current_step' => 'pending',
            ]);

            AuditLog::log(
                'kyc.submitted',
                $user,
                null,
                ['identity_type' => $user->kyc_identity_type],
                null,
                'kyc,compliance'
            );
        });
    }

    /**
     * Get KYC progress for a user.
     */
    public function getKycProgress(User $user): array
    {
        $stepsCompleted = $user->kyc_steps_completed ?? [];
        $currentStep = $user->kyc_current_step ?? self::STEP_IDENTITY_TYPE;

        return [
            'status'          => $user->kyc_status ?? 'not_started',
            'identity_type'   => $user->kyc_identity_type,
            'current_step'    => $currentStep,
            'steps_completed' => $stepsCompleted,
            'is_complete'     => $this->isKycComplete($user),
            'can_finalize'    => $this->canFinalize($user),
            'address'         => $user->kyc_data['address'] ?? null,
        ];
    }

    /**
     * Check if all required KYC steps are complete.
     */
    public function isKycComplete(User $user): bool
    {
        $stepsCompleted = $user->kyc_steps_completed ?? [];
        $requiredSteps = [self::STEP_IDENTITY_TYPE, self::STEP_IDENTITY_DOCUMENT, self::STEP_SELFIE, self::STEP_ADDRESS, self::STEP_ADDRESS_PROOF];

        foreach ($requiredSteps as $step) {
            if (! in_array($step, $stepsCompleted)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if KYC can be finalized.
     */
    public function canFinalize(User $user): bool
    {
        if ($user->kyc_status === 'pending' || $user->kyc_status === 'approved') {
            return false;
        }

        return $this->isKycComplete($user);
    }

    /**
     * Store a KYC document.
     */
    protected function storeDocument(User $user, array $documentData): KycDocument
    {
        $userUuid = $this->ensureUserUuid($user);
        $storageDisk = $this->resolveKycStorageDisk();
        $path = $documentData['file']->store("kyc/{$userUuid}", $storageDisk);

        // Use the uploaded temp file for hashing so this works across local/S3 disks.
        $realPath = $documentData['file']->getRealPath();
        if (! is_string($realPath) || $realPath === '') {
            throw new InvalidArgumentException('Unable to hash uploaded KYC document.');
        }
        $hash = hash_file('sha256', $realPath);

        return KycDocument::create(
            [
                'user_uuid'     => $user->uuid,
                'document_type' => $documentData['type'],
                'file_path'     => $path,
                'file_hash'     => $hash,
                'uploaded_at'   => now(),
                'metadata'      => [
                    'original_name' => $documentData['file']->getClientOriginalName(),
                    'mime_type'     => $documentData['file']->getMimeType(),
                    'size'          => $documentData['file']->getSize(),
                ],
            ]
        );
    }

    private function ensureUserUuid(User $user): string
    {
        $current = $user->uuid;
        if (is_string($current) && $current !== '') {
            return $current;
        }

        $uuid = (string) Str::uuid();
        $user->forceFill(['uuid' => $uuid])->saveQuietly();
        $user->refresh();

        if (! is_string($user->uuid) || $user->uuid === '') {
            throw new InvalidArgumentException('Unable to resolve user identity for KYC submission.');
        }

        return $user->uuid;
    }

    private function resolveKycStorageDisk(): string
    {
        /** @var array<string, mixed> $disks */
        $disks = config('filesystems.disks', []);
        if (isset($disks[self::KYC_STORAGE_DISK])) {
            return self::KYC_STORAGE_DISK;
        }

        $defaultDisk = (string) config('filesystems.default', 'local');
        if ($defaultDisk !== '' && isset($disks[$defaultDisk])) {
            return $defaultDisk;
        }

        return 'local';
    }

    /**
     * Verify user KYC.
     */
    public function verifyKyc(User $user, string $verifiedBy, array $options = []): void
    {
        DB::transaction(
            function () use ($user, $verifiedBy, $options) {
                $oldStatus = $user->kyc_status;

                // Update user status
                $user->update(
                    [
                        'kyc_status'      => 'approved',
                        'kyc_approved_at' => now(),
                        'kyc_expires_at'  => $options['expires_at'] ?? now()->addYears(2),
                        'kyc_level'       => $options['level'] ?? 'enhanced',
                        'risk_rating'     => $options['risk_rating'] ?? 'low',
                        'pep_status'      => $options['pep_status'] ?? false,
                    ]
                );

                // Mark all pending documents as verified
                $user->kycDocuments()
                    ->pending()
                    ->each(
                        function ($document) use ($verifiedBy, $options) {
                            $document->markAsVerified($verifiedBy, $options['document_expires_at'] ?? null);
                        }
                    );

                // Log the verification
                AuditLog::log(
                    'kyc.approved',
                    $user,
                    ['kyc_status'  => $oldStatus],
                    ['kyc_status'  => 'approved', 'kyc_level' => $user->kyc_level],
                    ['verified_by' => $verifiedBy, 'options' => $options],
                    'kyc,compliance,verification'
                );
            }
        );
    }

    /**
     * Reject user KYC.
     */
    public function rejectKyc(User $user, string $rejectedBy, string $reason): void
    {
        DB::transaction(
            function () use ($user, $rejectedBy, $reason) {
                $oldStatus = $user->kyc_status;

                // Update user status
                $user->update(
                    [
                        'kyc_status'      => 'rejected',
                        'kyc_rejected_at' => now(),
                    ]
                );

                // Mark documents as rejected
                $user->kycDocuments()
                    ->pending()
                    ->each(
                        function ($document) use ($reason, $rejectedBy) {
                            $document->markAsRejected($reason, $rejectedBy);
                        }
                    );

                // Log the rejection
                AuditLog::log(
                    'kyc.rejected',
                    $user,
                    ['kyc_status'  => $oldStatus],
                    ['kyc_status'  => 'rejected'],
                    ['rejected_by' => $rejectedBy, 'reason' => $reason],
                    'kyc,compliance,rejection'
                );
            }
        );
    }

    /**
     * Check if KYC is expired and update status.
     */
    public function checkExpiredKyc(User $user): bool
    {
        if ($user->kyc_status === 'approved' && $user->kyc_expires_at && $user->kyc_expires_at->isPast()) {
            $user->update(['kyc_status' => 'expired']);

            AuditLog::log(
                'kyc.expired',
                $user,
                ['kyc_status' => 'approved'],
                ['kyc_status' => 'expired'],
                null,
                'kyc,compliance,expired'
            );

            return true;
        }

        return false;
    }

    /**
     * Get KYC requirements for a specific level.
     */
    public function getRequirements(string $level): array
    {
        return match ($level) {
            'basic' => [
                'documents' => ['national_id', 'passport', 'selfie'],
                'limits'    => [
                    'daily_transaction'   => 100000, // $1,000
                    'monthly_transaction' => 500000, // $5,000
                    'max_balance'         => 1000000, // $10,000
                ],
            ],
            'enhanced' => [
                'documents' => ['passport', 'utility_bill', 'selfie'],
                'limits'    => [
                    'daily_transaction'   => 1000000, // $10,000
                    'monthly_transaction' => 5000000, // $50,000
                    'max_balance'         => 10000000, // $100,000
                ],
            ],
            'full' => [
                'documents' => ['passport', 'utility_bill', 'bank_statement', 'selfie', 'proof_of_income'],
                'limits'    => [
                    'daily_transaction'   => null, // No limit
                    'monthly_transaction' => null, // No limit
                    'max_balance'         => null, // No limit
                ],
            ],
            default => throw new InvalidArgumentException("Unknown KYC level: {$level}"),
        };
    }

    /**
     * Get KYC status for a user.
     */
    public function getKycStatus(User $user): string
    {
        // Check if KYC is expired
        $this->checkExpiredKyc($user);

        return $user->kyc_status ?? 'not_submitted';
    }

    /**
     * Check if user's KYC is approved.
     */
    public function isKycApproved(User $user): bool
    {
        return $this->getKycStatus($user) === 'approved';
    }
}
