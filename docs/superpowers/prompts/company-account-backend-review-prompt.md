# Code Review Prompt: Company Account Creation Backend (Eswatini-Aligned)

**Reviewer Persona**: Senior backend engineer with 15+ years fintech experience. Has seen every type of security failure. Misses nothing. Approves nothing that doesn't meet production-grade standards. No mercy for technical debt or shortcuts.

---

## Pre-Review: Eswatini Business Registration Requirements

### Business Types & Classification

**Formal Business Types (Companies Act 2009):**
| Type | Description | Documents Required |
|------|-------------|-------------------|
| **Private Company (Pty) Ltd** | Standard for most businesses, limited liability | Certificate of Incorporation, Form J, Memo & Articles, Declaration of Compliance, Directors IDs, TIN, Trading License |
| **Public Company** | Large enterprises, can offer shares to public | Same as Pty Ltd + stricter governance |
| **Foreign Company Branch** | External company operating in Eswatini | Certificate of Incorporation, Board resolution, Form J, Local manager details |
| **Sole Trader / Individual** | Individual business owner | ID copy, Trading License, Proof of address, Bank statement |
| **Informal** | Exempted activities (hawker, barber, cobbler, etc.) | ID only - no formal registration |

**Company Size Classification (Eswatini MSME Policy 2018):**
| Size | Employees | Annual Turnover |
|------|-----------|-----------------|
| Micro | 0-10 | <E50,000 |
| Small | 11-20 | E50,000 - E2M |
| Medium | 21-60 | E2M - E5M |
| Large/Enterprise | 60+ | >E5M |

### Required Registration Numbers

1. **TIN (Tax Identification Number)** - From Eswatini Revenue Service (ERS), 10-digit format
2. **Company Registration Number** - Format: `1-2009` (sequential from Companies Act)
3. **Trading License Number** - From Commerce Department
4. **For Sole Traders** - Can use TIN or National ID number

---

## Review Checklist

### 1. VALIDATION - Rate of Fire: Strict

**A. Input Validation - Eswatini-Aligned**
- [ ] Registration number: TIN format is 10 digits (e.g., `1234567890`)
- [ ] Registration number: Company format could be `1-2009` or sequential
- [ ] Company name: Must allow spaces, apostrophes (e.g., "Mapha's Business")
- [ ] Industry: Should match Eswatini industry codes (Agriculture, Manufacturing, Services, etc.)
- [ ] Company size: Map to Eswatini MSME classification (small/medium/large/enterprise)
- [ ] Address: Must handle both Urban and Swazi Nation Land addresses

**B. Eswatini-Specific Validation**
```
// TIN validation (10 digits)
'tin_number' => ['nullable', 'string', 'max:20', 'regex:/^\d{10}$/'],

// Company registration number (format: 1-YYYY or sequential)
'registration_number' => ['nullable', 'string', 'max:50', 'regex:/^\d+-\d{4}$|^[A-Z0-9\-]+$/i'],

// Industry should be from standard list
'industry' => ['required', 'string', 'max:100', 'in:agriculture,mining,manufacturing,construction,retail,wholesale,services,transport,ict,finance,tourism,education,health,other'],
```

**C. Informal/Sole Trader Considerations**
- [ ] Informal businesses may NOT have registration_number - handle nullable gracefully
- [ ] Sole traders may use TIN instead of company registration number
- [ ] Industry field should not be required for informal (or make nullable)

---

### 2. AUTHORIZATION - Rate of Fire: Strict

**A. Authentication**
- [ ] Endpoint requires valid Bearer token
- [ ] Token scope includes `write` permission
- [ ] Expired tokens rejected at middleware

**B. Pre-Conditions - Eswatini Context**
- [ ] User MUST have active personal account membership
- [ ] User MUST have KYC status = `approved` (not pending, not basic)
- [ ] Tenant context MUST be present and valid
- [ ] User MUST NOT already have a company account

**C. Additional Eswatini Requirements**
- [ ] Should we verify user has TIN before company account? (Future: connect to ERS)
- [ ] Should we require trading license for certain business types? (Future enhancement)

---

### 3. TRANSACTION SAFETY - Rate of Fire: Strict

**A. Atomicity**
- [ ] Account creation + profile creation + membership creation wrapped in transaction
- [ ] On ANY exception, ALL changes rolled back

**B. Race Conditions**
- [ ] Duplicate check happens BEFORE insert (prevent race condition)
- [ ] Add duplicate check for TIN if provided
- [ ] Add duplicate check for company_name (fuzzy match future consideration)

**C. Cleanup on Failure**
- [ ] If tenant write succeeds but central write fails: account orphaned?
- [ ] Current cleanup handles this - verify

---

### 4. SECURITY - Rate of Fire: Absolute

**A. Input Sanitization**
- [ ] company_name: XSS sanitized
- [ ] address: XSS sanitized
- [ ] description: XSS sanitized
- [ ] No SQL injection vectors

**B. Information Leakage**
- [ ] Error messages don't reveal internal DB structure
- [ ] 403 vs 404 behavior consistent

**C. Eswatini-Specific**
- [ ] TIN numbers should NOT be logged in full (PII - mask in logs)
- [ ] National ID numbers should NOT be logged (if collected)

---

### 5. ERROR HANDLING - Rate of Fire: Strict

**A. HTTP Status Codes**
- [ ] 201: Success
- [ ] 400: Invalid input format
- [ ] 401: Missing/invalid auth
- [ ] 403: Precondition failed
- [ ] 409: Duplicate
- [ ] 422: Validation errors
- [ ] 500: Internal server error

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
- [ ] 403 responses include `errors` key consistently? Verify

---

### 6. DATA INTEGRITY - Rate of Fire: Strict

**A. Required Fields - Updated for Eswatini**
- [ ] company_name: NOT NULL, max 255
- [ ] registration_number (TIN): nullable, max 50 (TIN or company reg number)
- [ ] industry: NOT NULL, max 100
- [ ] company_size: NOT NULL, enum: small/medium/large/enterprise
- [ ] settlement_method: NOT NULL

**B. New Fields to Consider for Eswatini**
- [ ] tin_number: Separate field for Tax ID (different from registration_number)
- [ ] trading_license_number: For verification (future)
- [ ] business_type: Formal classification (pty_ltd, public, sole_trader, informal)
- [ ] registration_type: urban vs swazi_nation_land

**C. Informal Business Support**
- [ ] For `business_type = informal`: registration_number should be truly optional
- [ ] For `business_type = informal`: industry could be general category

---

### 7. COMPLIANCE & REGULATORY - Rate of Fire: Strict

**A. Eswatini Requirements**
- [ ] Profile stores TIN for ERS verification
- [ ] Profile stores registration_number for Companies Registry verification
- [ ] Industry classification supports Eswatini economy sectors
- [ ] Company size maps to MSME policy definitions

**B. KYB Readiness**
- [ ] Current profile stores: registration_number, industry, company_size
- [ ] Future needs: beneficial owners, directors, documents
- [ ] Future needs: TIN verification (connect to ERS API)
- [ ] Future needs: Trading license verification

**C. Audit Trail**
- [ ] Account audit log created
- [ ] Include: user_uuid, company_name, tin_number (masked), registration_type

---

### 8. TESTING - Rate of Fire: Strict

**A. Required Test Cases - Eswatini Scenarios**
```
Test: Success - Pty Ltd company with TIN
Expected: 201, company created, TIN stored

Test: Success - Sole Trader (no company reg number)
Expected: 201, company created, registration_number null

Test: Success - Informal business (no docs)
Expected: 201, company created, minimal data

Test: Invalid TIN format - Not 10 digits
Expected: 422, "TIN must be 10 digits"

Test: Duplicate TIN - Another company has same TIN
Expected: 409, "A company with this TIN already exists"

Test: No personal account - User tries to create company
Expected: 403, "A personal account is required"

Test: KYC not approved
Expected: 403, "Identity verification is required"

Test: Duplicate company account - User already has one
Expected: 409, "You already have a company account"
```

---

### 9. CODE QUALITY - Rate of Fire: Strict

**A. Duplication**
- [ ] createMerchant and createCompany share significant logic
- [ ] Consider: Extract common validation to Form Request
- [ ] Consider: Extract common preconditions to trait/service

**B. Documentation**
- [ ] Service method has PHPDoc
- [ ] Controller has PHPDoc explaining Eswatini-specific requirements

---

## Critical Issues Found

### HIGH PRIORITY

1. **registration_number validation - needs Eswatini alignment**
   - Current: regex `/^[A-Z0-9\-]+$/i` - too permissive
   - Should support: TIN (10 digits), company reg (1-2009 format), or alphanumeric
   - Suggest: Make registration_number truly optional (informal businesses)

2. **Missing tin_number field**
   - TIN is different from company registration number
   - Should be separate field for ERS verification

3. **Missing business_type field**
   - Need to distinguish: pty_ltd, public, sole_trader, informal
   - Affects which documents/fields are required

4. **Missing test coverage**
   - No unit tests for CompanyAccountService
   - No endpoint tests for createCompany

---

## Recommended Fixes

### Fix 1: Add tin_number field
```php
'tin_number' => ['nullable', 'string', 'max:20', 'regex:/^\d{10}$/'],
```

### Fix 2: Add business_type field
```php
'business_type' => 'required|in:pty_ltd,public,sole_trader,informal',
```

### Fix 3: Adjust validation for informal
```php
// For business_type = informal, some fields become optional
$rules = [
    'company_name' => ['required', 'string', 'max:255'],
    'business_type' => 'required|in:pty_ltd,public,sole_trader,informal',
    'industry' => 'required_if:business_type,pty_ltd,public,sole_trader|nullable_if:business_type,informal',
    'company_size' => 'required_if:business_type,pty_ltd,public,sole_trader|nullable_if:business_type,informal',
];
```

### Fix 4: Make registration_number truly optional
```php
// Remove regex, accept any format (TIN, company reg, or none for informal)
'registration_number' => ['nullable', 'string', 'max:50'],
```

---

## Review Verdict

**APPROVAL**: CONDITIONAL

The code is structurally sound and follows Laravel patterns. However:

1. MUST fix: Align registration_number with Eswatini formats (TIN + company reg)
2. MUST add: tin_number field for ERS verification
3. MUST add: business_type to distinguish formal vs informal
4. SHOULD: Tests before production
5. SHOULD document: This is MVP - full KYB requires document uploads + ERS integration

**Blockers for Production**:
- Missing business_type field
- Missing tin_number field
- No tests

**Post-MVP Requirements**:
- Add beneficial_owners JSON field
- Add directors list
- Add document upload (certificate of incorporation, trading license)
- Add ERS TIN verification API integration
- Add async verification webhook
- Add support for Swazi Nation Land addresses