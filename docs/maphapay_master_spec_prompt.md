# MaphaPay — Master Prompt for Full Specification Document + Implementation Plan

## Objective

Produce a **production-grade master specification document and implementation plan** for the MaphaPay Back Office that is so detailed, explicit, and implementation-ready that **any competent coding agent or engineering team can execute it with minimal ambiguity**.

This is **not** a high-level product brief.

This is a **deep technical + operational specification** covering:
- product requirements
- admin UX / information architecture
- domain-to-UI mapping
- entity models
- workflows
- permissions
- audit controls
- reconciliation controls
- implementation sequencing
- file paths
- code touchpoints
- pseudocode
- class/page/resource suggestions
- migration/read-model suggestions
- API/UI contracts
- test plans
- acceptance criteria

The final deliverable must be detailed enough to function as:
- a PRD
- an admin UX spec
- a technical design doc
- a backlog source
- an implementation blueprint
- a QA/UAT checklist
- an operational launch-readiness plan

---

## Repo Paths (Source of Truth)

Use these exact local repositories as the primary source of truth and repeatedly revisit them whenever needed:

1. **MaphaPay Mobile App**  
   `/Users/Lihle/Development/Coding/maphapayrn`

2. **Current Back Office / Current Backend (FinAegis-based)**  
   `/Users/Lihle/Development/Coding/maphapay-backoffice`

3. **Old Backend / Legacy Operational Baseline**  
   `/Users/Lihle/Development/Coding/maphapay-backend`

---

## Inputs You Must Use

You have already been given the outputs of two prior passes:
- **Pass 1:** `/Users/Lihle/Development/Coding/maphapay-backoffice/docs/maphapay_pass1_inventory.md`   system inventory and forensic discovery
- **Pass 2:** `/Users/Lihle/Development/Coding/maphapay-backoffice/docs/maphapay_pass2_audit.md`   full audit and gap analysis

Treat those outputs as important working documents, but **do not rely on them blindly**.
Use them as a guide, then **re-open the repos and verify the evidence in code** before making any strong claims.

You must use four evidence layers in this order:

### Evidence Priority
1. **Actual code and repo structure**
2. **Audit outputs from Pass 1 and Pass 2**
3. **README / docs / comments**
4. **External best-practice benchmarks**

If a pass result says something but code does not confirm it, label it:
- `unverified`
- `documented but not proven`
- `inferred from structure`
- `verified in code`

---

## What the Prior Findings Already Suggest

The current findings indicate the following likely truths, which you must verify in code and then use to shape the final specification:

### Mobile app operational demands
The mobile app appears to require back-office support for:
- auth and identity controls
- wallet and account visibility
- send money
- request money
- QR pay
- merchant pay
- linked wallets
- MTN MoMo
- top-up / cash-in
- cash-out
- rewards
- notifications
- KYC / profile verification
- pockets / savings
- group savings / stokvel-like flows
- utility and airtime purchases
- cards / MCard
- social-money features
- support and dispute handling
- user / merchant / business / organisation operations

### Old backend baseline
The old backend appears to have had highly operational but unsafe “god-mode” admin patterns such as:
- direct balance adjustments
- user impersonation
- direct toggling of verification flags
- manual KYC approvals/rejections
- explicit notification pushes
- broad user-management powers
- multiple product-specific admin controllers

These patterns must **not** simply be recreated.
They must be translated into **safe, auditable, role-aware, maker-checker workflows**.

### Current back office likely strengths
The current back office appears to have:
- stronger architecture
- event sourcing
- richer domain decomposition
- Filament-based admin surfaces
- compliance, monitoring, audit and account-related primitives
- better backend integrity than the old backend

### Current back office likely gaps
The current back office appears to still lack or under-expose:
- a global transaction operations surface
- safe manual adjustment workflows
- full MTN MoMo admin tooling
- support tooling / case management
- social-money operator tooling
- utility / airtime operational tooling
- complete role-specific admin navigation
- enough operational surfaces for finance, fraud, support, and merchant ops
- focused MaphaPay-specific information architecture
- parity with practical legacy operator capabilities

You must verify all of the above before turning them into requirements.

---

## Research Requirement — Stand on the Shoulders of Giants

Before finalising the specification, use the findings and align the design with proven fintech and compliance operations patterns.

Your design must borrow applicable concepts from best-in-class operational platforms such as:
- **role-scoped admin permissions and account scoping**
- **KYC/KYB case management and investigation queues**
- **guided disputes / refund / evidence workflows**
- **reconciliation with exception management**
- **immutable ledger visibility**
- **maker-checker controls for sensitive money movement**
- **clear operational dashboards and deadline-driven queues**

Use external patterns as inspiration, but do **not** copy vendor-specific abstractions blindly.

### External benchmark patterns to incorporate conceptually
You should explicitly incorporate the following benchmark ideas into the specification:

#### 1. Role-scoped permissions and account scoping
Use the principle that admin users should only be granted permissions appropriate to their role, and only within the scopes they are entitled to manage.

Design for:
- granular permissions
- scoped account access
- least privilege
- no silent privilege escalation
- read-only auditor roles
- separation between configuration, finance ops, compliance, and support

#### 2. Case management for compliance, fraud, and support
Design a back office where alerts, KYC issues, suspicious activity, disputes, and customer investigations can be converted into structured cases with:
- queues
- assignment
- escalation
- SLA / priority
- notes
- attachments
- linked entities
- decision history
- final resolution records

#### 3. Guided dispute / refund / reversal workflows
Sensitive flows such as refunds, chargebacks, reversals, and disputes should use guided forms and lifecycle states, not freeform admin shortcuts.

#### 4. Reconciliation + exception management
The finance/reconciliation surface should not just show balances.
It should support:
- matching
- variance detection
- unresolved exceptions
- operator investigation
- export
- evidence history
- resolution logging

#### 5. Immutable ledger + real-time operational visibility
Every movement of money should be traceable through immutable event/ledger history while still being easy for operators to understand through a purpose-built UI.

#### 6. Deadline-driven operations
Any dispute, review, or unresolved exception with an expiry or SLA should surface clearly in dashboards and queues.

---

## Core Design Philosophy

You are designing for a **real digital wallet / neobank operations environment**, not a demo.

The specification must ensure the new back office can serve:
- individual users
- merchants
- businesses
- organisations
- internal operators
- compliance officers
- finance/reconciliation staff
- support staff
- merchant-ops staff
- risk/fraud analysts
- platform administrators
- auditors

The spec must ensure:
1. Nothing critical from the old backend is lost unless intentionally deprecated
2. Unsafe legacy powers are replaced by secure controls
3. The mobile app’s real support needs are covered end-to-end
4. The broad FinAegis module surface is filtered into a focused MaphaPay operating model
5. The admin UI is actually operable by real teams
6. Sensitive actions are protected by reasons, approvals, audit logs, and permissions
7. The result is implementable within the current codebase, not an imaginary rewrite

---

## What You Must Produce

Create a **single master specification document** that includes all of the following:

### 1. Executive Summary
Summarise:
- current state
- biggest risks
- biggest operational gaps
- target state
- implementation strategy

### 2. Source Validation and Evidence Method
Describe:
- which repo paths were inspected
- which files/folders/classes/resources/routes/pages were reviewed
- what was verified in code vs inferred
- where there were contradictions between audits and code

### 3. Product Reality Extracted From the Mobile App
Reconstruct the real operational needs of the back office from the mobile app, including:
- every feature surfaced to users
- every lifecycle/state that can occur
- every operator action needed to support the feature
- every entity that should be searchable by admins

You must inspect real files under:
- `src/app`
- `src/features`
- `src/core`
- `src/services`
- `src/theme` only when relevant to admin-support implications

For each app feature, include:
- relevant file paths
- hooks/services/API touchpoints
- user-visible states
- backend dependency
- admin dependency
- operational risk if unsupported

### 4. Legacy Capability Baseline
Reconstruct exactly what the old backend enabled operationally.

Inspect and cite code from:
- `/core/routes`
- `/core/app/Http/Controllers/Admin`
- `/core/app/Http/Controllers/*` where relevant
- config/policies/notification flows if applicable

Document:
- what admins could do
- what was operationally useful
- what was unsafe
- what must be preserved conceptually
- what should be intentionally retired

### 5. Current Back Office Capability Inventory
Map the current back office as it actually exists.

You must inspect at minimum:
- `app/Filament/Admin/Resources`
- `app/Filament/Admin/Pages`
- `app/Providers/*PanelProvider*`
- `routes/api.php`
- route loaders/module route registration
- `app/Domain`
- policies / permissions / middleware
- events / projectors / read-models / workflows
- jobs / actions / services
- tests related to money movement, monitoring, compliance, and admin actions

For each domain/resource/page, classify:
- verified in code
- partially exposed
- backend-only
- hidden from navigation
- likely future-useful
- currently irrelevant to MaphaPay’s core operations

### 6. Capability Matrix
Create an exhaustive matrix with columns:
- capability
- business purpose
- actor(s)
- mobile app dependency
- old backend support
- new backend/domain support
- new admin UI support
- workflow maturity
- permission maturity
- audit maturity
- operational completeness score (0–5)
- launch critical? (Y/N)
- recommendation
- implementation touchpoints (files/classes/resources)

The matrix must cover at least:

#### A. Identity & Access
- users
- admins
- merchants
- businesses
- organisations
- agents
- roles
- permissions
- session/device management
- auth resets
- biometrics/passkeys/2FA support surfaces

#### B. Onboarding & Verification
- KYC
- KYB
- document review
- re-submission
- verification states
- sanctions/watchlist review
- enhanced due diligence
- onboarding queueing
- business ownership review if relevant

#### C. Wallet & Ledger Operations
- wallets/accounts
- balances
- subaccounts
- pockets/savings
- holds
- freezes
- linked wallets
- limits
- restrictions
- ledger views
- adjustment requests
- adjustment approvals
- audit trail

#### D. Money Movement
- send money
- request money
- payment links
- QR pay
- merchant pay
- top-up
- cash-out
- bank transfers
- MTN MoMo
- cards
- utility/airtime payments
- group savings contributions/payouts
- refunds
- reversals
- retries
- cancellations
- dispute attachment
- failed/pending/stuck transaction handling

#### E. Monitoring / Risk / Fraud
- anomaly review
- suspicious transaction queues
- risk indicators
- fraud rules
- hold/release actions
- escalations
- evidence capture
- operator notes
- case ownership

#### F. Support Operations
- customer 360
- transaction lookup
- linked history
- notes
- attachments
- support cases
- complaint intake
- status updates
- safe-view / support assist patterns
- notification resend
- unblock/re-enable actions

#### G. Merchant / Business / Organisation Ops
- merchant onboarding
- merchant verification
- pricing / fee profile
- QR profile
- settlement
- payout controls
- organisation admins
- business profile review
- merchant support

#### H. Finance / Treasury / Reconciliation
- internal vs external balance checks
- provider reconciliation
- variance detection
- settlement exceptions
- export
- suspense accounts
- manual finance interventions
- fee reporting
- liquidity / float monitoring
- daily finance operations dashboard

#### I. Notifications / Messaging
- push
- SMS
- email
- in-app
- template management
- delivery logs
- retries / requeue
- broadcast vs transactional

#### J. Configuration / Platform Controls
- limits
- fees
- account tiers
- reward rules
- KYC thresholds
- merchant rules
- provider toggles
- module visibility
- environment settings
- feature flags

#### K. Reporting / Audit / Compliance Evidence
- operator action logs
- approval logs
- ledger/event history
- exports
- compliance evidence packages
- exception reports
- daily operational reports

### 7. Information Architecture and Sidebar Specification
Produce a complete admin IA / navigation redesign.

You must:
- audit the current sidebar and panel structure
- identify what is confusing
- identify what is too generic / FinAegis-centric
- identify what is missing
- define a new MaphaPay-first navigation tree

The final spec must include:
- navigation groups
- each page under each group
- intended user roles per group
- justification for every item
- whether an item is:
  - core
  - advanced
  - hidden for now
  - future module
  - launch critical
  - admin-only

You must design at least these nav groups:
- Dashboard
- Customers
- Merchants
- Businesses & Organisations
- Wallets & Ledgers
- Transactions
- Compliance
- Risk & Fraud
- Support Hub
- Finance & Reconciliation
- Notifications
- Configuration
- Reports
- Platform / Advanced / Future Modules

### 8. Target Screen Inventory
Define every required screen/page in the target back office.

For each page include:
- page name
- purpose
- target role(s)
- route / resource suggestion
- likely implementation style:
  - Filament Resource
  - custom Filament Page
  - Widget
  - Relation Manager
  - custom Livewire component
- required filters
- key fields/columns
- primary actions
- permission requirements
- related entities
- audit logging requirements
- empty state / loading / error state requirements
- future enhancement notes

Required pages must include at minimum:
- global transaction explorer
- transaction detail page
- user 360 page
- merchant detail page
- business/org detail page
- wallet/account detail page
- linked wallet detail page
- KYC/KYB review page
- anomaly / fraud case page
- support case page
- reconciliation dashboard
- provider/webhook event log page
- manual adjustment request page
- approval queue page
- notification delivery log page
- feature/configuration management pages

### 9. Entity Detail Specifications
For each major entity define a detail-page spec with tabs/sections.

At minimum specify for:
- User
- Merchant
- Business
- Organisation
- Wallet / Account
- Transaction
- Payment Intent / Request Money
- Linked Wallet
- KYC / KYB Case
- Fraud / Risk Case
- Support Case
- Reconciliation Exception
- Provider Webhook Event
- Reward Profile
- Card / MCard (if applicable)
- Group Savings / Pocket (if applicable)

For each detail page define tabs such as:
- Overview
- Timeline
- Related Transactions
- Linked Accounts
- Cases / Notes
- Documents
- Audit Log
- Actions
- Provider / External Events
- Limits / Controls
- Notifications

### 10. Workflow Specifications
Produce explicit workflows with:
- trigger
- preconditions
- states
- transitions
- permissions
- operator actions
- notifications
- audit events
- rollback / retry behaviour
- edge cases

Required workflows include:
- send money investigation
- request money lifecycle
- QR / merchant pay support flow
- top-up / cash-in failure handling
- cash-out approval/rejection flow
- MTN MoMo failure / retry / reconciliation flow
- KYC approval / rejection / resubmission
- wallet freeze / unfreeze
- safe manual ledger adjustment
- refund / reversal request
- dispute / complaint intake and handling
- anomaly triage
- support escalation
- notification resend / failure resolution
- provider webhook failure response
- reconciliation exception resolution

### 11. Role and Permission Model
Design a least-privilege model grounded in the current codebase.

At minimum define:
- Super Admin
- Platform Admin
- Operations Admin
- Customer Support L1
- Customer Support L2
- Compliance Analyst
- Compliance Manager
- Fraud Analyst
- Finance/Reconciliation Officer
- Treasury Lead
- Merchant Operations
- Business Account Manager
- Engineering Support
- Auditor / Read-Only

For each role define:
- pages accessible
- actions allowed
- export permissions
- PII visibility rules
- approval authority
- entity scope restrictions
- forbidden actions

Also specify:
- maker-checker rules
- dual control thresholds
- role conflicts that should be prohibited
- support-safe alternatives to impersonation

### 12. Risk, Audit, and Control Model
Define how the system should safely replace the old backend’s unsafe powers.

You must specify:
- which actions require reason codes
- which actions require attachments
- which require dual approval
- which require immutable audit logging
- which require notification to affected users
- which require exportability for audits
- which should never be allowed directly

Examples:
- direct balance change → never as freeform write; only controlled adjustment workflow
- impersonation → replace with support-safe shadow view / session assistance model
- verification flag toggles → only through reasoned review workflows
- refund/reversal → guided and state-aware
- freeze/unfreeze → permissioned and reasoned
- config changes → audited, role-limited, ideally maker-checker for sensitive settings

### 13. Data / Read Model / Search Specification
Recommend any additional read models, projections, materialized tables, or indexes needed to make the admin UX fast and operable.

You must identify:
- what the event-sourced model already gives
- what read models are missing for operations
- where denormalised tables are needed
- what global search needs to support
- what sort/filter combinations need indexes

Examples:
- global transaction search model
- customer 360 aggregate view
- unresolved exception queue
- pending approvals queue
- notification delivery log index
- reconciliation mismatch view
- KYC review queue projection
- provider event timeline projection

For each proposed read model specify:
- business purpose
- source aggregates/events
- projected fields
- likely file locations/classes
- rebuild / replay implications
- test implications

### 14. Implementation Plan
Create a highly practical roadmap.

Structure it as:
- Quick Wins
- Phase 1 — Launch-Critical
- Phase 2 — Operational Maturity
- Phase 3 — Expansion / Future Modules

For every item include:
- description
- why it matters
- dependency
- owner type (backend / admin UI / read model / permissions / workflow / infra / QA)
- complexity (S/M/L/XL)
- launch blocking? (Y/N)
- compliance critical? (Y/N)
- regression closure? (Y/N)
- likely file paths to modify
- new files/resources/pages likely needed
- suggested implementation sequence
- acceptance criteria

### 15. File-Path-Aware Implementation Guidance
The final document must include concrete implementation touchpoints with file paths wherever reasonably possible.

Examples of the level of specificity expected:
- where to add a new Filament resource
- where navigation is registered
- where policies are likely enforced
- where route loaders live
- where admin actions belong
- where to place read-model projectors
- where transaction queries currently live
- where tests should be added
- where a custom page vs resource makes sense

Do not stop at “create a transaction page.”
Instead specify things like:
- probable class names
- probable folder paths
- likely related resources/managers/widgets/actions
- migration suggestions
- policy suggestions
- test file locations
- event/projector touchpoints

### 16. Code Snippet / Pseudocode Guidance
Where useful, include implementation-grade pseudocode or illustrative snippets for:
- a global transaction resource
- a controlled manual adjustment workflow
- a maker-checker approval state machine
- a support-safe user assist mode
- a reconciliation exception queue
- a permissions matrix definition
- a transaction detail timeline assembler
- dashboard widgets and KPI queries

These snippets must be illustrative and aligned to the existing Laravel / Filament / event-sourcing style, not random abstractions.

### 17. Testing & Acceptance Plan
Define:
- required unit tests
- feature tests
- policy/permission tests
- workflow tests
- projection/read-model tests
- reconciliation exception tests
- audit log tests
- UI smoke tests
- launch UAT checklist

For each major feature area specify:
- what must be tested
- what regression it prevents
- what success looks like

### 18. Launch-Critical Minimum Viable Back Office
Create an explicit section naming the **minimum set of capabilities required before live rollout**.

Be decisive.
Do not dilute this section.
If the system cannot support operations without a capability, label it a blocker.

### 19. Future Module Strategy
Map the non-core FinAegis breadth into a sensible MaphaPay strategy.

For each broad or non-core module/domain classify it as:
- core now
- useful soon
- advanced later
- keep hidden for now
- not relevant
- archive from primary operator UX

Do not recommend deleting reusable capabilities unless there is a strong reason.
Prefer:
- hide
- demote
- role-gate
- place under advanced/platform/future modules

### 20. Final Verdict and Dependency Map
Conclude with:
- whether the current back office is fit for production
- what absolutely must change before launch
- what can wait
- what carries the highest operational risk
- the dependency map between key initiatives

---

## Quality Bar for the Final Document

The spec must be:
- explicit
- deeply structured
- implementation-ready
- grounded in code
- role-aware
- launch-aware
- security-aware
- compliance-aware
- readable by both humans and coding agents

Avoid:
- generic product language
- shallow recommendations
- vague “should support”
- hand-wavy architecture diagrams without implementation detail
- proposing a full rewrite unless unavoidable

Prefer:
- precise file paths
- specific page/resource names
- concrete workflows
- concrete fields, filters, and actions
- acceptance criteria
- migration strategy instead of replacement
- admin UX that maps to real operator jobs

---

## Mandatory Analytical Rules

1. **Never confuse backend existence with operator usability**
2. **Never preserve unsafe legacy admin power without redesigning it safely**
3. **Never assume a broad domain module deserves top-level UI prominence**
4. **Always ask whether an operator can actually complete the job**
5. **Optimise for supportability, compliance, finance control, and operational clarity**
6. **Treat global transaction operations and safe money adjustment workflows as first-class**
7. **Design around real MaphaPay usage, not generic banking-theatre**
8. **If something is uncertain, say so clearly**
9. **Every major conclusion should tie back to code evidence or a clearly marked inference**
10. **The final document must be something another agent can execute directly**

---

## Required Output Structure

The final spec must be structured exactly like this:

1. Executive Summary  
2. Sources Reviewed and Validation Method  
3. Product Reality Extracted from the Mobile App  
4. Legacy Operational Baseline from the Old Backend  
5. Current Back Office Capability Inventory  
6. Current Admin IA / Sidebar Audit  
7. Management Capability Matrix  
8. Key Gaps and Regressions  
9. What Must Be Preserved from Legacy  
10. What Must Be Intentionally Replaced with Safer Controls  
11. Recommended Target Operating Model  
12. Recommended Navigation / Information Architecture  
13. Required Screens and Page Specifications  
14. Entity Detail Page Specifications  
15. Workflow Specifications  
16. Role and Permission Model  
17. Audit, Risk, and Control Model  
18. Data / Read Model / Search Specification  
19. Implementation Plan and Sequencing  
20. File-Path-Aware Build Guidance  
21. Pseudocode / Snippet Appendix  
22. Testing and Acceptance Plan  
23. Launch-Critical Minimum Viable Back Office  
24. Future Module Strategy  
25. Final Verdict  
26. Dependency Map  
27. Assumptions / Unknowns / Open Questions  


---

# Context7 MCP — Documentation Research Instructions

The agent MUST use **Context7 MCP** to retrieve **up-to-date, framework-accurate documentation** for all technologies used in the MaphaPay system before finalising any implementation details.

This is critical to ensure:
- correct usage of Laravel 12
- correct usage of Filament Admin
- correct usage of Event Sourcing (Spatie)
- correct usage of GraphQL (Lighthouse)
- correct usage of queues, jobs, policies, and middleware
- correct usage of any supporting libraries detected in the codebase

---

## When to Use Context7 MCP

The agent MUST consult Context7 MCP when:

### 1. Designing or Modifying Filament Admin UI
Use Context7 to verify:
- how to define Resources
- how to build custom Pages
- how to use RelationManagers
- how to implement Actions (including bulk actions)
- how to customise navigation/sidebar
- how to structure forms and tables
- how to implement filters, tabs, and widgets
- how to integrate permissions with Filament

---

### 2. Designing Backend Logic (Laravel)

Use Context7 to verify:
- controllers and service patterns
- policies and gates
- middleware
- validation patterns
- request/response handling
- job/queue usage
- scheduling
- notifications
- event/listener patterns

---

### 3. Working with Event Sourcing (Spatie)

Use Context7 to verify:
- aggregates
- events
- projectors
- reactors
- replaying events
- rebuilding projections
- transaction integrity patterns

---

### 4. Designing Data Access / APIs

Use Context7 to verify:
- Eloquent usage
- query optimisation
- API resource patterns
- pagination and filtering
- GraphQL schema design (if Lighthouse is used)

---

### 5. Implementing Permissions & Security

Use Context7 to verify:
- policy structure
- permission packages (Spatie Permission if present)
- role assignment patterns
- guarding routes and resources
- securing admin actions

---

### 6. Implementing Queues, Jobs, and Workflows

Use Context7 to verify:
- queue configuration
- job dispatching
- retry logic
- failure handling
- batching
- event-driven workflows

---

## How to Use Context7 MCP

When using Context7:

1. Identify the relevant framework or library from the codebase  
   (e.g. Filament, Laravel, Spatie Event Sourcing)

2. Query Context7 MCP for:
   - latest best practices
   - recommended patterns
   - idiomatic usage
   - limitations or caveats

3. Extract:
   - correct class structures
   - method usage
   - lifecycle patterns
   - configuration approaches

4. Apply those patterns directly to:
   - proposed file structures
   - resource/page definitions
   - workflows
   - services and jobs

---

## Mandatory Rule

Before writing:
- any code snippets  
- any class structures  
- any file-path implementation guidance  

The agent MUST confirm:
> “This aligns with current best practices from Context7 MCP.”

If unsure:
- explicitly state uncertainty
- propose the safest pattern
- annotate with: `requires verification`

---

## What Context7 Must NOT Be Used For

Do NOT use Context7:
- as a replacement for reading the actual MaphaPay codebase
- to override working implementations already present
- to introduce unnecessary complexity
- to blindly copy patterns without adaptation

---

## Expected Outcome

After using Context7 MCP:
- all implementation guidance must be **framework-correct**
- all proposed code structures must be **realistically implementable**
- all recommendations must be **aligned with current Laravel/Filament ecosystems**

---

## Final Instruction

The agent must:
- combine **codebase reality**
- combine **audit findings**
- combine **external fintech patterns**
- combine **Context7 MCP documentation**

To produce a specification that is:
- technically correct
- operationally complete
- aligned with modern best practices
- directly implementable

---

## Final Instruction

Produce the strongest possible specification document.

The document must be rich enough that:
- a coding agent can build from it,
- a human engineer can review it,
- product/ops/compliance can validate it,
- QA can test it,
- and future agents can use it as the canonical MaphaPay back-office implementation reference.
