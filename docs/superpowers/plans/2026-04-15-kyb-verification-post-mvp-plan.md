# Company KYB Post-MVP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement 5 production-grade KYB verification features: database audit logging, encryption at rest, admin verification endpoints, webhooks, and presigned URL uploads.

**Architecture:** Each feature is implemented as independent middleware/service layer. Audit logging modifies controller; encryption uses filesystem config; admin endpoints add new controller methods; webhooks use queued service; presigned URLs require S3 integration.

**Tech Stack:** Laravel (backend), AWS S3 (file storage), Laravel Queues (webhooks), existing AuditLog model (central database), Filament admin (existing).

---

## Pre-Implementation: Explore Existing AuditLog

### Task 0: Understand AuditLog Integration

**Files:**
- Modify: `app/Domain/Compliance/Models/AuditLog.php:76-100` - review log() method signature
- Modify: `app/Http/Controllers/Api/KycController.php:332-348` - review existing audit usage
- Modify: `app/Domain/Account/Services/CompanyAccountService.php:102-108` - review existing audit in company creation

- [ ] **Step 1: Read AuditLog model log() method**

Run: `cat app/Domain/Compliance/Models/AuditLog.php | head -100`

Expected: See `log()` static method with parameters: action, auditable, old_values, new_values, metadata, tags

- [ ] **Step 2: Read existing audit usage in KycController**

Run: `grep -n "AuditLog::log" app/Http/Controllers/Api/KycController.php`

Expected: Find examples of how audit is used - typically: `AuditLog::log('action', $model, null, null, ['key' => 'value'], 'tags')`

- [ ] **Step 3: Confirm audit goes to central DB**

Run: Check model - `use UsesTenantConnection` is NOT used (AuditLog is central, not tenant)

Expected: Confirmed - AuditLog uses central database connection

---

## Feature 1: Database Audit Logging

### Task 1: Replace Log::info with AuditLog in CompanyDocumentController

**Files:**
- Modify: `app/Http/Controllers/Api/CompanyDocumentController.php:1-10` - add import
- Modify: `app/Http/Controllers/Api/CompanyDocumentController.php:107-112` - replace Log with AuditLog
- Modify: `app/Http/Controllers/Api/CompanyDocumentController.php:145-160` - add download audit
- Test: Create test for audit logging

- [ ] **Step 1: Add AuditLog import**

```php
use App\Domain\Compliance\Models\AuditLog;
```

- [ ] **Step 2: Replace upload Log::info with AuditLog::log**

In `upload()` method, replace lines 107-112:

```php
// OLD (delete):
Log::info('Company document uploaded', [
    'document_id' => $document->id,
    'document_type' => $validated['document_type'],
    'user_uuid' => $user->uuid,
    'company_profile_id' => $companyProfile->id,
]);

// NEW:
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
```

- [ ] **Step 3: Add AuditLog for download in download() method**

In `download()` method around line 145 (before return), add:

```php
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
```

- [ ] **Step 4: Verify syntax**

Run: `php -l app/Http/Controllers/Api/CompanyDocumentController.php`

Expected: No syntax errors

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/CompanyDocumentController.php
git commit -m "feat: replace Log facade with AuditLog for document operations"
```

---

### Task 2: Add Verification/Rejection Audit Actions

**Files:**
- Modify: `app/Http/Controllers/Api/CompanyDocumentController.php:170-210` - add verify/reject methods (will be created in Task 3)
- Test: Add audit test

- [ ] **Step 1: This is integrated into Task 5 verify() method** - audit logging added when admin verify/reject is implemented

---

## Feature 2: Encryption at Rest

### Task 3: Configure Encrypted Filesystem

**Files:**
- Modify: `config/filesystems.php` - add encrypted disk configuration
- Modify: `app/Http/Controllers/Api/CompanyDocumentController.php:65` - change storage disk

- [ ] **Step 1: Read current filesystem config**

Run: `cat config/filesystems.php | grep -A 20 "disks"`

Expected: See 'local', 'public', 's3' disk configurations

- [ ] **Step 2: Add encrypted local disk to config/filesystems.php**

Add after existing disks:

```php
'encrypted' => [
    'driver' => 'local',
    'root' => storage_path('app/encrypted'),
    'throw' => true,
],
```

**Note:** Laravel's local driver does NOT provide automatic encryption. For true AES-256 at rest:
1. **Option A (Recommended)**: Use S3 with SSE-KMS (Task 4) - simplest
2. **Option B**: Use Laravel's `enc` disk which encrypts via `APP_KEY` (suitable for dev only)
3. **Option C**: Use application-level encryption before storage

For MVP, this task configures the disk structure; actual encryption is handled by S3 in production.

- [ ] **Step 3: Update controller to use encrypted disk**

In `upload()` method, change line 65:

```php
// OLD:
$path = (string) $file->storeAs('company_documents/' . $companyProfile->id, $safeFileName, 'private');

// NEW:
$path = (string) $file->storeAs('company_documents/' . $companyProfile->id, $safeFileName, 'encrypted');
```

- [ ] **Step 4: Create encrypted directory**

Run: `mkdir -p storage/app/encrypted && chmod 700 storage/app/encrypted`

- [ ] **Step 5: Commit**

```bash
git add config/filesystems.php app/Http/Controllers/Api/CompanyDocumentController.php
git commit -m "feat: add encrypted disk for document storage"
```

---

### Task 4: For S3 - Add Server-Side Encryption (Alternative)

**Files:**
- Modify: `config/filesystems.php` - update S3 disk with encryption
- Test: Verify encryption applied

- [ ] **Step 1: Check if S3 disk exists**

Run: `grep -A 30 "'s3'" config/filesystems.php`

- [ ] **Step 2: If S3 exists, add SSE-KMS configuration**

```php
's3' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    'url' => env('AWS_URL'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    // Add encryption:
    'encryption' => 'aes256', // SSE-S3 or use 'aws:kms' with 'encryption_key_id'
],
```

- [ ] **Step 3: Commit**

```bash
git add config/filesystems.php
git commit -f "feat: add S3 server-side encryption"
```

---

## Feature 3: Admin Verification Endpoints

### Task 5: Add Verify/Reject Methods to Controller

**Files:**
- Modify: `app/Http/Controllers/Api/CompanyDocumentController.php` - add verify() method
- Modify: `app/Domain/Account/Routes/api.php:26-30` - add admin routes

- [ ] **Step 1: Add verify() method to CompanyDocumentController**

Add after download() method (around line 170):

```php
public function verify(Request $request, string $documentId): JsonResponse
{
    $validated = $request->validate([
        'action' => 'required|in:verify,reject',
        'rejection_reason' => 'required_if:action,reject|nullable|string|max:1000',
    ]);

    /** @var User $user */
    $user = $request->user();

    // Check admin permission
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

    // Update company KYB status if all required documents verified
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

    // Audit log
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
```

- [ ] **Step 2: Add admin routes**

In `app/Domain/Account/Routes/api.php`, add after document routes:

```php
// Admin KYB verification
Route::post('/accounts/company/documents/{documentId}/verify', [CompanyDocumentController::class, 'verify'])
    ->middleware(['auth:sanctum', 'api.rate_limit:mutation', 'scope:write']);
```

**Note:** Admin authorization is handled inline in the controller method using `$user->hasRole(['admin', 'super_admin'])` - no separate middleware needed.

- [ ] **Step 3: Verify syntax**

Run: `php -l app/Http/Controllers/Api/CompanyDocumentController.php && php -l app/Domain/Account/Routes/api.php`

Expected: No syntax errors

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Api/CompanyDocumentController.php app/Domain/Account/Routes/api.php
git commit -m "feat: add admin verify/reject endpoint for KYB documents"
```

---

### Task 6: Add Pending Documents List for Admin

**Files:**
- Modify: `app/Http/Controllers/Api/CompanyDocumentController.php` - add pendingList()
- Modify: `app/Domain/Account/Routes/api.php` - add route

- [ ] **Step 1: Add pendingList() method**

Add after verify() method:

```php
public function pendingDocuments(Request $request): JsonResponse
{
    /** @var User $user */
    $user = $request->user();

    if (!$user->hasRole(['admin', 'super_admin'])) {
        return response()->json([
            'success' => false,
            'message' => 'Admin privileges required.',
        ], 403);
    }

    $documents = AccountProfileCompanyDocument::query()
        ->where('status', 'pending')
        ->with(['companyProfile:id,company_name,business_type,account_uuid'])
        ->orderBy('uploaded_at', 'desc')
        ->paginate(20);

    return response()->json([
        'success' => true,
        'data' => $documents,
    ]);
}
```

- [ ] **Step 2: Add route**

```php
Route::get('/accounts/company/documents/pending', [CompanyDocumentController::class, 'pendingDocuments'])
    ->middleware(['auth:sanctum', 'api.rate_limit:query', 'scope:read']);
```

**Note:** Admin authorization is handled inline using `$user->hasRole(['admin', 'super_admin'])`.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/CompanyDocumentController.php app/Domain/Account/Routes/api.php
git commit -m "feat: add pending documents list for admin review"
```

---

## Feature 4: Webhook for Verification Status

### Task 7: Create Webhook Service

**Files:**
- Create: `app/Domain/Account/Services/CompanyKybWebhookService.php`
- Create: `config/kyb.php` - webhook configuration
- Create: `app/Jobs/SendKybWebhookJob.php` - proper queued job
- Modify: `app/Http/Controllers/Api/CompanyDocumentController.php` - call webhook after verification
- Modify: `database/migrations/tenant/2026_04_15_100003_create_account_profiles_company_table.php` - add webhook fields
- Modify: `database/migrations/tenant/2026_04_15_100004_create_account_profiles_company_documents_table.php` - add upload_token, expires_at

- [ ] **Step 1: Create CompanyKybWebhookService**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Models\AccountProfileCompany;
use App\Domain\Account\Models\AccountProfileCompanyDocument;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CompanyKybWebhookService
{
    private const MAX_RETRIES = 3;
    private const TIMEOUT = 30;

    public function __construct(
        private readonly string $webhookUrl,
        private readonly ?string $webhookSecret = null,
    ) {}

    public static function forCompany(AccountProfileCompany $company): ?self
    {
        if (empty($company->webhook_url)) {
            return null;
        }

        return new self($company->webhook_url, $company->webhook_secret);
    }

    public function sendDocumentStatusChange(
        AccountProfileCompanyDocument $document,
        string $oldStatus,
        string $newStatus,
        ?string $verifiedBy = null
    ): void {
        $payload = [
            'event' => 'kyb.document.status_changed',
            'timestamp' => now()->toISOString(),
            'data' => [
                'company_uuid' => $document->companyProfile?->account_uuid,
                'company_name' => $document->companyProfile?->company_name,
                'document_id' => $document->id,
                'document_type' => $document->document_type,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'verified_by' => $verifiedBy,
                'rejection_reason' => $document->rejection_reason,
            ],
        ];

        $this->sendWebhook($payload);
    }

    public function sendCompanyKybStatusChange(
        AccountProfileCompany $company,
        string $oldStatus,
        string $newStatus
    ): void {
        $payload = [
            'event' => 'kyb.company.status_changed',
            'timestamp' => now()->toISOString(),
            'data' => [
                'company_uuid' => $company->account_uuid,
                'company_name' => $company->company_name,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'rejection_reason' => $company->kyb_rejection_reason,
            ],
        ];

        $this->sendWebhook($payload);
    }

    private function sendWebhook(array $payload): void
    {
        if (empty($this->webhookUrl)) {
            return;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'MaphaPay-KYB/1.0',
        ];

        if ($this->webhookSecret) {
            $headers['X-KYB-Signature'] = $this->generateSignature($payload);
        }

        try {
            Http::timeout(self::TIMEOUT)
                ->withHeaders($headers)
                ->retry(self::MAX_RETRIES, 1000)
                ->post($this->webhookUrl, $payload);

            Log::info('KYB webhook sent successfully', [
                'event' => $payload['event'],
                'url' => $this->webhookUrl,
            ]);
        } catch (Throwable $e) {
            Log::error('KYB webhook failed', [
                'event' => $payload['event'],
                'url' => $this->webhookUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function generateSignature(array $payload): string
    {
        $json = json_encode($payload);
        return 'sha256=' . hash_hmac('sha256', $json, $this->webhookSecret);
    }
}
```

- [ ] **Step 2: Create config/kyb.php**

```php
<?php

return [
    'webhook' => [
        'enabled' => env('KYB_WEBHOOK_ENABLED', false),
        'default_url' => env('KYB_WEBHOOK_URL'),
        'default_secret' => env('KYB_WEBHOOK_SECRET'),
        'timeout' => env('KYB_WEBHOOK_TIMEOUT', 30),
        'retries' => env('KYB_WEBHOOK_RETRIES', 3),
    ],

    'verification' => [
        'auto_verify_threshold' => env('KYB_AUTO_VERIFY_THRESHOLD', 0), // 0 = manual only
    ],
];
```

- [ ] **Step 3: Add webhook fields to company profile migration**

In `database/migrations/tenant/2026_04_15_100003_create_account_profiles_company_table.php`, add before last `);`:

```php
$table->string('kyb_status')->default('pending'); // pending, in_progress, verified, rejected
$table->timestamp('kyb_submitted_at')->nullable();
$table->timestamp('kyb_verified_at')->nullable();
$table->text('kyb_rejection_reason')->nullable();
$table->string('webhook_url')->nullable();
$table->string('webhook_secret')->nullable();
```

- [ ] **Step 3: Add upload_token and expires_at to documents migration**

In `database/migrations/tenant/2026_04_15_100004_create_account_profiles_company_documents_table.php`, add after `file_hash`:

```php
$table->string('upload_token')->nullable();
$table->timestamp('expires_at')->nullable();
```

- [ ] **Step 4: Create SendKybWebhookJob (proper queued job instead of closure)**

Create `app/Jobs/SendKybWebhookJob.php`:

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Account\Services\CompanyKybWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendKybWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly CompanyKybWebhookService $webhookService,
        private readonly string $documentId,
        private readonly string $oldStatus,
        private readonly string $newStatus,
        private readonly ?string $verifiedBy = null,
    ) {}

    public function handle(): void
    {
        // Document needs to be re-fetched in job context
        $document = \App\Domain\Account\Models\AccountProfileCompanyDocument::query()
            ->with('companyProfile')
            ->find($this->documentId);

        if (!$document) {
            return;
        }

        $this->webhookService->sendDocumentStatusChange(
            $document,
            $this->oldStatus,
            $this->newStatus,
            $this->verifiedBy
        );
    }
}
```

- [ ] **Step 5: Replace closure dispatch with Job in controller verify() method**

In verify() method, after `$document->update([...])`, add:

```php
// Send webhook notification (using proper Job instead of closure)
$webhookService = CompanyKybWebhookService::forCompany($document->companyProfile);
if ($webhookService) {
    SendKybWebhookJob::dispatch(
        $webhookService,
        $document->id,
        'pending',
        $document->status,
        $user->uuid
    );
}
```

Also add import at top of controller:
```php
use App\Jobs\SendKybWebhookJob;
```

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Account/Services/CompanyKybWebhookService.php config/kyb.php database/migrations/tenant/2026_04_15_100003_create_account_profiles_company_table.php app/Http/Controllers/Api/CompanyDocumentController.php
git commit -m "feat: add KYB webhook service for status change notifications"
```

---

## Feature 5: Presigned URL Upload Pattern

### Task 8: Add Presigned URL Upload Endpoints

**Files:**
- Modify: `app/Http/Controllers/Api/CompanyDocumentController.php` - add getUploadUrl() and confirmUpload()
- Modify: `app/Domain/Account/Routes/api.php` - add presigned routes
- Test: Verify S3 integration

- [ ] **Step 1: Add getUploadUrl() method**

Add new method:

```php
public function getUploadUrl(Request $request): JsonResponse
{
    $validated = $request->validate([
        'company_profile_id' => ['required', 'uuid'],
        'document_type' => ['required', 'string', 'in:' . implode(',', array_keys(AccountProfileCompanyDocument::DOCUMENT_TYPES))],
        'file_name' => ['required', 'string', 'max:255'],
        'content_type' => ['required', 'string', 'in:application/pdf,image/jpeg,image/png'],
        'file_size' => ['required', 'integer', 'min:1024', 'max:10485760'],
    ]);

    /** @var User $user */
    $user = $request->user();

    // Verify access (same as upload)
    $companyProfile = AccountProfileCompany::query()
        ->where('id', $validated['company_profile_id'])
        ->first();

    if (!$companyProfile) {
        return response()->json(['success' => false, 'message' => 'Company profile not found.'], 404);
    }

    $hasAccess = \App\Domain\Account\Models\AccountMembership::query()
        ->forUser($user->uuid)
        ->where('account_uuid', $companyProfile->account_uuid)
        ->where('account_type', 'company')
        ->active()
        ->exists();

    if (!$hasAccess) {
        return response()->json(['success' => false, 'message' => 'Access denied.'], 403);
    }

    // Check for existing document type
    $existingDoc = AccountProfileCompanyDocument::query()
        ->where('company_profile_id', $companyProfile->id)
        ->where('document_type', $validated['document_type'])
        ->exists();

    if ($existingDoc) {
        return response()->json([
            'success' => false,
            'message' => 'Document type already exists. Delete existing first.',
        ], 409);
    }

    // Generate presigned URL
    $key = 'company_documents/' . $companyProfile->id . '/' . Str::uuid() . '.' . pathinfo($validated['file_name'], PATHINFO_EXTENSION);
    
    $url = Storage::disk('s3')->temporaryUrl(
        $key,
        now()->addMinutes(15),
        [
            'Content-Type' => $validated['content_type'],
            'Content-Length' => $validated['file_size'],
        ]
    );

    // Create pending record
    $uploadToken = Str::uuid()->toString();
    
    $document = AccountProfileCompanyDocument::query()->create([
        'company_profile_id' => $companyProfile->id,
        'document_type' => $validated['document_type'],
        'file_path' => $key,
        'original_file_name' => $validated['file_name'],
        'mime_type' => $validated['content_type'],
        'file_size' => $validated['file_size'],
        'status' => 'pending',
        'uploaded_by_user_uuid' => $user->uuid,
        'uploaded_at' => now(),
        'upload_token' => $uploadToken,
        'expires_at' => now()->addMinutes(15),
    ]);

    return response()->json([
        'success' => true,
        'data' => [
            'upload_url' => $url,
            'upload_token' => $uploadToken,
            'expires_at' => $document->expires_at->toISOString(),
            'document_id' => $document->id,
        ],
    ]);
}
```

- [ ] **Step 2: Add confirmUpload() method**

```php
public function confirmUpload(Request $request): JsonResponse
{
    $validated = $request->validate([
        'upload_token' => ['required', 'uuid'],
        'document_type' => ['required', 'string'],
        'file_hash' => ['required', 'string', 'size:64'], // SHA-256
    ]);

    $document = AccountProfileCompanyDocument::query()
        ->where('upload_token', $validated['upload_token'])
        ->where('expires_at', '>', now())
        ->first();

    if (!$document) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid or expired upload token.',
        ], 404);
    }

    // Verify file exists in S3
    if (!Storage::disk('s3')->exists($document->file_path)) {
        return response()->json([
            'success' => false,
            'message' => 'File not found in storage.',
        ], 404);
    }

    // Update with hash and clear token
    $document->update([
        'file_hash' => $validated['file_hash'],
        'upload_token' => null,
        'expires_at' => null,
    ]);

    AuditLog::log(
        'company.document.uploaded',
        $document,
        null,
        null,
        ['upload_method' => 'presigned_url', 'file_hash' => $validated['file_hash']],
        'kyb,document'
    );

    return response()->json([
        'success' => true,
        'data' => [
            'document_id' => $document->id,
            'status' => $document->status,
        ],
        'message' => 'Document uploaded successfully.',
    ]);
}
```

- [ ] **Step 3: Add routes**

```php
// Presigned URL upload routes
Route::post('/accounts/company/documents/upload-url', [CompanyDocumentController::class, 'getUploadUrl'])
    ->middleware(['auth:sanctum', 'api.rate_limit:mutation', 'scope:write']);
Route::post('/accounts/company/documents/confirm', [CompanyDocumentController::class, 'confirmUpload'])
    ->middleware(['auth:sanctum', 'api.rate_limit:mutation', 'scope:write']);
```

- [ ] **Step 4: Add Str import**

In controller, add at top:

```php
use Illuminate\Support\Str;
```

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/CompanyDocumentController.php app/Domain/Account/Routes/api.php
git commit -m "feat: add presigned URL upload pattern for direct S3 uploads"
```

---

## Final Integration: Run Migrations

### Task 9: Apply Database Migrations

**Files:**
- Run: Migration commands

- [ ] **Step 1: Run tenant migrations**

Run: `php artisan tenants:migrate --force`

Expected: Company profile table updated with KYB status + webhook fields, documents table updated with new fields

- [ ] **Step 2: Verify routes**

Run: `php artisan route:list --path=accounts/company`

Expected: Show all new routes including verify, pending, upload-url, confirm

---

## Testing Checklist

- [ ] Audit logging - Upload creates AuditLog record, download creates AuditLog record
- [ ] Encryption - Files in encrypted/ folder cannot be read directly
- [ ] Admin endpoints - Non-admin gets 403, admin can verify/reject
- [ ] Webhooks - Verify webhook POST sent after document status change (check logs)
- [ ] Presigned URL - Get URL, PUT to S3, confirm - file appears in DB

---

**Plan complete and saved to `docs/superpowers/plans/2026-04-15-kyb-verification-post-mvp-plan.md`. Two execution options:**

**1. Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**