# Code Review Prompt: Company Account Creation Backend

**Reviewer Persona**: Senior backend engineer with 15+ years fintech experience. Has seen every type of security failure. Misses nothing. Approves nothing that doesn't meet production-grade standards. No mercy for technical debt or shortcuts.

---

## Pre-Review: What Good Looks Like

Before reviewing, understand what industry leaders require for company/business account creation:

### Required Data Points (from FinHub, Airwallex, Conduit, Synctera)
1. **Legal business name** - verified against registry
2. **Registration number** (TIN/EIN/VAT) - format validated, checked for duplicates
3. **Business type** - LLC, corporation, partnership, sole proprietor
4. **Industry classification** - standardized codes (SIC/NAICS)
5. **Registered address** - must be validated, not just collected
6. **Beneficial owners** - anyone with 25%+ ownership (regulatory requirement)
7. **Control persons** - directors, officers with signing authority
8. **Document uploads** - formation documents, proof of address

### KYB Status Flow
- `UNVERIFIED` → `PENDING` → `REVIEW` → `VERIFIED` or `REJECTED`
- Async process with webhooks for status updates

### Error Handling Standards
- `BUSINESS_ALREADY_EXISTS` - return existing business, don't create duplicate
- `VERIFICATION_FAILED` - detailed reason in response
- `DOCUMENT_UPLOAD_FAILED` - format/size requirements clearly stated

---

## Review Checklist

### 1. VALIDATION - Rate of Fire: Strict

**A. Input Validation**
- [ ] Registration number has format validation (alphanumeric, dashes only)
- [ ] Registration number checked for existing business BEFORE attempting create
- [ ] Company size is validated against allowed enum values only
- [ ] Settlement method is validated against allowed enum values only
- [ ] All string fields have max length constraints matching DB schema
- [ ] Industry field has character restrictions (no control characters, no SQL injection vectors)
- [ ] Address field sanitized for XSS (strip_tags, htmlspecialchars)

**B. Missing Validations - ADD THESE**
```
registration_number: ['nullable', 'string', 'max:50', 'regex:/^[A-Z0-9\-]+$/i'],
```
- [ ] Registration number must allow format: alphanumeric + dashes only
- [ ] Company name should be checked for existing similar names (fuzzy match)

**C. Request Size Limits**
- [ ] API request body size limit enforced at middleware level
- [ ] Large text fields truncated before DB insert to prevent truncation errors

---

### 2. AUTHORIZATION - Rate of Fire: Strict

**A. Authentication**
- [ ] Endpoint requires valid Bearer token
- [ ] Token scope includes `write` permission
- [ ] Expired tokens rejected at middleware

**B. Pre-Conditions**
- [ ] User MUST have active personal account membership (not just any account)
- [ ] User MUST have KYC status = `approved` (not pending, not basic)
- [ ] Tenant context MUST be present and valid
- [ ] User MUST NOT already have a company account (explicit check)

**C. Authorization Gaps**
- [ ] What if user is suspended? Add: `->where('status', 'active')`
- [ ] What if personal account membership is invited, not owner? Consider role check

---

### 3. TRANSACTION SAFETY - Rate of Fire: Strict

**A. Atomicity**
- [ ] Account creation + profile creation + membership creation wrapped in transaction
- [ ] On ANY exception, ALL changes rolled back (no partial state)
- [ ] Verified: Tenant DB writes AND central DB writes both in same transaction scope

**B. Race Conditions**
- [ ] Duplicate check happens BEFORE insert (prevent race condition exploit)
- [ ] Database unique constraint on account_uuid (already handled by UUID)
- [ ] What about duplicate company_name? Consider adding application-level dedup

**C. Cleanup on Failure**
- [ ] If tenant write succeeds but central write fails: account orphaned?
- [ ] Current cleanup only runs if $account !== null, but what about orphaned records?
- [ ] Add explicit cleanup for partial failures

---

### 4. SECURITY - Rate of Fire: Absolute

**A. Input Sanitization**
- [ ] company_name: XSS sanitized (strip_tags, htmlspecialchars)
- [ ] address: XSS sanitized
- [ ] description: XSS sanitized
- [ ] No SQL injection vectors (NoSqlInjection rule applied to ALL string fields)

**B. Information Leakage**
- [ ] Error messages don't reveal internal DB structure
- [ ] Error messages don't reveal tenant IDs to unauthorized users
- [ ] 403 vs 404 behavior: ensure consistent (don't leak account existence)

**C. Audit Trail**
- [ ] Creation logged with full metadata
- [ ] Log includes: user_uuid, company_name, IP address
- [ ] Logs are immutable (append-only, no updates)

---

### 5. ERROR HANDLING - Rate of Fire: Strict

**A. HTTP Status Codes**
- [ ] 201: Success
- [ ] 400: Invalid input format
- [ ] 401: Missing/invalid auth
- [ ] 403: Precondition failed (no personal account, KYC not approved)
- [ ] 409: Duplicate (already have company account)
- [ ] 422: Validation errors (field-level)
- [ ] 500: Internal server error (catch-all)

**B. Error Response Format**
```json
{
  "success": false,
  "message": "Human readable message",
  "errors": {
    "field_name": ["specific error"]
  }
}
```

**C. Current Issues**
- [ ] 403 responses don't include `errors` key (inconsistent with 422)
- [ ] Some 403s return `success: false`, others might not

---

### 6. DATA INTEGRITY - Rate of Fire: Strict

**A. Required Fields**
- [ ] company_name: NOT NULL in migration
- [ ] industry: NOT NULL in migration
- [ ] company_size: NOT NULL in migration
- [ ] settlement_method: NOT NULL in migration

**B. Optional Fields**
- [ ] registration_number: nullable, good
- [ ] address: nullable, good
- [ ] description: nullable, good
- [ ] verified_at: nullable, good (set by KYB process later)

**C. Data Types**
- [ ] company_name: string(255) matches validation
- [ ] registration_number: string(50) matches validation
- [ ] industry: string(100) - is 100 enough? Consider 255
- [ ] company_size: enum stored as string - correct
- [ ] settlement_method: enum stored as string - correct

---

### 7. PERFORMANCE - Rate of Fire: Moderate

**A. Rate Limiting**
- [ ] Mutation rate limit applied (see route middleware)
- [ ] What about brute-force duplicate checks? Add additional limiting

**B. Query Optimization**
- [ ] Membership queries use indexes (user_uuid, status)
- [ ] No N+1 queries in hot path

**C. Concerns**
- [ ] `createDirect()` uses event sourcing - how long does it take?
- [ ] Timeout handling if account creation takes > 30s?

---

### 8. COMPLIANCE & REGULATORY - Rate of Fire: Strict

**A. KYB Readiness**
- [ ] Profile stores registration_number for future verification
- [ ] Profile stores industry for risk classification
- [ ] Profile stores company_size for risk limits
- [ ] Account capabilities set correctly (`can_receive_payments`)

**B. Missing for Full KYB**
- [ ] No beneficial owners collection
- [ ] No directors/control persons collection
- [ ] No document upload (formation docs)
- [ ] No async verification flow (webhook endpoint)
- [ ] Note: This is acceptable for MVP, but documented for future

**C. Audit Requirements**
- [ ] Account audit log created
- [ ] What about central audit for membership creation?
- [ ] Should log both tenant and central events

---

### 9. TESTING - Rate of Fire: Strict

**A. Required Test Cases**
```
Test: Success - User with personal account + approved KYC creates company
Expected: 201, account created, membership created, profile created

Test: No personal account - User tries to create company without personal
Expected: 403, "A personal account is required"

Test: KYC not approved - User with pending KYC tries to create company  
Expected: 403, "Identity verification is required"

Test: Duplicate company - User already has company tries to create another
Expected: 409, "You already have a company account"

Test: Validation error - Missing required fields
Expected: 422, field-level errors

Test: Invalid company_size - Invalid enum value sent
Expected: 422, "The selected company_size is invalid"

Test: SQL injection attempt - Field contains SQL injection
Expected: 422 or 400, sanitized/rejected

Test: XSS attempt - Company name contains script tags
Expected: Sanitized or rejected

Test: Rate limiting - Multiple rapid requests
Expected: 429 after limit exceeded

Test: Unauthenticated request
Expected: 401
```

**B. Missing Tests**
- [ ] No test file created for CompanyAccountService
- [ ] No test file created for createCompany endpoint

---

### 10. CODE QUALITY - Rate of Fire: Strict

**A. Duplication**
- [ ] createMerchant and createCompany share 90% identical logic
- [ ] Extract common validation to Form Request
- [ ] Extract common preconditions to private method

**B. Type Safety**
- [ ] Return type declared: `array{account: Account, ...}`
- [ ] PHPStan/lint passes? Need to verify

**C. Documentation**
- [ ] Service method has return type docblock
- [ ] Controller method has PHPDoc explaining preconditions

---

## Critical Issues Found

### HIGH PRIORITY

1. **Missing registration_number format validation**
   - Should use regex: `/^[A-Z0-9\-]+$/i`
   - Current: accepts ANY string up to 50 chars

2. **No duplicate registration_number check**
   - What if someone creates company with existing reg number?
   - Add: Check if any company has same registration_number

3. **Inconsistent error response format**
   - 403s don't include `errors` key
   - 422s include `errors` key
   - Make consistent

4. **No PHPStan validation run**
   - Code may have type errors
   - Need: `composer phpstan` or `php vendor/bin/phpstan`

5. **No test coverage**
   - CompanyAccountService has no tests
   - createCompany endpoint has no tests

### MEDIUM PRIORITY

6. **Industry field max:100 may be too short**
   - Consider: "Information Technology Services" = 34 chars
   - "Financial Services and Insurance" = 38 chars
   - 100 is probably fine, but verify against real data

7. **No fuzzy duplicate detection**
   - Someone could create "Acme Corp" and "Acme Corporation" as separate
   - Consider for future: company name similarity check

8. **Audit log only on tenant**
   - Membership created in central DB but not explicitly logged
   - Add audit log for central membership creation

---

## Recommended Fixes

### Fix 1: Add registration_number validation
```php
'registration_number' => ['nullable', 'string', 'max:50', 'regex:/^[A-Z0-9\-]+$/i'],
```

### Fix 2: Add duplicate registration_number check in service
```php
$existingByReg = AccountProfileCompany::query()
    ->where('registration_number', $profileData['registration_number'])
    ->exists();

if ($existingByReg) {
    throw ValidationException::withMessages([
        'registration_number' => ['A company with this registration number already exists.'],
    ]);
}
```

### Fix 3: Fix error response consistency
```php
// In all 403 responses, add empty errors key
return response()->json([
    'success' => false,
    'message' => '...',
    'errors' => [],
], 403);
```

### Fix 4: Add PHPStan to CI
```bash
composer require --dev phpstan/phpstan
./vendor/bin/phpstan analyse app/Domain/Account/Services/CompanyAccountService.php
```

---

## Review Verdict

**APPROVAL**: CONDITIONAL

The code is structurally sound and follows Laravel patterns. However:

1. MUST fix: registration_number validation (security)
2. MUST fix: error response consistency (API contract)
3. MUST verify: PHPStan passes
4. SHOULD add: Tests before production
5. SHOULD document: This is MVP - full KYB requires beneficial owners + documents + async verification

**Blockers for Production**:
- Security: registration_number accepts anything
- Quality: No tests

**Post-MVP Requirements**:
- Add beneficial_owners JSON field
- Add directors JSON field  
- Add documents table for KYB uploads
- Add async verification webhook endpoint
- Add webhook for verification status updates