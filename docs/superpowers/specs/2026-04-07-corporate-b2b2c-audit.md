# Corporate / B2B2C Domain Model And Controls Audit

Date: 2026-04-07

## Summary

This section validates the source audit's claims about corporate accounts, B2B2C context isolation, business approvals, merchant/KYB lifecycle, and spend controls against the current codebase.

Main conclusion:

- the codebase already contains real business-organization and tenant-context foundations,
- it also contains isolated approval and spend-control components that can support corporate use cases,
- but it does **not** yet present one coherent corporate domain model covering company treasury, memberships, delegated administration, KYB lifecycle, spend controls, payroll/mass payouts, and contract-governed partner behavior.

The right recommendation is not "corporate support is absent." The right recommendation is "converge the existing team/tenant, merchant, approval, and spend-control primitives into a single governed corporate domain."

## Evidence Reviewed

Primary backend evidence:

- [`app/Models/Team.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Models/Team.php)
- [`app/Models/TeamUserRole.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Models/TeamUserRole.php)
- [`app/Policies/TeamPolicy.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Policies/TeamPolicy.php)
- [`app/Actions/Fortify/CreateNewUser.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Actions/Fortify/CreateNewUser.php)
- [`app/Http/Controllers/TeamMemberController.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Http/Controllers/TeamMemberController.php)
- [`app/Http/Middleware/InitializeTenancyByTeam.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Http/Middleware/InitializeTenancyByTeam.php)
- [`app/Http/Middleware/FilamentTenantMiddleware.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Http/Middleware/FilamentTenantMiddleware.php)
- [`app/Resolvers/TeamTenantResolver.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Resolvers/TeamTenantResolver.php)
- [`app/Domain/Commerce/Models/Merchant.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Commerce/Models/Merchant.php)
- [`app/Domain/Commerce/Services/MerchantOnboardingService.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Commerce/Services/MerchantOnboardingService.php)
- [`app/GraphQL/Mutations/Commerce/SubmitMerchantApplicationMutation.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/GraphQL/Mutations/Commerce/SubmitMerchantApplicationMutation.php)
- [`app/GraphQL/Mutations/Commerce/ApproveMerchantMutation.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/GraphQL/Mutations/Commerce/ApproveMerchantMutation.php)
- [`app/Filament/Admin/Resources/MerchantResource.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/MerchantResource.php)
- [`app/Domain/Wallet/Services/MultiSigApprovalService.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Wallet/Services/MultiSigApprovalService.php)
- [`app/Domain/Wallet/Models/MultiSigApprovalRequest.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Domain/Wallet/Models/MultiSigApprovalRequest.php)
- [`app/GraphQL/Queries/X402/X402SpendingLimitsQuery.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/GraphQL/Queries/X402/X402SpendingLimitsQuery.php)
- [`app/GraphQL/Queries/X402/X402PaymentsQuery.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/GraphQL/Queries/X402/X402PaymentsQuery.php)

External reference anchors:

- Apache Fineract documentation for tenant-aware core banking, accounting, approvals, and business-date discipline[^fineract-docs]
- Stripe Connect documentation for connected-account onboarding and capability-gated business activation[^stripe-connect]
- Modern Treasury platform documentation for role/permission sets and approval-oriented operational controls[^modern-treasury-roles]
- SDK.finance backoffice and operating-model references for business users, cashdesk/finance separation, and enterprise operations patterns[^sdk-backoffice-manual][^sdk-cashdesks]

## Claim Validation

### 1. "A user should authenticate once and switch cleanly between personal and business context."

Verdict: `Partial`

What the code shows:

- business organizations are modeled as `Team` records with `is_business_organization`, `organization_type`, `max_users`, and `allowed_roles`
- request tenancy is initialized from the authenticated user's current team
- Filament has tenant-aware session switching and tenant access checks
- GraphQL X402 queries scope business data by `currentTeam`

What remains missing:

- one explicit product-level context model distinguishing personal, merchant, corporate, and partner contexts
- explicit context-switch UX contracts and cache/notification partition rules
- stronger guarantees that all corporate-adjacent reads and writes are context-partitioned, not just selected modules

Corrected finding:

- context switching foundations exist,
- but the platform does not yet expose a complete B2B2C context-isolation model.

### 2. "Corporate RBAC and delegated administration are incomplete."

Verdict: `Confirmed`

What the code shows:

- `TeamUserRole` exists for team-scoped role assignment
- business-team registration seeds allowed roles
- `TeamMemberController` supports adding, editing, and removing business-team members
- access control for team management is owner-centric via `TeamPolicy`

What remains missing:

- capability-based corporate entitlements beyond simple role labels
- amount limits, approval thresholds, and cost-center scoping
- delegated administration logs and approval-bound privilege changes
- maker-checker semantics for corporate admin changes

The source audit is correct that role labels alone are not enough for corporate finance.

### 3. "Merchant / KYB / business onboarding is not yet production-grade."

Verdict: `Confirmed`

What the code shows:

- a merchant model exists and can be viewed in admin
- onboarding, approval, activation, suspension, and termination methods are defined in a service
- GraphQL mutations expose merchant submission and review entry points, but they currently behave like a demo shim rather than a persisted business-onboarding workflow

What remains missing:

- the onboarding service keeps its core state in a private in-memory array rather than durable storage
- submission creates a `Merchant` row with a separate `public_id`, while approval/suspension paths call the in-memory service and then read the database row by `id`
- there is no single canonical persisted merchant-onboarding identity or DB-backed lifecycle authority across submit, approve, suspend, and activate flows
- merchant approval is not anchored to a persistent KYB/case workflow
- capability gating, contract/pricing attachment, and evidence collection are not first-class
- there is no coherent distinction yet between merchant onboarding, corporate onboarding, and API-partner onboarding

The source audit is correct that business-relationship modeling remains incomplete.

### 4. "Corporate approvals and spend controls need a single domain model."

Verdict: `Partial`

What the code shows:

- multi-signature approval flows already exist for wallet operations
- approval requests persist initiator, signer decisions, quorum, metadata, expiry, and completion state
- X402 spending limits and payments already scope some spending controls by team

What remains missing:

- one corporate approval-policy model that governs treasury actions, payouts, payroll, and delegated admin changes
- business rules connecting approval thresholds to company roles and limits
- a company treasury model linked to departmental or employee spend accounts
- evidence-bound expense or payout approvals

Corrected finding:

- approval and spend-control primitives exist,
- but they are not yet unified into a corporate operating model.

### 5. "Payroll, batch payouts, and corporate treasury operations are missing."

Verdict: `Confirmed`

What the code shows:

- treasury capabilities exist elsewhere in the platform,
- but this section's evidence does not show a corporate payroll or batch-disbursement domain tied to company context.

What remains missing:

- payroll batches
- payout batches with line-level validation and exception handling
- beneficiary validation and duplicate-recipient controls
- company treasury vs employee spend-account distinction
- corporate reconciliation anchored to supporting evidence

This is a real gap, not just an unproven claim.

## Corrected Findings

### What already exists

- business-organization flagging on teams
- team-scoped tenant initialization and switching
- business-team membership management
- team-scoped roles
- merchant lifecycle states and admin visibility
- merchant onboarding entry points, but only as a transitional/demo-style shim
- multi-sig approval infrastructure
- team-scoped spending-limit queries

### What is materially missing

- one canonical `Company` / corporate workspace model over the current team primitive
- one corporate-membership and entitlement model with scoped capabilities
- persistent KYB / merchant / partner onboarding workflows with evidence and approvals
- company treasury, departmental spend, and employee spend-account boundaries
- payroll and mass-payout batch lifecycle
- contract-specific pricing, API client ownership, and webhook ownership under corporate context

## Recommendation

Build the corporate/B2B2C layer as an incremental convergence, not a new parallel stack:

- treat `Team` plus tenancy as the current context primitive,
- introduce a first-class corporate profile over that primitive,
- unify merchant/KYB/business onboarding into persistent case-driven workflows,
- and route approvals, treasury controls, payouts, and delegated administration through one corporate policy model.

The best-practice direction is consistent with Fineract's tenant/accounting rigor, Stripe Connect's business-onboarding capability posture, Modern Treasury's role/permission and approval orientation, and SDK.finance's explicit enterprise-operations segmentation.[^fineract-docs][^stripe-connect][^modern-treasury-roles][^sdk-backoffice-manual]

## Final Verdict

The right rewrite for this section is:

"MaphaPay already has real business-organization and tenant-context foundations. The unresolved issue is that merchant onboarding, team administration, approvals, and spend controls still live as separate capabilities rather than one coherent corporate/B2B2C domain."

## Footnotes

[^fineract-docs]: Apache Fineract documentation: <https://fineract.apache.org/docs/current/>
[^stripe-connect]: Stripe Connect documentation, onboarding and connected accounts: <https://docs.stripe.com/connect>
[^modern-treasury-roles]: Modern Treasury platform docs, permission sets and roles: <https://docs.moderntreasury.com/platform/docs/standard-options-for-permission-sets-and-roles>
[^sdk-backoffice-manual]: SDK.finance backoffice manual: <https://sdk.finance/backofficemanual/>
[^sdk-cashdesks]: SDK.finance cashdesks article: <https://sdk.finance/knowledge-base/cashdesks/>
