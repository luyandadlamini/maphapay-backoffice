# Company KYB Verification Post-MVP Implementation Spec

## Context

The company account creation MVP is complete with:
- Company profile creation with Eswatini-aligned fields
- Document upload with security best practices
- Basic authorization

The following features are needed for production-grade KYB verification.

---

## Feature 1: Database Audit Logging

### Current State
- Uses `Log::info()` facade in `CompanyDocumentController`
- No structured audit trail in database
- Cannot meet compliance requirements

### Target State
- All document operations logged to `AuditLog` table (central database)
- Includes: upload, download, verify, reject
- Captures: user_uuid, action, auditable, metadata, IP, user_agent

### Implementation
1. Replace `Log::info()` calls with `AuditLog::log()` in controller
2. Add audit actions: `company.document.uploaded`, `company.document.downloaded`, `company.document.verified`, `company.document.rejected`

---

## Feature 2: Encryption at Rest

### Current State
- Files stored in `private` disk as plain text

### Target State
- Files encrypted using AES-256 before storage
- Encryption keys managed via Laravel's encryption or S3 SSE

### Implementation Options
1. **Laravel Encrypted Disk** - Use `enc` disk with `encrypto` filesystem
2. **S3 Server-Side Encryption** - If using S3, enable SSE-KMS
3. **Application-Level Encryption** - Encrypt before `Storage::put()`

### Recommendation
Use S3 with SSE-KMS (simplest, most secure) or Laravel encrypted disk for local storage.

---

## Feature 3: Admin Verification Endpoints

### Current State
- No way to verify or reject documents via API

### Target State
- Admin can mark documents as `verified` or `rejected`
- Add rejection reason
- Track who verified (verified_by_user_uuid)

### API Design
```
POST /api/accounts/company/documents/{id}/verify
Body: { "status": "verified" | "rejected", "rejection_reason": "..." }

GET /api/accounts/company/documents/{id}/status

GET /api/admin/company-documents/pending  (admin only)
GET /api/admin/company-documents/{id}      (admin only)
```

### Authorization
- Verify/reject requires `admin` role or `kyb:verify` permission
- Add middleware: `is_admin` or custom `can:verify_kyb`

---

## Feature 4: Webhook for Verification Status Changes

### Current State
- No webhook notification when status changes

### Target State
- When document status changes, POST to configured webhook URL
- Include: company_id, document_id, status, timestamp

### API Design
```json
{
  "event": "kyb.document.status_changed",
  "timestamp": "2026-04-15T10:30:00Z",
  "data": {
    "company_uuid": "uuid",
    "document_id": "uuid",
    "document_type": "certificate_of_incorporation",
    "old_status": "pending",
    "new_status": "verified",
    "verified_by": "admin-user-uuid"
  }
}
```

### Implementation
1. Add `webhook_url` configuration to company profile (optional)
2. Create `CompanyKybWebhookService` to send webhooks
3. Queue webhook delivery (don't block request)
4. Add retry logic for failed deliveries

---

## Feature 5: Presigned URL Upload Pattern (S3 Direct)

### Current State
- Files uploaded through API server → can timeout, uses server bandwidth

### Target State
- Two-step pattern:
  1. Client requests upload URL → server returns presigned S3 URL
  2. Client PUTs file directly to S3 → fast, no server bottleneck
  3. Client confirms upload → server creates DB record

### API Design
```
POST /api/accounts/company/documents/upload-url
Body: { "company_profile_id": "uuid", "document_type": "...", "file_name": "doc.pdf", "content_type": "application/pdf", "file_size": 204800 }

Response: {
  "upload_url": "https://s3-bucket.url/upload?X-Amz-Signature=...",
  "expires_at": "2026-04-15T10:45:00Z",
  "upload_token": "unique-token"
}

PUT {upload_url} (direct to S3 with file)

POST /api/accounts/company/documents/confirm
Body: { "upload_token": "unique-token", "document_type": "..." }
```

### Benefits
- Reduced server load
- Faster uploads for large files
- Presigned URLs expire (security)
- Can implement chunked uploads for very large files

---

## Data Models

### Company Document (Updated)
```php
// Existing fields + new
- file_hash (SHA-256) - required
- encryption_version - nullable (for future migration)
- upload_token - nullable (for presigned pattern)
- expires_at - nullable (for presigned pattern)
```

### Company Profile (Updated)
```php
// New fields
- kyb_status: pending|in_progress|verified|rejected
- kyb_submitted_at: datetime
- kyb_verified_at: datetime
- kyb_rejection_reason: text
- webhook_url: string (optional)
- webhook_secret: string (for HMAC verification)
```

---

## Integration Points

| Feature | Files to Modify |
|---------|-----------------|
| Audit Logging | CompanyDocumentController, AuditLog model |
| Encryption | config/filesystems.php, storage migration |
| Admin Endpoints | CompanyDocumentController, routes/api.php |
| Webhooks | CompanyKybWebhookService, config/kyb.php |
| Presigned URL | CompanyDocumentController, S3Service |

---

## Testing Requirements

1. Audit logging - verify all actions logged to AuditLog table
2. Encryption - verify files cannot be read without decryption
3. Admin endpoints - verify only admins can verify/reject
4. Webhooks - verify payload sent, retry on failure
5. Presigned URL - verify direct upload works, token validation

---

## Dependencies

- AWS S3 SDK (if using presigned URLs)
- Laravel Queue (for webhooks)
- Filament (existing admin panel - add KYB management)