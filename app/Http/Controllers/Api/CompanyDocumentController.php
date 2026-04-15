<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Account\Models\AccountProfileCompany;
use App\Domain\Account\Models\AccountProfileCompanyDocument;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Throwable;

class CompanyDocumentController extends Controller
{
    private const MAX_FILE_SIZE = 10240; // 10MB
    private const ALLOWED_MIMES = ['jpg', 'jpeg', 'png', 'pdf'];

    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_profile_id' => ['required', 'uuid'],
            'document_type' => ['required', 'string', 'in:' . implode(',', array_keys(AccountProfileCompanyDocument::DOCUMENT_TYPES))],
            'document' => ['required', 'file', 'mimes:' . implode(',', self::ALLOWED_MIMES), 'max:' . self::MAX_FILE_SIZE],
        ]);

        /** @var User $user */
        $user = $request->user();

        // Verify user has access to this company profile
        $companyProfile = AccountProfileCompany::query()
            ->where('id', $validated['company_profile_id'])
            ->first();

        if ($companyProfile === null) {
            return response()->json([
                'success' => false,
                'message' => 'Company profile not found.',
            ], 404);
        }

        // Verify user owns this company (via membership)
        $hasAccess = \App\Domain\Account\Models\AccountMembership::query()
            ->forUser($user->uuid)
            ->where('account_uuid', $companyProfile->account_uuid)
            ->where('account_type', 'company')
            ->active()
            ->exists();

        if (!$hasAccess) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this company account.',
            ], 403);
        }

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('document');

        // Validate file is present and valid (security best practice)
        if (!$request->hasFile('document') || !$file->isValid()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or missing file.',
                'errors' => ['document' => ['File is invalid or missing.']],
            ], 400);
        }

        // Check for duplicate document type
        $existingDoc = AccountProfileCompanyDocument::query()
            ->where('company_profile_id', $companyProfile->id)
            ->where('document_type', $validated['document_type'])
            ->exists();

        if ($existingDoc) {
            return response()->json([
                'success' => false,
                'message' => 'A document of this type already exists. Please delete the existing document first or contact support.',
                'errors' => ['document_type' => ['A document of this type already exists.']],
            ], 409);
        }

        try {
            // Generate safe filename using hashName (prevents path traversal)
            $safeFileName = $file->hashName();
            $fileHash = hash_file('sha256', $file->getRealPath());

            // Store the document with safe filename
            $path = (string) $file->storeAs('company_documents/' . $companyProfile->id, $safeFileName, 'private');

            // Create document record
            $document = AccountProfileCompanyDocument::query()->create([
                'company_profile_id' => $companyProfile->id,
                'document_type' => $validated['document_type'],
                'file_path' => $path,
                'file_hash' => $fileHash,
                'original_file_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'status' => 'pending',
                'uploaded_by_user_uuid' => $user->uuid,
                'uploaded_at' => now(),
            ]);

            AuditLog::log(
                'company.document.uploaded',
                $document,
                null,
                null,
                [
                    'document_type' => $validated['document_type'],
                    'company_profile_id' => $companyProfile->id,
                    'file_hash' => $fileHash,
                    'file_size' => $file->getSize(),
                ],
                'kyb,document'
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'document_id' => $document->id,
                    'document_type' => $document->document_type,
                    'status' => $document->status,
                    'uploaded_at' => $document->uploaded_at->toISOString(),
                ],
                'message' => 'Document uploaded successfully.',
            ], 201);
        } catch (Throwable $e) {
            Log::error('Company document upload failed', [
                'error' => $e->getMessage(),
                'user_uuid' => $user->uuid,
                'company_profile_id' => $companyProfile->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to upload document.',
                'errors' => ['document' => ['Upload failed. Please try again.']],
            ], 500);
        }
    }

    public function list(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_profile_id' => ['required', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $companyProfile = AccountProfileCompany::query()
            ->where('id', $validated['company_profile_id'])
            ->first();

        if ($companyProfile === null) {
            return response()->json([
                'success' => false,
                'message' => 'Company profile not found.',
            ], 404);
        }

        $hasAccess = \App\Domain\Account\Models\AccountMembership::query()
            ->forUser($user->uuid)
            ->where('account_uuid', $companyProfile->account_uuid)
            ->where('account_type', 'company')
            ->active()
            ->exists();

        if (!$hasAccess) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this company account.',
            ], 403);
        }

        $documents = AccountProfileCompanyDocument::query()
            ->where('company_profile_id', $companyProfile->id)
            ->orderBy('uploaded_at', 'desc')
            ->get()
            ->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'document_type' => $doc->document_type,
                    'document_type_label' => AccountProfileCompanyDocument::DOCUMENT_TYPES[$doc->document_type] ?? $doc->document_type,
                    'status' => $doc->status,
                    'uploaded_at' => $doc->uploaded_at?->toISOString(),
                    'verified_at' => $doc->verified_at?->toISOString(),
                    'rejection_reason' => $doc->rejection_reason,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $documents,
        ]);
    }

    public function requiredDocuments(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_type' => ['required', 'string', 'in:pty_ltd,public,sole_trader,informal'],
        ]);

        $requiredDocs = AccountProfileCompanyDocument::REQUIRED_BY_TYPE[$validated['business_type']] ?? [];

        $documents = array_map(function ($docType) use ($requiredDocs) {
            return [
                'type' => $docType,
                'label' => AccountProfileCompanyDocument::DOCUMENT_TYPES[$docType] ?? $docType,
                'required' => in_array($docType, $requiredDocs),
            ];
        }, array_keys(AccountProfileCompanyDocument::DOCUMENT_TYPES));

        return response()->json([
            'success' => true,
            'data' => $documents,
        ]);
    }

    public function download(Request $request, string $documentId): JsonResponse
    {
        $request->validate([
            'document_id' => ['required', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $document = AccountProfileCompanyDocument::query()->find($documentId);

        if ($document === null) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found.',
            ], 404);
        }

        $companyProfile = AccountProfileCompany::query()
            ->where('id', $document->company_profile_id)
            ->first();

        if ($companyProfile === null) {
            return response()->json([
                'success' => false,
                'message' => 'Company profile not found.',
            ], 404);
        }

        $hasAccess = \App\Domain\Account\Models\AccountMembership::query()
            ->forUser($user->uuid)
            ->where('account_uuid', $companyProfile->account_uuid)
            ->where('account_type', 'company')
            ->active()
            ->exists();

        if (!$hasAccess) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this document.',
            ], 403);
        }

        if (!Storage::disk('private')->exists($document->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Document file not found.',
            ], 404);
        }

        AuditLog::log(
            'company.document.downloaded',
            $document,
            null,
            null,
            [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
            'kyb,document'
        );

        return Storage::disk('private')->download(
            $document->file_path,
            $document->original_file_name
        );
    }

    public function verify(Request $request, string $documentId): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|in:verify,reject',
            'rejection_reason' => 'required_if:action,reject|nullable|string|max:1000',
        ]);

        /** @var User $user */
        $user = $request->user();

        if (!$user->hasRole(['admin', 'super_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Admin privileges required.',
            ], 403);
        }

        $document = AccountProfileCompanyDocument::query()->find($documentId);

        if ($document === null) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found.',
            ], 404);
        }

        $action = $validated['action'];
        $isVerify = $action === 'verify';

        $document->update([
            'status' => $isVerify ? 'verified' : 'rejected',
            'verified_at' => $isVerify ? now() : null,
            'verified_by_user_uuid' => $isVerify ? $user->uuid : null,
            'rejection_reason' => !$isVerify ? ($validated['rejection_reason'] ?? null) : null,
        ]);

        if ($isVerify) {
            $companyProfile = $document->companyProfile;
            $requiredDocs = \App\Domain\Account\Models\AccountProfileCompanyDocument::REQUIRED_BY_TYPE[$companyProfile->business_type] ?? [];

            $uploadedDocs = \App\Domain\Account\Models\AccountProfileCompanyDocument::query()
                ->where('company_profile_id', $companyProfile->id)
                ->where('status', 'verified')
                ->pluck('document_type')
                ->toArray();

            $allVerified = empty($requiredDocs) || count(array_intersect($requiredDocs, $uploadedDocs)) === count($requiredDocs);

            if ($allVerified) {
                $companyProfile->update([
                    'kyb_status' => 'verified',
                    'kyb_verified_at' => now(),
                ]);
            } else {
                $companyProfile->update(['kyb_status' => 'in_progress']);
            }
        }

        AuditLog::log(
            $isVerify ? 'company.document.verified' : 'company.document.rejected',
            $document,
            null,
            ['status' => $document->status],
            [
                'verified_by' => $user->uuid,
                'rejection_reason' => $document->rejection_reason,
            ],
            'kyb,document'
        );

        return response()->json([
            'success' => true,
            'data' => [
                'document_id' => $document->id,
                'status' => $document->status,
                'verified_at' => $document->verified_at?->toISOString(),
            ],
            'message' => $isVerify ? 'Document verified successfully.' : 'Document rejected.',
        ]);
    }
}