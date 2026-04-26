# COMPREHENSIVE SECURITY & QUALITY AUDIT PROMPT
## Minor Accounts Implementation (Phases 0-12)
### MaphaPay Backoffice - Banking/Fintech Application

---

## CRITICAL WARNING

**This is a banking application handling real money. Any vulnerabilities, logic flaws, or security gaps can result in:**
- Financial loss to users
- Regulatory violations and penalties
- Legal liability
- Reputational damage
- Loss of customer trust

**Audit must leave NO assumptions unexamined. When in doubt, research best practices for fintech/banking applications, Laravel security, and mobile payment systems.**

---

## AUDIT SCOPE

You are auditing the COMPLETE implementation of Minor Accounts (ages 6-17) from Phase 0 through Phase 12. This includes:

### Phases Overview:
- **Phase 0**: Account model updates, permission levels, parent-child relationships
- **Phase 1**: Guardian management, co-guardian support
- **Phase 2**: Account freezing/locking, emergency allowance
- **Phase 3**: Spend approvals (parent authorization for transactions)
- **Phase 4**: Points system (earn/redeem)
- **Phase 5**: Chores system (tasks, completions, payments)
- **Phase 6**: Rewards system (badges, milestones)
- **Phase 7**: Family funding links, external remittances
- **Phase 8**: Savings groups for teens
- **Phase 9**: Family reconciliation, MTN MoMo integration
- **Phase 10**: Lifecycle automation (birthday transitions, age 18 migration)
- **Phase 11**: (Implicit - likely refinements)
- **Phase 12**: Virtual card support for Rise tier (ages 13+)

---

## AUDIT FRAMEWORK

### 1. SECURITY AUDIT (CRITICAL PRIORITY)

#### 1.1 Authentication & Authorization
- [ ] All endpoints verify user identity properly
- [ ] Guardian authorization checks use correct pattern: `authorizeGuardian(User, Account)` NOT account-only
- [ ] Non-guardians receive 403 Forbidden, not 401/404
- [ ] PIN-based authentication for children (ages 12-15) is properly secured
- [ ] Invite codes expire after 72 hours and cannot be reused
- [ ] No privilege escalation possible (child → guardian, guardian → other child's data)

#### 1.2 Financial Transaction Security
- [ ] All money movements require authorization
- [ ] Spending limits enforced at DATABASE level, not just UI level
- [ ] Transaction amounts validated against limits BEFORE processing
- [ ] No race conditions in balance updates (use database transactions with locking)
- [ ] Idempotency keys prevent duplicate transactions
- [ ] Merchant category blocks enforced at transaction authorization time
- [ ] Card token queries use `issuer_card_token` NOT `card_token`
- [ ] Virtual card creation requires tier verification (`$account->tier === 'rise'`)

#### 1.3 Data Protection
- [ ] Sensitive data (PII, financial) encrypted at rest
- [ ] API responses never expose full card numbers, CVV, or PINs
- [ ] Transaction history access restricted to account owner + guardians
- [ ] Child data properly anonymized at age 18
- [ ] GDPR/POPIA compliance: data purge vs. transaction retention distinction

#### 1.4 Input Validation & Sanitization
- [ ] All user inputs validated (types, ranges, formats)
- [ ] SQL injection prevented (use parameterized queries/Eloquent)
- [ ] XSS prevented (output encoding in API responses)
- [ ] CSRF protection on state-changing operations
- [ ] Rate limiting on sensitive endpoints (login, transactions)

---

### 2. CODE QUALITY AUDIT

#### 2.1 Architecture & Design Patterns
- [ ] Domain-Driven Design properly followed (domain logic in `app/Domain/`)
- [ ] Services are stateless and focused (single responsibility)
- [ ] Models properly encapsulate business logic
- [ ] Event sourcing used for state changes (financial transactions)
- [ ] Proper use of Laravel conventions (service containers, facades)

#### 2.2 Error Handling
- [ ] No `BusinessException` used (doesn't exist - use `InvalidArgumentException`)
- [ ] Proper exception types: `InvalidArgumentException` (validation), `RuntimeException` (operations)
- [ ] All exceptions logged with sufficient context
- [ ] User-facing errors are friendly, not stack traces
- [ ] Financial failures don't leave inconsistent state

#### 2.3 Code Structure
- [ ] Constants defined in dedicated classes (e.g., `MinorCardConstants`)
- [ ] No magic numbers or strings (use named constants)
- [ ] Proper namespacing (`App\Domain\Account\...`)
- [ ] Consistent naming conventions (camelCase methods, PascalCase classes)
- [ ] PHPDoc comments on public interfaces

---

### 3. ROBUSTNESS AUDIT

#### 3.1 Transaction Safety
- [ ] All multi-step operations use database transactions
- [ ] Locks acquired before balance modifications
- [ ] Rollback on any failure in transaction chain
- [ ] No partial state if process fails mid-way

#### 3.2 Concurrency Handling
- [ ] Optimistic locking for concurrent updates
- [ ] Race condition prevention on:
  - Points earning/redemption
  - Balance updates
  - Level transitions
  - Card issuance approvals
- [ ] Distributed lock mechanism for critical sections

#### 3.3 Edge Cases
- [ ] Child turns 18 mid-transaction
- [ ] Parent account closed/frozen (child orphan protection)
- [ ] Multiple guardians with conflicting decisions
- [ ] Network failures during transactions
- [ ] Expired session during multi-step flow
- [ ] Duplicate transaction submissions (idempotency)
- [ ] Points transfer at account closure

#### 3.4 Data Integrity
- [ ] Foreign key constraints at database level
- [ ] Unique constraints where required
- [ ] NOT NULL constraints on critical fields
- [ ] Cascading deletes properly configured
- [ ] UUIDs used for all external identifiers

---

### 4. API CONTRACT AUDIT

#### 4.1 Endpoint Security
- [ ] All financial endpoints require authentication
- [ ] Guardian-only endpoints verify guardian role
- [ ] Child endpoints verify minor account ownership
- [ ] Cross-account access prevented (user A cannot access user B's data)

#### 4.2 Request/Response Validation
- [ ] All inputs validated with strong types
- [ ] Response schemas consistent
- [ ] Proper HTTP status codes (200, 201, 400, 403, 404, 422)
- [ ] No sensitive data in error responses

#### 4.3 Rate Limiting
- [ ] Authentication endpoints rate limited
- [ ] Transaction endpoints rate limited
- [ ] Resource creation endpoints rate limited

---

### 5. TEST COVERAGE AUDIT

#### 5.1 Unit Tests
- [ ] Service logic tested in isolation
- [ ] Edge cases covered (expired, denied, concurrent)
- [ ] Tier verification tested (Rise vs Grow rejection)
- [ ] Authorization checks tested (guardian vs non-guardian)
- [ ] Card token queries use `issuer_card_token`

#### 5.2 Integration Tests
- [ ] Full approval flow tested
- [ ] Guardian-only endpoints return 403 for non-guardians
- [ ] Tier-based access enforced
- [ ] Data isolation between accounts

#### 5.3 Security Tests
- [ ] Unauthorized access attempts return 403
- [ ] Cross-account data access prevented
- [ ] Input validation rejects invalid data

---

## FILES TO AUDIT

### Domain Models (Check existence and correctness):
```
app/Domain/Account/Models/Account.php
app/Domain/Account/Models/AccountMembership.php
app/Domain/Account/Models/GuardianInvite.php
app/Domain/Account/Models/MinorSpendApproval.php
app/Domain/Account/Models/MinorReward.php
app/Domain/Account/Models/MinorRedemptionApproval.php
app/Domain/Account/Models/MinorRedemptionOrder.php
app/Domain/Account/Models/MinorChore.php
app/Domain/Account/Models/MinorChoreCompletion.php
app/Domain/Account/Models/MinorPointsLedger.php
app/Domain/Account/Models/MinorFamilyFundingLink.php
app/Domain/Account/Models/MinorFamilyFundingAttempt.php
app/Domain/Account/Models/MinorFamilySupportTransfer.php
app/Domain/Account/Models/MinorFamilyReconciliationException.php
app/Domain/Account/Models/MinorAccountLifecycleTransition.php
app/Domain/Account/Models/MinorAccountLifecycleException.php
app/Domain/Account/Models/MinorCardLimit.php (Phase 12)
app/Domain/Account/Models/MinorCardRequest.php (Phase 12)
```

### Domain Services:
```
app/Domain/Account/Services/MinorAccountAccessService.php
app/Domain/Account/Services/AccountService.php
app/Domain/Account/Services/AccountMembershipService.php
app/Domain/Account/Services/MinorNotificationService.php
app/Domain/Account/Services/MinorChoreService.php
app/Domain/Account/Services/MinorPointsService.php
app/Domain/Account/Services/MinorRewardService.php
app/Domain/Account/Services/MinorRedemptionOrderService.php
app/Domain/Account/Services/MinorFamilyIntegrationService.php
app/Domain/Account/Services/MinorFamilyFundingPolicy.php
app/Domain/Account/Services/MinorFamilyReconciliationService.php
app/Domain/Account/Services/MinorAccountLifecycleService.php
app/Domain/Account/Services/MinorAccountLifecyclePolicy.php
app/Domain/Account/Services/MinorCardRequestService.php (Phase 12)
app/Domain/Account/Services/MinorCardService.php (Phase 12)
```

### API Controllers:
```
app/Http/Controllers/Api/Account/MinorCardController.php (Phase 12)
app/Http/Controllers/Api/MinorAccountController.php
app/Http/Controllers/Api/MinorFamilyFundingLinkController.php
app/Http/Controllers/Api/MinorFamilySupportTransferController.php
app/Http/Controllers/Api/CoGuardianController.php
```

### Migrations:
```
database/migrations/tenant/2026_04_16_000001_add_minor_account_columns.php
database/migrations/tenant/2026_04_17_100000_create_minor_spend_approvals_table.php
database/migrations/tenant/2026_04_18_100000_create_minor_points_ledger_table.php
database/migrations/tenant/2026_04_18_100003_create_minor_chores_table.php
database/migrations/tenant/2026_04_18_100002_create_minor_reward_redemptions_table.php
database/migrations/tenant/2026_04_20_099999_create_minor_rewards_table.php
database/migrations/tenant/2026_04_20_100000_add_phase_8_columns_to_minor_rewards_table.php
database/migrations/tenant/2026_04_20_100001_recreate_minor_reward_redemptions_table.php
database/migrations/tenant/2026_04_20_100002_create_minor_redemption_approvals_table.php
database/migrations/tenant/2026_04_23_110000_create_minor_account_lifecycle_transitions_table.php
database/migrations/tenant/2026_04_23_110100_create_minor_account_lifecycle_exceptions_table.php
database/migrations/tenant/2026_04_23_110110_create_minor_account_lifecycle_exception_acknowledgments_table.php
database/migrations/tenant/2026_04_24_002653_create_minor_card_limits_table.php (Phase 12)
database/migrations/tenant/2026_04_24_002653_create_minor_card_requests_table.php (Phase 12)
database/migrations/tenant/2026_04_24_002653_add_minor_account_uuid_to_cards_table.php (Phase 12)
```

### Constants:
```
app/Domain/Account/Constants/MinorCardConstants.php (Phase 12)
```

### Commands:
```
app/Console/Commands/ExpireMinorCardRequests.php (Phase 12)
app/Console/Commands/ExpireMinorSpendApprovals.php
app/Console/Commands/EvaluateMinorAccountLifecycleTransitions.php
```

### Filament Resources:
```
app/Filament/Admin/Resources/MinorCardRequestResource.php (Phase 12)
app/Filament/Admin/Resources/MinorAccountLifecycleExceptionResource.php
app/Filament/Admin/Resources/MinorAccountLifecycleTransitionResource.php
app/Filament/Admin/Resources/MinorFamilyFundingLinkResource.php
app/Filament/Admin/Resources/MinorFamilyFundingAttemptResource.php
app/Filament/Admin/Resources/MinorFamilyReconciliationExceptionResource.php
```

### Tests:
```
tests/Unit/Domain/Account/Services/MinorCardRequestServiceTest.php (Phase 12)
tests/Unit/Domain/Account/Services/MinorCardServiceTest.php (Phase 12)
tests/Feature/Http/Controllers/Api/MinorCardControllerTest.php (Phase 12)
tests/Feature/Http/Controllers/Api/MinorAccountControllerTest.php
tests/Feature/Http/Controllers/Api/MinorFamilyFundingLinkControllerTest.php
tests/Feature/Http/Controllers/Api/MinorFamilySupportTransferControllerTest.php
tests/Feature/Http/Controllers/Api/CoGuardianControllerTest.php
tests/Feature/Http/Controllers/Api/MinorSpendApprovalControllerTest.php
tests/Feature/Http/Controllers/Api/MinorChoreTest.php
tests/Feature/Http/Controllers/Api/MinorRewardTest.php
tests/Feature/Http/Controllers/Api/MinorRedemptionOrdersControllerTest.php
tests/Feature/Http/Controllers/Api/MinorPointsServiceTest.php
tests/Feature/Http/Controllers/Api/MinorEmergencyBypassTest.php
```

---

## CRITICAL PATTERNS TO VERIFY

### Authorization Pattern (MUST USE):
```php
// CORRECT - User + Account pair
$user = $request->user();
$minorAccount = Account::where('uuid', $minorUuid)->firstOrFail();
$this->accessService->authorizeGuardian($user, $minorAccount); // throws AuthorizationException

// WRONG - Will fail
$this->accessService->hasGuardianAccess($user->account, $minorAccount);
```

### Card Token Query (MUST USE):
```php
// CORRECT
Card::where('issuer_card_token', $cardToken)

// WRONG
Card::where('card_token', $cardToken)
```

### Tier Verification (MUST USE):
```php
// CORRECT
if ($account->tier !== 'rise') {
    throw new \InvalidArgumentException('Virtual cards are only available for Rise tier');
}

// WRONG - DO NOT derive from date_of_birth on accounts table
$profile = UserProfile::where('user_id', $account->user_id)->first();
$age = $profile?->date_of_birth->diffInYears(now());
```

### Exception Handling (MUST USE):
```php
// CORRECT
throw new \InvalidArgumentException('Validation message');
throw new \RuntimeException('Operational error');

// WRONG - BusinessException doesn't exist
throw new \BusinessException('Message');
```

### User Identification (MUST USE):
```php
// CORRECT - Use User's uuid
'requested_by_user_uuid' => $requester->uuid

// WRONG
'requested_by_user_uuid' => $requester->account->uuid
```

---

## RESEARCH REQUIREMENTS

Before finalizing audit, research:

1. **Laravel Security Best Practices**:
   - CSRF protection
   - XSS prevention
   - SQL injection prevention
   - Authentication hardening
   - Rate limiting strategies

2. **Fintech/Banking Security Standards**:
   - PCI-DSS requirements for card handling
   - PSD2 (European) requirements
   - Strong Customer Authentication (SCA)
   - Transaction monitoring requirements
   - Segregation of duties

3. **Laravel Financial Transactions**:
   - Database transaction patterns
   - Optimistic/pessimistic locking
   - Idempotency implementation
   - Race condition prevention

4. **Mobile Payment Security**:
   - Tokenization standards
   - Device binding for provisioning
   - Fraud detection patterns

---

## OUTPUT FORMAT

Provide your audit in the following format:

### Executive Summary
- Critical findings count
- High priority issues
- Recommended immediate actions

### Detailed Findings
For each issue found:
1. **Severity**: Critical / High / Medium / Low
2. **Location**: File path and line number
3. **Description**: What the issue is
4. **Impact**: Security/financial/compliance risk
5. **Evidence**: Code snippet or test demonstrating the issue
6. **Remediation**: Specific fix required

### Verification Commands
Provide commands to verify fixes:
```bash
# Run static analysis
./vendor/bin/phpstan analyse --memory-limit=2G

# Run unit tests
./vendor/bin/pest tests/Unit/Domain/Account/Services/

# Run integration tests
./vendor/bin/pest tests/Feature/Http/Controllers/Api/Minor

# Run security-focused tests
./vendor/bin/pest --filter=Authorization
```

---

## FINAL CHECKLIST

Before submitting audit, verify you have:

- [ ] Examined ALL domain models for security/quality
- [ ] Examined ALL services for authorization patterns
- [ ] Examined ALL controllers for input validation
- [ ] Examined ALL migrations for data integrity
- [ ] Examined ALL tests for coverage
- [ ] Verified no BusinessException usage
- [ ] Verified authorizeGuardian(User, Account) pattern
- [ ] Verified issuer_card_token usage
- [ ] Verified tier check uses $account->tier
- [ ] Verified user identification uses $user->uuid
- [ ] Checked for race conditions in financial operations
- [ ] Checked for SQL injection vulnerabilities
- [ ] Checked for authorization bypasses
- [ ] Checked for data exposure in responses
- [ ] Verified transaction safety (rollback on failure)
- [ ] Verified idempotency for money operations

**Remember: This is a banking app. Mistakes can be catastrophic. Be thorough.**

---

## REFERENCE: ORIGINAL PLAN

Review the original implementation plan for context:
`/Users/Lihle/Development/Coding/maphapay-backoffice/docs/superpowers/plans/2026-04-16-minor-accounts-feature-ages-6-17.md`

This plan defines:
- 8 permission levels (Grow/Rise tiers)
- Points & rewards system
- Chore-to-allowance automation
- Shared family goals
- Parental controls (spending limits, merchant blocks, freeze)
- Virtual card flow (Phase 12)
- Compliance & regulatory requirements
- 13 implementation phases

Ensure implementation matches plan specifications exactly.

---

**END OF AUDIT PROMPT**
