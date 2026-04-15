# Code Review Prompt: Company KYB Document Upload - Brutal Review

**Reviewer Persona**: Senior backend engineer with 15+ years fintech experience. Has seen every type of security failure. Misses nothing. Approves nothing that doesn't meet production-grade standards. No mercy for technical debt or shortcuts.

---

## Pre-Review: Industry Best Practices from Research

### File Upload Security (Laravel Context7)
- **Always validate with `isValid()`** after `hasFile()` check
- **Use `hashName()`** instead of original filename (prevents path traversal)
- **Use `extension()`** based on MIME type, not client-provided extension
- **Max file size enforced** - 10MB standard for KYB docs
- **Allowed types**: jpg, jpeg, png, pdf (no executables, no SVG)

### Fintech KYB Document Standards (Web Research)

1. **Two-Step Upload Pattern** (Finmid, Conduit):
   - Step 1: Create upload metadata → get temporary `upload_url`
   - Step 2: PUT file directly to storage (not through API server)
   - Prevents server overload, faster uploads, presigned URLs expire in 15min

2. **Document Type Validation**:
   - Must match business type requirements
   - Pty Ltd needs: certificate_of_incorporation, form_j, memo_articles, directors_id, trading_license
   - Sole Trader needs: trading_license, proof_of_address, bank_statement
   - Informal: none required

3. **Security Requirements** (FileVault, iComply, Decentro):
   - AES-256 encryption at rest
   - TLS 1.3 in transit
   - Zero-trust ingestion model
   - Immutable audit logs
   - Access controls with role-based permissions
   - Short-lived tokens for downloads

4. **Fraud Prevention**:
   - Verify upload source (device attestation)
   - Cryptographic integrity (hash files, store checksums)
   - Tamper detection (store file hash on upload)
   - Reject re-submitted screen captures

---

## Review Checklist

### 1. FILE VALIDATION - Rate of Fire: Strict

**A. Current Implementation Issues**
- [ ] `$request->validate()` checks mimes but does NOT call `isValid()`
- [ ] No `hasFile()` check before accessing file
- [ ] Uses original filename `getClientOriginalName()` - **SECURITY RISK**
- [ ] No file hash generation for tamper detection

**B. Missing Validations - ADD THESE**
```php
// In controller - before validation
if (!$request->hasFile('document') || !$request->file('document')->isValid()) {
    return response()->json([
        'success' => false,
        'message' => 'Invalid or missing file.',
    ], 400);
}

// Use hashName instead of original name
$path = $file->hashName(); // generates unique random name
$extension = $file->extension(); // safe, based on MIME

// Store file hash for tamper detection
$fileHash = hash_file('sha256', $file->getRealPath());
```

**C. File Size**
- [ ] Current: 10MB max (10240 KB) - correct
- [ ] What about minimum? Add: `min:1024` (1KB minimum)

---

### 2. STORAGE SECURITY - Rate of Fire: Strict

**A. Current Implementation**
- [ ] Files stored in `private` disk - correct (not publicly accessible)
- [ ] Path includes company_profile_id - correct isolation
- [ ] Files stored at: `company_documents/{company_profile_id}/{hashName}`

**B. Missing Security**
- [ ] **No encryption at rest** - files stored as plain text
- [ ] **No file hash stored** - cannot detect tampering
- [ ] **No access logging** - who downloaded what?
- [ ] Download endpoint uses Storage::download() - exposes file directly

**C. Recommended Additions**
```php
// Add to document record
'file_hash' => hash_file('sha256', $file->getRealPath()),
'file_checksum' => md5_file($file->getRealPath()),

// In download, verify hash before serving
// Log every download: user, timestamp, document_id, IP
```

---

### 3. AUTHORIZATION - Rate of Fire: Strict

**A. Current Implementation**
- [ ] Validates Bearer token - good
- [ ] Checks user has company membership - good
- [ ] Checks membership is active - good

**B. Gaps**
- [ ] **No scope check** - should require `scope:write` for upload, `scope:read` for list/download
- [ ] **No rate limiting on upload** - could be exploited for DoS
- [ ] **No document count limit** - Finmid caps at 10 docs per business

**C. Add These Middleware**
```php
// routes
Route::post('/accounts/company/documents', [...])
    ->middleware(['api.rate_limit:mutation', 'scope:write']); // ADD scope:write

Route::get('/accounts/company/documents/{id}', [...])
    ->middleware(['api.rate_limit:query', 'scope:read']); // ADD scope:read
```

---

### 4. ERROR HANDLING - Rate of Fire: Strict

**A. Response Format Consistency**
- [ ] Upload returns: `success, data, message` - good
- [ ] List returns: `success, data` - good
- [ ] Required returns: `success, data` - good
- [ ] Download returns: raw file (correct)
- [ ] Errors return: `success, message, errors` - inconsistent with 422

**B. Edge Cases Not Handled**
- [ ] Storage disk full - what happens?
- [ ] File write fails mid-upload
- [ ] Duplicate document type upload - allowed or rejected?
- [ ] What if company_profile_id doesn't exist - handled (404)
- [ ] What if user uploads after company deleted - handled (404)

**C. Missing Validation**
- [ ] What if document_type not in allowed list? - handled by validation
- [ ] What if same document uploaded twice? - **NOT HANDLED** - could cause confusion

---

### 5. AUDIT & COMPLIANCE - Rate of Fire: Strict

**A. Current Logging**
- [ ] Upload logs to Log facade - good
- [ ] Logs include: document_id, type, user_uuid, company_profile_id

**B. Missing Audit Requirements**
- [ ] **No audit table** - should log to AuditLog table, not Log facade
- [ ] **No download logging** - who accessed what document?
- [ ] **No modification tracking** - can documents be deleted/replaced?
- [ ] **No immutable record** - can logs be tampered with?

**C. Required Additions**
```php
// Add to audit log (per Signzy, Decentro best practices)
AuditLog::log(
    'company.document.downloaded',
    $document,
    $user,
    null,
    ['ip_address' => $request->ip(), 'user_agent' => $request->userAgent()],
    'kyb,document'
);

// Track: upload, download, verify, reject, delete
```

---

### 6. API DESIGN - Rate of Fire: Moderate

**A. Issues**
- [ ] `company_profile_id` passed in request body - should be path param
- [ ] GET list returns all docs - what if user wants filter by type?
- [ ] No way to delete/replace document
- [ ] No way to mark document as "verified" (admin function)

**B. Better Design**
```php
// Upload: PUT /accounts/company/{companyProfileId}/documents
// (idempotent - upload or replace)

// List: GET /accounts/company/{companyProfileId}/documents?type=certificate_of_incorporation

// Delete: DELETE /accounts/company/{companyProfileId}/documents/{documentId}
```

---

### 7. PERFORMANCE - Rate of Fire: Moderate

**A. Concerns**
- [ ] Files stored in `private` disk - what's the backend? Local? S3?
- [ ] No image compression - 10MB limit could be hit quickly
- [ ] No thumbnail generation for preview
- [ ] Large files could timeout upload

**B. Optimizations**
- [ ] Consider presigned URLs for direct-to-S3 upload (Finmid pattern)
- [ ] Add file size warning at 8MB
- [ ] Implement upload chunking for large files

---

### 8. DATA INTEGRITY - Rate of Fire: Strict

**A. Current Model Issues**
- [ ] `company_profile_id` is UUID but references `account_profiles_company.id` - correct
- [ ] No unique constraint on (company_profile_id, document_type) - duplicates allowed
- [ ] `status` field exists but no way to change it via API

**B. Missing Constraints**
- [ ] Could upload same document type multiple times - confusing for verification
- [ ] No cascade delete if company profile deleted

---

### 9. COMPLIANCE - Rate of Fire: Strict

**A. Eswatini Requirements**
- [ ] Documents match business type requirements - model has this
- [ ] Model has REQUIRED_BY_TYPE constant - good
- [ ] Informal businesses exempted - correct (empty array)

**B. KYB Verification Flow Missing**
- [ ] No endpoint to submit company for KYB review
- [ ] No webhook for verification status updates
- [ ] No async verification flow (future requirement)

---

### 10. TESTING - Rate of Fire: Strict

**A. Required Test Cases**
```
Test: Upload valid document
Expected: 201, document created, file stored

Test: Upload with invalid file (exe)
Expected: 422, "The document must be a file of type: jpg, jpeg, png, pdf."

Test: Upload with file > 10MB
Expected: 422, "The document may not be greater than 10240 kilobytes."

Test: Upload without authentication
Expected: 401

Test: Upload with invalid token
Expected: 401

Test: Upload without scope:write
Expected: 403

Test: Upload for company user doesn't own
Expected: 403

Test: Upload duplicate document_type
Allowed? Should reject or allow replace?

Test: List documents for valid company
Expected: 200, array of documents

Test: List documents for invalid company
Expected: 404

Test: Download document user doesn't own
Expected: 403

Test: Download non-existent document
Expected: 404

Test: Required documents for pty_ltd
Expected: 200, includes all 5 required doc types

Test: Required documents for informal
Expected: 200, empty required array
```

**B. Missing**
- [ ] No unit tests for controller
- [ ] No integration tests for upload flow
- [ ] No feature tests for authorization

---

## Critical Issues Found

### HIGH PRIORITY (Security)

1. **File validation incomplete**
   - No `isValid()` check - could accept corrupted uploads
   - Using original filename - path traversal risk
   - No file hash for tamper detection

2. **No encryption at rest**
   - Files stored as plain text in private disk
   - Should use encrypted disk or file-level encryption
   - Violates fintech security standards

3. **No audit trail to database**
   - Using Log facade instead of AuditLog table
   - No download tracking
   - Cannot meet compliance requirements

4. **No scope middleware**
   - All endpoints accessible with any valid token
   - Should require scope:write for mutation, scope:read for query

### MEDIUM PRIORITY

5. **Duplicate document upload allowed**
   - Could upload same type multiple times
   - Should reject or implement idempotent replace

6. **No document count limit**
   - Finmid limits to 10 per business
   - Could abuse with unlimited uploads

7. **No admin verification endpoints**
   - No way to mark documents as verified/rejected
   - No way to add rejection reason

### LOW PRIORITY

8. **API design could be improved**
   - company_profile_id in body vs path
   - No filtering on list endpoint

---

## Recommended Fixes

### Fix 1: Complete File Validation
```php
// In upload method, before creating record
if (!$request->hasFile('document') || !$request->file('document')->isValid()) {
    return response()->json([
        'success' => false,
        'message' => 'Invalid or missing file.',
        'errors' => ['document' => ['File is invalid or missing.']],
    ], 400);
}

$file = $request->file('document');
$fileHash = hash_file('sha256', $file->getRealPath());
$safeName = $file->hashName(); // unique, safe
$extension = $file->extension();
```

### Fix 2: Add Scope Middleware
```php
// In routes/api.php
Route::post('/accounts/company/documents', [...])
    ->middleware(['api.rate_limit:mutation', 'scope:write']);
Route::get('/accounts/company/documents', [...])
    ->middleware(['api.rate_limit:query', 'scope:read']);
Route::get('/accounts/company/documents/{id}', [...])
    ->middleware(['api.rate_limit:query', 'scope:read']);
```

### Fix 3: Add Audit to Database
```php
// In controller, after successful upload
AuditLog::log(
    'company.document.uploaded',
    $document,
    $user,
    null,
    [
        'document_type' => $validated['document_type'],
        'company_profile_id' => $companyProfile->id,
        'file_hash' => $fileHash,
        'ip_address' => $request->ip(),
    ],
    'kyb,document'
);

// Same for download
AuditLog::log(
    'company.document.downloaded',
    $document,
    $user,
    null,
    ['ip_address' => $request->ip()],
    'kyb,document'
);
```

### Fix 4: Add File Hash to Model & Migration
```php
// In migration
$table->string('file_hash')->nullable(); // SHA-256 hash

// In model
protected $casts = [
    'file_hash' => 'string',
];

// In upload, store hash
'file_hash' => hash_file('sha256', $file->getRealPath()),
```

### Fix 5: Prevent Duplicate Document Types
```php
// In service or controller, before create
$existing = AccountProfileCompanyDocument::query()
    ->where('company_profile_id', $companyProfile->id)
    ->where('document_type', $validated['document_type'])
    ->exists();

if ($existing) {
    return response()->json([
        'success' => false,
        'message' => 'Document type already uploaded. Use replace to update.',
        'errors' => ['document_type' => ['A document of this type already exists.']],
    ], 409);
}
```

---

## Review Verdict

**APPROVAL**: CONDITIONAL - BLOCKERS

The code is functional but has critical security gaps:

1. **MUST FIX**: Add `isValid()` check and use `hashName()` instead of original filename
2. **MUST FIX**: Add file hash for tamper detection
3. **MUST FIX**: Add scope middleware to routes
4. **MUST FIX**: Replace Log facade with AuditLog for compliance
5. **SHOULD FIX**: Prevent duplicate document type uploads
6. **SHOULD ADD**: Document count limit (10 max)

**Post-MVP Requirements**:
- Add admin verification endpoints (verify/reject)
- Add webhook for verification status changes
- Implement encrypted storage (AES-256)
- Add presigned URL upload pattern (S3 direct)
- Add document deletion endpoint
- Add filtering to list endpoint

**Compliance Gap**: Current implementation cannot pass security audit for fintech. Audit logs must go to database, not Log facade. Files must be encrypted at rest. Access must be logged.