<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Account\Models\AccountMembership;
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
        $hasAccess = AccountMembership::query()
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

            Log::info('Company document uploaded', [
                'document_id' => $document->id,
                'document_type' => $validated['document_type'],
                'user_uuid' => $user->uuid,
                'company_profile_id' => $companyProfile->id,
            ]);

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

        $hasAccess = AccountMembership::query()
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

        try {
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
        } catch (Throwable $e) {
            Log::error('CompanyDocumentController: list failed', [
                'user_uuid' => $user->uuid,
                'company_profile_id' => $companyProfile->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to retrieve documents. Please try again.',
            ], 500);
        }

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

        try {
            $requiredDocs = AccountProfileCompanyDocument::REQUIRED_BY_TYPE[$validated['business_type']] ?? [];

            $documents = array_map(function ($docType) use ($requiredDocs) {
                return [
                    'type' => $docType,
                    'label' => AccountProfileCompanyDocument::DOCUMENT_TYPES[$docType] ?? $docType,
                    'required' => in_array($docType, $requiredDocs, true),
                ];
            }, array_keys(AccountProfileCompanyDocument::DOCUMENT_TYPES));

            return response()->json([
                'success' => true,
                'data' => $documents,
            ]);
        } catch (Throwable $e) {
            Log::error('CompanyDocumentController: requiredDocuments failed', [
                'business_type' => $validated['business_type'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to retrieve required documents. Please try again.',
            ], 500);
        }
    }

    public function download(Request $request, string $documentId): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        // Validate the route parameter is a UUID (no body param needed — ID comes from URL)
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $documentId)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid document identifier.',
            ], 422);
        }

        /** @var User $user */
        $user = $request->user();

        try {
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

            $hasAccess = AccountMembership::query()
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
                Log::warning('CompanyDocumentController: file missing from storage', [
                    'document_id' => $documentId,
                    'file_path' => $document->file_path,
                    'user_uuid' => $user->uuid,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Document file not found.',
                ], 404);
            }

            Log::info('Company document downloaded', [
                'document_id' => $documentId,
                'user_uuid' => $user->uuid,
                'company_profile_id' => $companyProfile->id,
            ]);

            // Serve through download controller — file is on private disk, never public/
            // Use a sanitized filename to prevent header injection
            $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $document->original_file_name ?? 'document');

            return Storage::disk('private')->download(
                $document->file_path,
                $safeFilename
            );
        } catch (Throwable $e) {
            Log::error('CompanyDocumentController: download failed', [
                'document_id' => $documentId,
                'user_uuid' => $user->uuid,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to download document. Please try again.',
            ], 500);
        }
    }
}