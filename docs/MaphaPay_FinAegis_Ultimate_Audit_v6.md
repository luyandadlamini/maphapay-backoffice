
# MaphaPay + FinAegis Unified Master Audit

**Project:** MaphaPay mobile app + FinAegis core banking backend  
**Prepared for:** Lihle Dlamini  
**Date:** 7 April 2026  
**Format:** Consolidated, enhanced, gap-focused audit  
**Source inputs consolidated:**
- Enterprise Back-Office Audit & Architectural Blueprint v1.0
- Enterprise Back-Office & Aggregator Blueprint v2.0
- Enterprise Back-Office & Aggregator Blueprint v3.0
- Enterprise Master Blueprint v4.0
- Uploaded `README.md` (MaphaPay React Native app)
- Uploaded `README 2.md` (FinAegis backend)
- External research on official product, security, and regulatory sources

---

## 1. Document purpose and method

This document merges the four prior audits into a **single master audit**, preserves their recommendations, improves the structure, adds implementation-grade detail, and highlights additional gaps discovered from:

1. the actual MaphaPay mobile README,
2. the actual FinAegis backend README, and
3. external research against official sources and current standards.

The goal is not only to restate architecture aspirations, but to turn them into a **scrutinised operating model** for a production-grade payments platform in Eswatini and the wider Southern African ecosystem.

### 1.1 What this audit does
- Preserves the intent and content of all prior audits.
- Reorganises them into an actionable engineering, operations, and compliance blueprint.
- Tests the current stack against core-banking, aggregator, B2B2C, mobile-security, and back-office best practice.
- Flags gaps that were previously under-emphasised or omitted.
- Produces a prioritised roadmap.

### 1.2 What this audit does not assume
This audit does **not** assume that every named third-party provider exposes a stable public developer API. Public evidence confirms product availability for MTN MoMo, e-Mali, SBS ePocket and broader banking API capability from some regional institutions, but public developer documentation is uneven. For that reason, the correct strategy is **provider adapter + commercial integration track + switch-ready orchestration**, not hard-coded assumptions about public API maturity.[^mtn-openapi][^emali][^sbs-epocket][^fnb-integration][^stdbank-dev][^nedbank-api]

---



## 1A. What makes this the ultimate audit

This version consolidates the strongest elements of all three uploaded documents into a single decision document:
- the **broadest gap analysis and implementation detail** from the Unified Master Audit,
- the **succinct engineering posture** from the Enterprise Master Audit v5.0, and
- the **strongest operating-model and control-plane design** from the Enterprise Master Blueprint.

It therefore reframes the platform simultaneously as:
- a **financial switch and orchestration engine**,
- a **ledger-first core banking platform**,
- a **B2B2C embedded-finance operating model**,
- and a **segmented operations, treasury, compliance, and risk control system**.

### 1A.1 Ultimate architectural stance
The architectural centre of gravity should shift from **“wallet app with integrations”** to **“ledger-controlled financial switch with retail, business, and operations channels on top.”**

### 1A.2 Ultimate non-negotiables
1. No wallet balance without a ledger basis.
2. No high-risk action without explicit approval policy.
3. No provider callback without inbox dedupe and replay controls.
4. No settlement confidence without reconciliation evidence.
5. No mobile trust assumption without device/app integrity controls.
6. No business scaling without context isolation, partner controls, and contract-aware pricing.

## 1B. Executive engineering snapshot

### 1B.1 Core components
- **FinAegis** — Laravel / DDD / Event Sourcing / CQRS / workflows / GraphQL / Filament
- **MaphaPay** — React Native / Expo / Expo Router / TanStack Query / Zustand / Axios / SecureStore
- **Redis** — caching, orchestration locks, idempotency support, stream/event plumbing
- **Soketi** — WebSocket delivery for push-first state updates
- **PostgreSQL** — primary transactional persistence

### 1B.2 Canonical service boundaries
1. Wallet Domain — user-facing balances as derived views
2. Ledger Domain — source-of-truth accounting layer
3. Payment Orchestrator — flow control, routing, policy, and state handling
4. Provider Adapters — MTN, e-Mali, SBS, bank rails, card rails, billers
5. Compliance Domain — KYC/KYB/AML/sanctions/case workflows
6. Corporate Domain — business wallets, payroll, approval chains, spend controls
7. Reconciliation Domain — statement ingestion, matching, exception handling, close processes
8. Operations Domain — support, treasury, CRO/commercial, and control-room tooling

### 1B.3 Canonical transaction state machine
**INITIATED → PROCESSING → PENDING_EXTERNAL / PENDING_INTERNAL → PENDING_SETTLEMENT → SETTLED / FAILED / REVERSED**

This state machine must remain distinct from the posting lifecycle in the ledger.

## 1C. Southern Africa integration reality snapshot

### 1C.1 Practical posture
The platform should assume a mixed connectivity landscape:
- some providers expose structured APIs,
- some require H2H or commercial onboarding,
- some remain batch-heavy,
- and settlement timing may differ materially from customer-facing status.

### 1C.2 Working assumptions to preserve
| Rail / institution | Likely integration posture | Design implication |
|---|---|---|
| MTN MoMo | API-led where enabled | Treat as canonical first adapter, not special-case architecture |
| FNB | H2H and/or limited API exposure depending on product | Build batch-aware orchestration and settlement windows |
| Standard Bank | API-capable with enterprise onboarding | Use provider-neutral OAuth/credential abstractions |
| Nedbank | Marketplace/API-capable with onboarding | Keep beneficiary validation and payment-initiation abstracted |
| e-Mali / SBS / other local wallets | product availability clearer than public developer maturity | use commercial-integration track + switch-ready adapters |

### 1C.3 Design consequence
Because provider maturity is uneven, the correct pattern is:
**adapter registry + capability matrix + routing engine + reconciliation discipline**, not rail-specific controller logic.


## 2. Current-state snapshot from the supplied READMEs

### 2.1 Mobile app snapshot: MaphaPay React Native
The uploaded mobile README shows a modern Expo/React Native stack built on **React Native 0.83 + Expo SDK 55**, Expo Router, TanStack Query v5, Zustand v5, Reanimated 4, Axios, Expo SecureStore, and a custom design system. The app already includes dashboard, wallet, savings, QR, social, notifications, wallet linking, MTN MoMo transfer flows, and dynamic verification support. It also already treats terminal success correctly by requiring `status === 'success'` and a finalised `reference`, which is a strong integrity improvement.[^mapha-readme]

**What is already good:**
- Feature-first frontend organisation.
- Clear split between server state and client state.
- Secure token storage via Expo SecureStore.
- Thin route files and modular features.
- Dynamic verification awareness.
- QR deep-linking design.
- MTN transfer status handling already hardened conceptually.

**What is materially incomplete from the README itself:**
- Cards are still mostly mock.
- Rewards are mostly mock.
- Utility services are mock.
- Some workflows still rely on polling.
- The README does not describe certificate pinning, device attestation, jailbreak/root handling, key rotation, offline policy, or secure release controls.

### 2.2 Backend snapshot: FinAegis
The uploaded backend README describes a very powerful Laravel-based platform using **DDD, CQRS, Event Sourcing, GraphQL, Redis Streams, Soketi, Filament v3, Waterline workflows, Horizon, multi-tenancy, fraud/compliance domains, card issuance domain, privacy tooling, OpenTelemetry tracing, and 49 modular domains**.[^finaegis-readme]

**What is already good:**
- Strong modularity and domain separation.
- Event-sourced core with CQRS.
- Real-time infrastructure already present.
- Compliance/fraud primitives already exist.
- Admin tooling and module management already exist.
- Distributed tracing is already in the platform.
- Card issuance and treasury domains already exist.

**What remains strategically unresolved despite the strong foundation:**
- Whether wallet balances are fully derived from an immutable double-entry GL as ultimate truth.
- Whether operational panels are split into true business silos with strong entitlements.
- Whether payment orchestration for Eswatini/Southern Africa is modelled as a first-class switch.
- Whether reconciliation is automated end to end at settlement-account level.
- Whether B2B2C workspace, payroll, fleet cards, corporate spend controls, and maker-checker are complete across all critical flows.

---

## 3. Strategic conclusion

### 3.1 Bottom-line assessment
MaphaPay + FinAegis is **not starting from zero**. It already has infrastructure that many fintechs only plan to build later. The problem is not lack of architectural sophistication. The problem is **operational completion, accounting absolutism, regulated-payments discipline, and productisation of the platform into a coherent embedded-finance engine**.

### 3.2 Core thesis
To become a defensible platform, MaphaPay must evolve from:
- a strong wallet app with backend sophistication,

into:
- a **settlement-aware financial switch**,
- a **strict double-entry core ledger**,
- a **multi-context personal/business operating system**,
- a **corporate disbursement and spend-management platform**, and
- a **fully instrumented operations/compliance machine**.

### 3.3 Primary architectural principle
The **General Ledger must become the ultimate source of truth**, with wallets, cards, external-provider balances, and dashboards all becoming derived views of ledger-backed events rather than semi-independent balance stores. Apache Fineract’s emphasis on accounting discipline and product configurability makes this the correct benchmark.[^fineract-docs]

---

## 4. Consolidated strengths already present

This section preserves and enhances the strengths identified across the prior audits.

### 4.1 Mobile strengths
- Modern RN/Expo foundation.
- Strong route organisation.
- Good state-management posture: TanStack Query for server state, Zustand for local/session state.
- Dynamic verification logic already acknowledged.
- Secure token storage approach present.
- QR/deep-link scheme already designed.
- Wallet-linking storage strategy already exists.

### 4.2 Backend strengths
- 49 domain modules and modular plugin architecture.
- Event Sourcing + CQRS.
- Waterline workflows for approval/state-machine enforcement.
- Soketi/WebSocket support.
- Redis Streams and Horizon.
- GraphQL schemas and subscriptions.
- Fraud, Compliance, Wallet, Treasury, Banking, Card Issuance, Privacy, and Monitoring domains.
- OpenTelemetry tracing and event streaming.
- Filament v3 admin capability.

### 4.3 Business-model strengths
- The architecture can support retail, merchant, corporate, and API partner use cases.
- The platform is well-positioned for B2B2C operations.
- There is enough modularity to support regional expansion without rewriting the entire core.

---

## 5. Master list of critical findings

### 5.1 Severity 1 — Must fix before scale or serious production exposure
1. **Ledger absolutism is not yet proven.** The stack is advanced, but the documentation does not prove that every money movement resolves into an immutable, balancing, double-entry journal whose derived projections are the sole source of balances.
2. **Provider orchestration is conceptually defined but not fully hardened.** The platform still reads like an MTN-first system with planned adapters, not yet a fully neutral financial switch.
3. **Settlement and reconciliation architecture must become first-class.** A payment platform fails operationally when the internal state and provider statements diverge.
4. **Back-office role separation must be stricter.** Support, compliance, treasury, and revenue operations need different entitlements, data scopes, and action rights.
5. **Corporate/B2B2C is not yet coherently modelled.** Context switching, company treasury, employee cards, payroll, expense evidence, approval chains, and contract-specific pricing need a single domain model.
6. **Mobile hardening is under-specified.** Device attestation, app integrity, tamper detection, session-binding, release discipline, and high-risk flow step-up controls need explicit implementation.
7. **Deep-link and QR trust boundaries are under-specified.** Payment-link and QR flows are high-risk for spoofing, redirect abuse, and untrusted payload injection.
8. **Idempotency and replay strategy must be universal.** Every financial mutation and inbound webhook needs deterministic dedupe behaviour.
9. **Data classification and cardholder-data boundaries are not clearly described.** This becomes urgent the moment real card issuance moves beyond mock.
10. **Operational metrics and SLOs are not yet tied to money-state guarantees.** Tracing exists, but treasury-grade control loops are not clearly defined.

### 5.2 Severity 2 — High-priority gaps
1. Missing explicit **outbox/inbox event delivery pattern** for provider callbacks and internal projections.
2. Missing explicit **ledger posting rules engine** separate from business orchestration.
3. Missing **business-day close / settlement-day close** routines.
4. Missing **chargeback/dispute lifecycle** and card-dispute evidence workflow.
5. Missing **customer-risk segmentation** tied to limits, verification friction, and ongoing monitoring.
6. Missing **contract-aware pricing and fee versioning** with effective dates and rollback.
7. Missing **developer/API product controls** such as API keys, scopes, rate tiers, webhook secrets, partner environments, and commercial entitlements.
8. Missing **offline/poor-network behavioural guarantees** on mobile for duplicate submits, stale balances, partial syncs, and conflict handling.
9. Missing **secret rotation and cryptographic key lifecycle narrative**.
10. Missing **release governance** for mobile OTA updates in a regulated money application.

### 5.3 Severity 3 — Strategic enhancements
1. EPS/open-banking readiness for Eswatini switch participation.
2. Regional abstraction for bank/mobile-money differences.
3. Treasury forecasting and prefunding engine.
4. Embedded partner APIs for collections/disbursements.
5. Fraud-scoring feedback loops.
6. Financial-data warehouse and executive BI layer.

---

## 6. Target operating model

### 6.1 The correct mental model for MaphaPay
MaphaPay should be operated as **five tightly integrated systems**:

1. **Customer experience layer** — mobile/web/API channels.
2. **Business orchestration layer** — payment flows, routing, approvals, product logic.
3. **Core ledger layer** — double-entry journal and chart of accounts.
4. **Provider connectivity layer** — mobile money, bank APIs, card issuer, utility billers, switch rails.
5. **Operational control layer** — support, compliance, treasury, reconciliation, revenue ops, observability.

### 6.2 Why this matters
Many fintech products mix orchestration and accounting into the same workflow code. That works until there are reversals, timeouts, provider mismatches, partial settlements, FX spreads, or card disputes. The ledger must not be a side effect. It must be the source of financial truth.

---

## 7. Core banking and ledger audit

This section consolidates and expands all prior accounting recommendations.

### 7.1 Required end-state
You need a dedicated **Accounting domain** that is independent from the wallet domain and guarantees:
- a full chart of accounts,
- transaction templates/posting rules,
- immutable journal entries,
- balancing enforcement,
- reversals rather than destructive edits,
- period close controls,
- settlement-account tracking,
- suspense/clearing accounts,
- fee and tax postings,
- partner payable/receivable accounts,
- reconciliation states,
- auditability of every posting origin.

### 7.2 Non-negotiable ledger rules
1. **No balance mutation without journal impact.**
2. **Every journal batch must balance exactly.**
3. **No deletes; corrections happen via reversal + repost.**
4. **Wallet balances are liabilities.**
5. **Settlement accounts are assets.**
6. **Platform fees are income.**
7. **Chargebacks, losses, and dispute reserves need explicit accounts.**
8. **Pending states must post into clearing/suspense, not final customer balances, until finality is achieved.**
9. **External provider status changes cannot bypass the ledger.**
10. **Derived projections must be rebuildable from the event store and journal history.**

### 7.3 Ledger gaps not stressed enough in the earlier audits
#### 7.3.1 Posting engine vs workflow engine
The prior audits correctly call for double-entry accounting, but the architecture also needs a clear split between:
- **workflow/orchestration state**, and
- **financial posting state**.

Example: a transfer workflow can move through `initiated -> provider_pending -> provider_succeeded -> settled`, but the ledger should post distinct states such as:
- customer funds reserved,
- provider clearing asset recognised,
- fee accrued,
- settlement confirmed,
- exception transferred to suspense.

#### 7.3.2 Effective-dated posting rules
Do not hard-code posting behaviour into controllers/services. Posting templates should be versioned and effective-dated so that fee, tax, interchange, or routing changes do not require dangerous code edits.

#### 7.3.3 Reversal taxonomy
You need separate reversal types for:
- customer cancellation,
- provider decline,
- provider timeout later resolved to failure,
- partial success,
- duplicate callback,
- operator-initiated refund,
- card chargeback,
- compliance freeze/unfreeze.

#### 7.3.4 Business-day close
The ledger needs a controlled daily close with:
- provider statement import status,
- unresolved suspense count,
- unreconciled amount totals,
- aged exceptions,
- required approvals before close.

### 7.4 Recommended chart-of-accounts skeleton
At minimum:
- **10000 Assets**
  - 10100 MTN settlement
  - 10200 e-Mali settlement
  - 10300 FNB settlement
  - 10400 Standard Bank settlement
  - 10500 Nedbank settlement
  - 10600 SBS settlement
  - 10700 Card issuer prefund/JIT account
  - 10800 Suspense assets
- **20000 Liabilities**
  - 20100 Customer wallet liabilities
  - 20200 Corporate treasury liabilities
  - 20300 Pending payout liabilities
  - 20400 Merchant settlement liabilities
  - 20500 Partner payable liabilities
- **30000 Equity**
- **40000 Income**
  - transfer fees
  - payout fees
  - interchange share
  - FX spread
  - SaaS/API fees
- **50000 Expenses**
  - provider fees
  - card network fees
  - fraud losses
  - chargeback losses
  - refunds/compensation

### 7.5 Example preserved from prior audits, but refined
#### Inter-wallet transfer: e-Mali to SBS ePocket, E500, fee E5
**Economic interpretation:**
1. You collect value from e-Mali into your settlement side.
2. You owe the user via a clearing liability.
3. You fulfil outward to SBS less fee.
4. You recognise fee revenue.

A production implementation should split this across states rather than one simplistic final batch. Earlier audits were directionally correct, but in practice you should model:
- reserve/authorise,
- provider accepted,
- provider completed,
- settlement confirmed,
- exception handling if outbound fails after inbound succeeds.

### 7.6 Verdict on accounting maturity
**Current likely state:** strong architectural ingredients, but not enough evidence yet of a fully productised ledger discipline.  
**Required state:** Fineract-grade accounting integrity with operational controls.[^fineract-docs]

---

## 8. Payment orchestration and universal switch audit

### 8.1 The right architecture
The earlier audits correctly call for `PaymentProviderInterface`. That should now be expanded into a real **Payment Orchestration Layer** with these subcomponents:
- provider registry,
- capability matrix,
- routing engine,
- failover policy,
- fee estimator,
- finality normaliser,
- callback verifier,
- provider statement importer,
- provider health monitor,
- settlement calculator.

### 8.2 Capability matrix per rail
Every adapter should declare capabilities such as:
- collect,
- disburse,
- transfer,
- cash-out,
- cash-in,
- account verification,
- balance enquiry,
- webhook support,
- async finality,
- batch payout,
- reversals,
- statement export.

This avoids hard-coding flows per provider.

### 8.3 Public-doc reality check
The prior audits assume several adapters. That is strategically correct, but integration readiness varies.

**Publicly evidenced:**
- MTN MoMo developer portal exposes Open API products including collection, disbursement, and remittance.[^mtn-openapi][^mtn-products]
- Standard Bank has a public developer portal/API marketplace.[^stdbank-dev][^stdbank-market]
- Nedbank has a public API marketplace including payments and wallet-related APIs.[^nedbank-api][^nedbank-wallet]
- FNB publicly markets integration/API access for business channels.[^fnb-integration]
- e-Mali and SBS ePocket are clearly live products, but public developer documentation was not clearly surfaced in this audit, so commercial integration may rely on partner channels, H2H arrangements, USSD-backed services, switch integration, or private specs rather than open public docs.[^emali][^sbs-epocket]

**Architectural implication:**
Do not bind your roadmap to “public API exists” assumptions. Build adapter contracts so each provider can be implemented via:
- REST API,
- host-to-host file exchange,
- statement import,
- switch integration,
- manual/assisted ops fallback.

### 8.4 Routing engine requirements
Routing must consider:
- rail availability,
- provider health,
- fees,
- transaction size,
- beneficiary network,
- merchant/corporate contract,
- finality speed,
- settlement exposure,
- FX implications,
- compliance constraints,
- dispute risk.

### 8.5 Under-noticed gap: provider-finality normalisation
Every provider expresses states differently. You need a normalisation layer that converts provider-specific states into internal states such as:
- `accepted`,
- `awaiting_customer_action`,
- `pending_provider`,
- `posted_pending_settlement`,
- `settled`,
- `failed_retryable`,
- `failed_terminal`,
- `reversed`,
- `manual_review`.

### 8.6 Under-noticed gap: inbound/outbound asymmetry
A transfer between two providers is not one transaction. It is at least two linked legs:
- inbound collection,
- internal clearing,
- outbound disbursement.

Your orchestration must tolerate one leg succeeding while the other fails and immediately route the case into exception or compensation logic.

---

## 9. Eswatini ecosystem and regulatory-readiness audit

### 9.1 Strategic relevance
The Central Bank of Eswatini is actively overseeing national payment systems and has a National Payment System Act. Public material also points to ongoing national payment modernisation, including the Eswatini Payments Switch and fast payments initiatives.[^cbe-nps][^cbe-act][^cbe-vision][^afi-fastpayments][^bis-eps]

### 9.2 What this means for MaphaPay
MaphaPay should not be designed only as a proprietary aggregator. It should be **EPS-ready**:
- switch-aware payment routing,
- open-banking-ready consent and access models,
- standardised account/beneficiary abstractions,
- settlement and scheme-level reconciliation hooks,
- regulator-facing reporting exports.

### 9.3 Compliance architecture implications
You need explicit support for:
- PSP/operator licensing assumptions,
- customer safeguarding / trust-account mechanics,
- transaction monitoring,
- suspicious activity reporting,
- agent controls if cash operations exist,
- record retention,
- consent and access logs,
- KYC/KYB level-based controls.

### 9.4 Gap that was not explicit enough before
The earlier audits discuss AML and maker-checker, which is correct, but a real production rollout also needs **regulator-reporting posture**:
- report templates,
- evidence retention schedules,
- case management auditability,
- privileged-access monitoring,
- complaints escalation,
- scheme breach handling.

---

## 10. Corporate/B2B2C architecture audit

This is one of the strongest themes in the prior audits and remains strategically correct.

### 10.1 Required core entities
The business model should be formalised with these core entities:
- `Workspace` / `Company`
- `CompanyMembership`
- `Role`
- `ApprovalPolicy`
- `Department/CostCenter`
- `CorporateWallet`
- `EmployeeSpendAccount`
- `CardProgramme`
- `Card`
- `ExpenseEvidence`
- `PayrollBatch`
- `PayoutBatch`
- `VendorContract`
- `ApiClient`
- `WebhookEndpoint`

### 10.2 Context switching
The prior audits are right that a user should authenticate once and switch context. Expand that into these rules:
- all requests carry explicit context headers or context-bound tokens,
- caches are context-partitioned,
- notifications are context-tagged,
- cards and balances are never commingled across personal and business context,
- risky actions require context-specific re-authentication.

### 10.3 RBAC is not enough by itself
In corporate finance, simple role labels are insufficient. You also need:
- amount limits,
- per-feature permissions,
- per-cost-centre visibility,
- geography restrictions,
- MCC restrictions,
- maker-checker thresholds,
- time-window policies,
- second-factor requirements.

### 10.4 Corporate master account model
The earlier audits correctly refer to a master corporate treasury. Enhance this into:
- treasury wallet,
- departmental sub-ledgers,
- spend controls,
- payroll liabilities,
- card prefunding/JIT links,
- settlement controls,
- multi-user approval workflow,
- delegated administration logs.

### 10.5 Payroll and mass payout gaps that need addition
Add the following that were not fully stressed before:
- beneficiary validation before release,
- dry-run payroll simulation,
- duplicate-recipient detection,
- cut-off times,
- partial-batch recovery,
- payroll return handling,
- proof-of-payment generation,
- bulk-upload validation and maker-checker before execution,
- batch-level and line-level exception handling,
- post-run reconciliation.

---

## 11. Card issuing and spend-management audit

### 11.1 Strategic direction is correct
The prior audits are right: virtual cards tied to corporate funding are a major differentiator. FinAegis already signals card-issuance capability in its broader platform description.[^finaegis-readme]

### 11.2 Production-grade card programme requirements
Beyond “issue a virtual card”, you need:
- card programme configuration,
- PAN/CVV handling boundaries,
- tokenisation posture,
- JIT funding or prefunded models,
- real-time authorisation decisioning,
- MCC/merchant/geo controls,
- recurring-payment rules,
- velocity controls,
- lost/stolen/freeze/unfreeze lifecycle,
- cardholder verification rules,
- card-event webhooks,
- statement exports,
- dispute/chargeback case handling,
- interchange allocation logic.

### 11.3 PCI boundary warning
The moment you move from mock cards to real issuance, your architecture must sharply separate:
- systems that can access cardholder data,
- systems that can only access tokens or masked PANs,
- staff roles that can see sensitive fields,
- logs that must never contain PAN/CVV.

PCI DSS remains the baseline standard for protecting payment account data; v4.0.1 is the current limited revision that clarified wording without adding new requirements.[^pci-dss][^pci-v401]

### 11.4 Expense evidence workflow
The earlier audits correctly call for receipt upload after spend. Expand it into:
- auto-prompt on matching MCCs,
- receipt OCR/classification later if desired,
- VAT/tax evidence fields,
- missing-receipt chasers,
- manager approval for non-compliant claims,
- auto-booking into expense categories,
- link to ERP/accounting exports.

---

## 12. Back-office and operational controls audit

### 12.1 Panel separation remains correct
The earlier audits correctly propose isolated panels such as:
- `/support`
- `/compliance`
- `/cfo`
- `/cro`

This is strongly aligned with SDK.finance’s emphasis on role-specific back-office responsibilities.[^sdk-team-roles]

### 12.2 Minimum operational workbenches
#### Support Hub
- 360° customer view
- KYC/KYB status
- linked wallets/connections
- communication history
- device/session history
- recent actions and flags
- permitted low-risk remediation actions

#### Compliance Hub
- investigations queue
- SAR/STR case management
- document requests
- freeze/quarantine workflow
- evidence upload
- case notes
- approval chain
- sanctions/PEP/watchlist integration points

#### CFO/Treasury Hub
- settlement balances
- prefund positions
- provider exposure
- reconciliation exceptions
- aged suspense items
- daily close controls
- cash desk and agent float if relevant

#### CRO/Commercial Hub
- pricing products
- contract-specific fee schedules
- partner management
- commission rules
- interchange-sharing rules
- enterprise account configurations

### 12.3 Missing operations layer that should be added
Add **DevSecOps / Reliability panel capabilities**:
- webhook replay with audit trail,
- queue/stream lag visibility,
- stuck transaction monitor,
- provider outage switchboard,
- degraded-mode toggles,
- safe retry tools,
- idempotency inspection,
- trace lookup by request ID/reference.

### 12.4 Operational control principle
No support or admin action should directly alter money state without a governed workflow. Staff actions should create requests, approvals, or reversible commands—not silent database mutations.

---

## 13. Reconciliation and treasury audit

### 13.1 This must become a first-class product area
Reconciliation is not an admin report. It is one of the core products of a payment platform.

### 13.2 Required reconciliation layers
1. **Internal reconciliation** — ledger vs projections.
2. **Provider reconciliation** — internal ledger vs provider statements/APIs.
3. **Treasury reconciliation** — settlement bank balances vs expected positions.
4. **Corporate reconciliation** — issued payouts/cards vs supporting evidence/exports.

### 13.3 Matching engine requirements
The earlier audits correctly call for automated matching. Extend it with:
- exact match,
- fuzzy match,
- amount/date/reference similarity,
- split/merge match handling,
- duplicate statement line detection,
- ageing buckets,
- auto-writeoff policy thresholds,
- escalation workflow.

### 13.4 Under-noticed prefunding problem
If you add multiple rails and card issuance, you also need a **treasury prefunding engine** that predicts when:
- MTN prefund is low,
- bank settlement float is low,
- card JIT account is underfunded,
- payroll batches will breach available liquidity.

### 13.5 Required reports
- daily settlement report,
- aged unreconciled report,
- suspense movement report,
- partner payable/receivable report,
- fee revenue by rail,
- failed payout by cause,
- provider downtime impact report.

---

## 14. Fraud, AML, and investigations audit

### 14.1 Prior direction is correct
The prior audits correctly recommend:
- suspicious activity detection,
- quarantine queues,
- maker-checker,
- investigation workbench.

### 14.2 Gaps to add
#### 14.2.1 Risk scoring fabric
You need a reusable risk engine that ingests:
- device reputation,
- behavioural velocity,
- beneficiary novelty,
- geo anomalies,
- linked-wallet churn,
- high-risk merchant categories,
- corporate admin changes,
- failed authentication patterns,
- provider callback irregularities.

#### 14.2.2 Tiered actions
Risk responses should be configurable:
- allow,
- allow + log,
- step-up authentication,
- soft hold,
- hard hold,
- manual review,
- block + case creation.

#### 14.2.3 Corporate abuse scenarios
The earlier audits focus heavily on retail abuse patterns. Add these corporate-specific scenarios:
- payroll fraud,
- synthetic employee accounts,
- card abuse by employees,
- invoice substitution,
- privilege escalation by delegated admins,
- high-value batch exfiltration.

#### 14.2.4 Case evidence model
Investigations should preserve:
- all relevant journal IDs,
- callback payloads,
- operator actions,
- device/app integrity results,
- communications,
- uploaded evidence,
- approval path.

---

## 15. Identity, KYC, KYB, and trust architecture audit

### 15.1 Good prior direction, but needs expansion
The prior audits correctly mention KYC, KYB, SumSub, ZK-KYC, and proof-based compliance.

### 15.2 Practical recommendation
Use a layered trust model:
- **Identity evidence** — documents, liveness, address, tax or business docs.
- **Verification outcome** — verified/unverified/expired/review-required.
- **Risk tier** — limits and friction rules derived from verification state and behaviour.
- **Business relationship** — retail, merchant, corporate, API partner, agent.

### 15.3 ZK-KYC caution
Privacy-preserving verification is strategically attractive, and FinAegis already signals privacy tooling, but do not let “ZK-KYC” become an excuse to avoid evidence governance. In production you still need:
- source-of-truth evidence references,
- expiry rules,
- retrigger policies,
- consent records,
- regulator-request handling,
- human-review capability.

### 15.4 KYB requirements
For business onboarding, capture at least:
- legal entity profile,
- registration docs,
- tax docs,
- UBOs,
- directors/signatories,
- bank/wallet settlement details,
- expected transaction profile,
- contract tier,
- approval history.

---

## 16. Mobile app architecture audit

### 16.1 What the current mobile stack gets right
The app architecture described in the README is sensible and modern. The server/client-state separation is especially good.

### 16.2 Mobile gaps requiring attention
#### 16.2.1 Polling should be replaced where finality is asynchronous
The prior audits correctly recommend moving transaction status to Soketi/WebSocket flows. FinAegis already supports Soketi and GraphQL subscriptions on the backend, so the enabling infrastructure exists.[^finaegis-readme]

#### 16.2.2 Secure storage limitations must be acknowledged explicitly
Expo SecureStore is a valid choice for secrets, but Expo’s own documentation says it should not be relied on as the single source of truth for irreplaceable critical data; Android data does not survive uninstall, while iOS keychain persistence across reinstall is not guaranteed and should not be treated as a contractual platform guarantee.[^expo-securestore]

**Implication:**
- Never make the device copy of tokens, linked wallets, or policy state authoritative.
- Treat local data as cache or secure session material only.
- Build resilient refresh/recovery flows.

#### 16.2.3 Device integrity needs to move from “nice to have” to “required”
Expo now provides `@expo/app-integrity`, which uses Apple App Attest on iOS and Play Integrity on Android.[^expo-appintegrity]

Apple’s App Attest exists specifically so your server can gain more confidence that requests come from legitimate instances of your app.[^apple-appattest][^apple-validate] Google’s Play Integrity API exists to help verify that requests come from your genuine app on a genuine and certified Android device.[^play-integrity][^play-verdicts]

**This should be integrated for high-risk actions** such as:
- login from new device,
- wallet linking,
- send money,
- request acceptance,
- card provisioning,
- payout approval,
- admin-sensitive actions.

#### 16.2.4 Deep-link and QR security
Because `maphapay://` routes can initiate payment or request flows, you need:
- strict route allowlists,
- payload signatures or backend-resolved opaque tokens,
- expiry windows,
- anti-replay tokens,
- user-visible payee confirmation,
- domain verification for HTTPS fallbacks.

#### 16.2.5 Offline behaviour is under-described
You need explicit mobile rules for:
- submit-once semantics,
- stale balance warnings,
- queued non-financial sync vs blocked financial actions,
- retry UX,
- transaction timeline hydration after reconnect.

#### 16.2.6 Session-bound context and cache partitioning
Because the app will support personal and business modes, TanStack Query keys, local stores, linked-wallet cache entries, notification lists, and receipt-upload queues should all be namespaced by active context.

### 16.3 Dynamic security policy is the right pattern
The README already points in the right direction: the backend should remain the authority, and the app should use locally cached policy only as an optimisation. Preserve this design.

---

## 17. Mobile security audit against current standards

### 17.1 Standard to align against
OWASP MASVS remains the key mobile security verification baseline and covers storage, cryptography, authentication/authorisation, network communication, platform interaction, code quality, and resilience against tampering.[^masvs][^masvs-frontispiece]

### 17.2 Control areas MaphaPay should explicitly satisfy
- secure local storage,
- certificate and transport protections,
- strong authentication and step-up,
- secure biometric usage,
- anti-tamper and app integrity,
- secure deep linking,
- log redaction,
- secure update/release process,
- least-privilege permissions,
- runtime risk handling on compromised devices.

### 17.3 Gaps not obvious from earlier audits
1. **Certificate pinning / public key pinning** is not described.
2. **Jailbreak/root/emulator handling** is not described.
3. **Screenshot/screen-recording policy** for sensitive screens is not described.
4. **Sensitive analytics/event-redaction policy** is not described.
5. **OTA update governance** for regulated flows is not described.
6. **Biometric key invalidation edge cases** need explicit recovery UX.

---

## 18. API, webhook, and idempotency audit

### 18.1 Universal idempotency is required
The prior audits correctly call for `X-Idempotency-Key`. Expand this into a platform-wide contract:
- every financial mutation requires an idempotency key,
- keys are namespaced by actor + route + semantic operation,
- request body hash must be stored,
- duplicate with changed payload returns deterministic error,
- dedupe window defined per operation type,
- operator/admin tools use idempotency too.

### 18.2 Webhook handling requirements
Every inbound provider webhook must go through:
1. signature/authentication verification,
2. source allowlist validation,
3. dedupe store,
4. immutable raw payload storage,
5. normalisation,
6. business state update command,
7. ledger impact if appropriate,
8. downstream event publication.

### 18.3 Missing inbox/outbox pattern
This audit strongly recommends adding explicit:
- **Inbox** for idempotent processing of inbound webhooks/provider events.
- **Outbox** for guaranteed delivery of internal domain events and partner notifications.

This prevents state changes from being lost when the database commit succeeds but downstream publication fails.

### 18.4 External API product posture
For B2B/API partners, you need:
- scoped credentials,
- per-partner rate limits,
- sandbox environments,
- webhook signing secrets,
- contract-aware limits,
- callback retry rules,
- replay controls,
- partner-facing event logs,
- developer portal and support model.

---

## 19. Data architecture and eventing audit

### 19.1 Event sourcing is an advantage only if projections are governed
FinAegis already has event sourcing and Redis Streams. That is excellent. But projections must be explicitly tiered:
- **financial projections** — highest integrity, rebuildable, audit-protected.
- **operational projections** — support/compliance dashboards.
- **UX projections** — mobile dashboards, counts, summaries.
- **analytics projections** — BI warehouse.

### 19.2 Gap: financial vs non-financial events
Do not let all events be treated equally. Some events change money truth; others only describe UI/operational state. You need event classes or metadata that identify:
- financial-critical,
- compliance-critical,
- customer-visible,
- analytics-only.

### 19.3 Gap: projection lag SLOs
Given Redis Streams and subscriptions, you should define explicit SLOs:
- ledger write latency,
- projection freshness,
- websocket event delivery latency,
- webhook processing latency,
- reconciliation completion window.

---

## 20. Observability, reliability, and incident-response audit

### 20.1 Strong foundations exist
FinAegis already signals OpenTelemetry, Zipkin, Jaeger, per-request tracing, Redis Streams metrics, and real-time dashboards.[^finaegis-readme]

### 20.2 What must be added
You need money-grade SRE controls:
- SLOs tied to financial actions,
- provider health scorecards,
- stuck-payment detectors,
- reconciliation breach alerts,
- aged suspense alerts,
- callback failure-rate alerts,
- queue depth and lag thresholds,
- traceability from mobile request to provider call to ledger entry.

### 20.3 Incident classes to formalise
- provider outage,
- callback outage,
- duplicate debit suspicion,
- partial batch execution,
- ledger/projection mismatch,
- card auth outage,
- mobile release regression,
- integrity-attestation outage.

### 20.4 Control-room principle
For a financial platform, incident response must be part of product design. Operators need visible runbooks and safe controls, not ad hoc shell access.

---

## 21. Product configuration and pricing audit

### 21.1 Dynamic product factory remains the right direction
The earlier audits correctly propose a CRO-facing product/pricing builder. This is essential.

### 21.2 Extend it with these additional controls
- effective dates,
- test/simulate mode,
- approval before publish,
- rollback to prior version,
- customer/partner segment targeting,
- currency/country applicability,
- tax/VAT treatment,
- floor/ceiling fees,
- bundled corporate pricing,
- maker-checker for commercial changes.

### 21.3 Fee engine rules should support
- flat fees,
- percentage fees,
- tiered bands,
- caps/floors,
- partner rebates,
- interchange sharing,
- promotional waivers,
- corporate contract overrides,
- route-dependent fees.

---

## 22. Merchant, QR, and acceptance audit

### 22.1 Merchant support remains under-developed in the current README
The mobile README shows QR support and merchant pay flows, but merchant operations need deeper modelling.

### 22.2 Merchant operating requirements
- merchant profile and risk tier,
- store and till hierarchy,
- cashier identities,
- dynamic and static QR management,
- settlement schedule configuration,
- dispute handling,
- refund rules,
- statement exports,
- merchant API/webhook capability,
- fraud monitoring.

### 22.3 QR-specific security controls
- merchant QR signing or server lookup,
- amount-binding for dynamic QR,
- expiry windows,
- anti-tamper payload rules,
- merchant status check before pay,
- settlement destination validation.

---

## 23. Agent and cash-desk audit

### 23.1 Prior recommendation remains sound
If agent operations are planned, the cash desk must be treated as a controlled balance state machine, not a loose operational process.

### 23.2 Expand with missing controls
- opening float approval,
- supervisor override controls,
- denomination capture,
- surprise cash counts,
- variance escalation,
- agent commission calculation,
- agent risk scoring,
- geofencing or branch association,
- end-of-day lockout if unreconciled.

---

## 24. Release engineering and environment audit

### 24.1 Mobile release governance gap
Because the app uses Expo, you need a formal policy for:
- EAS environments,
- build provenance,
- OTA update eligibility,
- feature flags for regulated flows,
- kill switches,
- staged rollout,
- rollback,
- mandatory version enforcement.

### 24.2 Backend environment posture gap
You need stricter environment separation for:
- sandbox vs live providers,
- masked test data,
- reconciliation dry runs,
- seed data,
- partner onboarding sandboxes,
- secrets management,
- queue and stream isolation.

### 24.3 Test strategy expansion
The backend README already shows targeted verification tests. Good. Expand to include:
- ledger invariant tests,
- provider adapter contract tests,
- callback replay tests,
- batch payout partial-failure tests,
- projection rebuild tests,
- mobile deep-link abuse tests,
- app integrity fallback tests,
- reconciliation fixture tests.

---

## 25. The biggest previously unnoticed gaps

These are the most important additions from this master audit beyond the earlier blueprints.

### 25.1 A real outbox/inbox event-delivery pattern
Without it, the platform remains vulnerable to “state committed but event not published” or “webhook delivered twice” problems.

### 25.2 Business-day close and settlement-day close
Without formal close processes, reconciliation becomes advisory instead of controlling.

### 25.3 Treasury prefunding and liquidity forecasting
As you add rails and cards, cash/liquidity operations become strategic.

### 25.4 Deep-link/QR trust boundary design
This is a real fraud surface and must be designed, not assumed.

### 25.5 Mobile release governance for a regulated product
A fintech app cannot treat OTA updates like a casual consumer app.

### 25.6 Explicit API productisation for B2B partners
A B2B2C platform needs developer lifecycle management, not just endpoints.

### 25.7 Distinction between workflow state and money state
This is one of the most important architectural disciplines for financial correctness.

### 25.8 Context-partitioned caches and permissions in mobile
Personal/business context contamination is a subtle but serious UX and compliance risk.

### 25.9 Chargeback/dispute architecture
Especially important once cards and merchant acceptance are real.

### 25.10 Regulator/reporting operating posture
You need operational evidence production, not just compliance logic.

---

## 26. Consolidated target architecture

### 26.1 Recommended macro-architecture
1. **Channel Layer**
   - Mobile app
   - Web/admin
   - Partner API
2. **Identity & Trust Layer**
   - Auth
   - Device/app integrity
   - KYC/KYB
   - Risk policy
3. **Financial Orchestration Layer**
   - Transfer workflows
   - Collections/disbursements
   - Payroll/batches
   - Card authorisation controls
4. **Core Ledger Layer**
   - Chart of accounts
   - Journal posting engine
   - Period close
   - Reconciliation state
5. **Connectivity Layer**
   - Provider adapters
   - Switch integrations
   - Card issuer
   - Utility billers
6. **Operations Layer**
   - Support
   - Compliance
   - Treasury
   - Revenue/CRO
   - Reliability/DevSecOps
7. **Data/Insight Layer**
   - Projections
   - BI warehouse
   - Audit evidence
   - Monitoring and alerts

### 26.2 Recommended backend bounded contexts
- Identity
- Trust / Device Integrity
- User / Workspace
- Wallet
- Accounting
- Payment Orchestration
- Provider Connectivity
- Corporate Finance
- Card Programme
- Merchant Acceptance
- Compliance
- Fraud
- Treasury & Reconciliation
- Pricing & Contracts
- Notifications / Communications
- Operations / Case Management
- Reporting / BI

---

## 27. Action plan by time horizon

### 27.1 Immediate 0-30 day priorities
1. Freeze the **ledger target design** and posting rules.
2. Define **money-state vs workflow-state** architecture.
3. Introduce **universal idempotency contract** and webhook inbox design.
4. Design **personal/business context model** end to end.
5. Harden **deep-link and QR trust model**.
6. Stand up the first true operational panels with least-privilege scopes.
7. Define SLOs for payment finality, webhook latency, and reconciliation lag.

### 27.2 30-60 day priorities
1. Implement ledger domain and migration strategy for balance derivation.
2. Build provider orchestration registry/capability matrix.
3. Move transfer updates from polling to Soketi/subscription-driven state.
4. Implement reconciliation ingestion and matching engine v1.
5. Build KYB + corporate workspace + approval policy core model.
6. Integrate app/device integrity for high-risk mobile actions.[^expo-appintegrity][^apple-appattest][^play-integrity]

### 27.3 60-120 day priorities
1. Add first corporate treasury and employee spend flows.
2. Add payroll/payout batches with dry-run and maker-checker.
3. Add contract-aware pricing builder.
4. Add treasury prefunding dashboards and daily close process.
5. Add partner API product controls.
6. Add chargeback/dispute case model.

### 27.4 120+ day priorities
1. Card programme production hardening.
2. EPS/open-banking readiness.
3. Regional adapter expansion.
4. Full BI warehouse and executive controls.
5. Advanced fraud scoring and feedback loops.

---

## 28. Implementation priority matrix

| Area | Priority | Why |
|---|---|---|
| Ledger as source of truth | Critical | Prevents silent money-state divergence |
| Idempotency + webhook inbox/outbox | Critical | Prevents duplicate or lost financial actions |
| Reconciliation engine | Critical | Essential for safe operations at scale |
| Personal/business context isolation | Critical | Required for B2B2C correctness |
| Back-office role isolation | Critical | Required for governance and risk control |
| Device/app integrity | High | Required for higher-risk mobile actions |
| Deep-link / QR hardening | High | Real fraud surface |
| Treasury prefunding | High | Necessary once multi-rail/card flows expand |
| Dynamic pricing/contracts | High | Enables business autonomy without unsafe code edits |
| Card programme controls | High | Required before real card scale |
| Partner API lifecycle | Medium-High | Required for B2B/B2B2C growth |
| BI / warehouse layer | Medium | Important for scaling management control |

---

## 29. Final verdict

### 29.1 Overall assessment
The combined MaphaPay + FinAegis stack is **architecturally ambitious and unusually capable** for its stage. The opportunity is real. The missing piece is not more features in isolation. The missing piece is **discipline**:
- accounting discipline,
- operational discipline,
- trust/security discipline,
- reconciliation discipline,
- context and permissions discipline.

### 29.2 What success looks like
If you execute this correctly, MaphaPay becomes:
- a personal wallet,
- a business treasury app,
- a payroll and disbursement rail,
- a corporate spend-control tool,
- a merchant acceptance platform,
- a multi-provider financial aggregator,
- and eventually a regulated embedded-finance operating system for Southern Africa.

### 29.3 Most important single recommendation
If there is only one architectural rule to protect above all others, it is this:

> **No movement of value should ever be considered final unless it is represented by a balanced, immutable ledger event and a controlled settlement/reconciliation state.**

That rule should shape the rest of the platform.

---

## 30. Preserved recommendations from the earlier audits (lossless consolidation)

This section preserves the substantive recommendations from v1.0-v4.0 in consolidated form so that no original directional content is lost.

### 30.1 From v1.0 — Core banking, operations, security, and interoperability
- Implement a standalone Accounting domain.
- Treat user wallets as liabilities and reserve accounts as assets.
- Reject unbalanced transactions.
- Enforce maker-checker using Waterline.
- Build a Product Factory in Filament for dynamic financial rules.
- Separate Filament panels for support, compliance, CFO, and CRO.
- Add cash-desk workflows for agents.
- Surface immutable event-sourced session and action logs.
- Make idempotency global across money mutations.
- Prefer proof-based/ZK verification where appropriate.
- Build provider adapters rather than remaining tightly coupled to MTN.
- Build a webhook management hub.
- Move mobile transaction status from polling toward real-time updates.
- Fetch and cache backend-defined security policy on app boot.

### 30.2 From v2.0 — Aggregation and 360° operational management
- Build PaymentProviderInterface for multiple providers.
- Add polling/caching for net-worth aggregation.
- Support inter-wallet routing across rails.
- Build 360° views for Individuals, Merchants, and Corporates.
- Show linked-account health and token status in support tools.
- Add cashier management, settlement settings, and QR/till controls for merchants.
- Add corporate/API partner views for webhooks and dynamic fee contracts.
- Automate daily reconciliation and disputes queues.
- Provide webhook replay.
- Bind requests to device identity and use idempotency keys.

### 30.3 From v3.0 — Corporate ecosystem and spend management
- Add personal/business context switching in one app.
- Introduce company/workspace hierarchy.
- Invite employees into corporate workspaces.
- Define RBAC for admin, manager, employee.
- Add virtual card issuance tied to corporate master wallet.
- Add receipt capture for employee spend.
- Add bulk disbursement engine and smart routing.
- Add batch maker-checker for high-value corporate payouts.
- Build KYB queue and corporate contract configuration.
- Support custom enterprise fee structures and rebates.

### 30.4 From v4.0 — Master blueprint and universal finance engine
- Position MaphaPay as a universal dashboard for Eswatini financial assets.
- Use Redis caching and background polling/jobs for aggregated balances.
- Allow inter-wallet pull/push routing through MaphaPay GL.
- Introduce corporate sub-accounts and role-based workspace controls.
- Add virtual cards with MCC/category restrictions and wallet-linked funding.
- Add bulk payout routing by destination network.
- Raise accounting to Fineract-grade standard.
- Build support/compliance/CFO/CRO operational silos.
- Add 360° client views, KYB queue, cash desk, reconciliation engine, and vendor contract tooling.
- Add AML sandbox, zero-trust API, device fingerprinting, idempotency, ZK-KYC, webhook replay.
- Replace frontend polling with WebSocket state sync.
- Implement a staged roadmap from ledger to adapters to B2B to operations to security.

---

## 31. Source notes

### 31.1 Internal sources used
- Uploaded mobile README (`README.md`)
- Uploaded backend README (`README 2.md`)
- The four blueprint/audit texts provided in the prompt

### 31.2 External research note
External facts in this document were added only where they strengthened the audit, especially for:
- Fineract benchmark positioning,
- SDK.finance back-office role separation,
- OWASP MASVS mobile-security baseline,
- PCI DSS payment-data baseline,
- Expo SecureStore limitations,
- Expo/Apple/Google app-integrity controls,
- MTN MoMo developer platform,
- Standard Bank, FNB, and Nedbank integration posture,
- Eswatini payment-system/regulatory direction.

---

## Footnotes

[^mapha-readme]: Derived from the uploaded `README.md` for MaphaPay in this conversation.
[^finaegis-readme]: Derived from the uploaded `README 2.md` for FinAegis in this conversation.
[^fineract-docs]: Apache Fineract documentation: https://fineract.apache.org/docs/current/
[^sdk-team-roles]: SDK.finance back-office roles: https://sdk.finance/team-roles/
[^masvs]: OWASP MASVS overview: https://mas.owasp.org/MASVS/
[^masvs-frontispiece]: OWASP MASVS frontispiece/scope: https://mas.owasp.org/MASVS/02-Frontispiece/
[^pci-dss]: PCI DSS overview: https://www.pcisecuritystandards.org/standards/pci-dss/
[^pci-v401]: PCI SSC note on PCI DSS v4.0.1: https://blog.pcisecuritystandards.org/just-published-pci-dss-v4-0-1
[^expo-securestore]: Expo SecureStore documentation: https://docs.expo.dev/versions/latest/sdk/securestore/
[^expo-appintegrity]: Expo App Integrity documentation: https://docs.expo.dev/versions/latest/sdk/app-integrity/
[^apple-appattest]: Apple DeviceCheck / App Attest overview: https://developer.apple.com/documentation/devicecheck
[^apple-validate]: Apple guidance on validating apps that connect to your server: https://developer.apple.com/documentation/devicecheck/validating-apps-that-connect-to-your-server
[^play-integrity]: Google Play Integrity API overview: https://developer.android.com/google/play/integrity/overview
[^play-verdicts]: Google Play Integrity verdicts: https://developer.android.com/google/play/integrity/verdicts
[^mtn-openapi]: MTN MoMo developer portal: https://momodeveloper.mtn.com/
[^mtn-products]: MTN MoMo products / Open API product information: https://momodeveloper.mtn.com/products and https://momodeveloper.mtn.com/api-documentation
[^fnb-integration]: FNB Integration Channel: https://www.fnb.co.za/integration-channel/index.html
[^stdbank-dev]: Standard Bank developer portal: https://developer.standardbank.co.za/
[^stdbank-market]: Standard Bank API marketplace: https://developer.standardbank.com/APIMarketplace/s/
[^nedbank-api]: Nedbank API marketplace: https://apim.nedbank.co.za/static/products and https://apim.nedbank.co.za/static/payment
[^nedbank-wallet]: Nedbank Wallet API: https://apim.nedbank.africa/find-the-right-api/wallet-api.html
[^emali]: Eswatini Mobile e-Mali public product page: https://eswatinimobile.co.sz/about-e-mali/
[^sbs-epocket]: SBS ePocket public product page: https://www.sbs.co.sz/epocket/
[^cbe-vision]: Central Bank of Eswatini National Payment System Vision 2025: https://www.centralbank.org.sz/wp-content/uploads/2021/04/Eswatini-National-Payment-System-Vision-2025.pdf
[^cbe-nps]: Central Bank of Eswatini National Payment Systems page: https://www.centralbank.org.sz/national-payment-systems/
[^cbe-act]: National Payment System Act, 2023: https://www.centralbank.org.sz/wp-content/uploads/2021/04/NATIONAL-PAYMENT-SYSTEM-ACT-2023.pdf
[^afi-fastpayments]: AFI note on Eswatini fast payments / EPS: https://www.afi-global.org/news/eswatini-citizens-discover-the-benefits-of-the-countrys-new-fast-payments-system/
[^bis-eps]: BIS-hosted speech referencing fast/instant payments and open banking in Eswatini: https://www.bis.org/review/r250114f.htm


## 32. Engineering consolidation appendix from v5.0

This appendix preserves the most useful compact engineering signals from the shorter engineering-edition audit so they remain visible in the final master document.

### 32.1 Multi-ledger framing
The platform should explicitly distinguish:
- **Operational Ledger** — real-time financial postings and balances,
- **Settlement Ledger** — provider settlement positions, prefunding, and clearing,
- **Reporting Ledger / warehouse projections** — management, BI, and regulatory reporting views.

These can coexist in one controlled architecture, but the operational posting engine must remain authoritative.

### 32.2 Routing score heuristic
A routing decision may be scored using weighted factors such as:
- cost,
- latency,
- success rate,
- settlement confidence,
- available prefund/float,
- compliance constraints,
- and customer or contract preference.

### 32.3 Failure modes that must remain first-class
- partial transfer failure,
- duplicate webhook,
- provider downtime,
- stale linked-wallet balance,
- callback success without settlement confirmation,
- settlement confirmation without customer-notification success,
- reversal after apparent customer finality,
- payroll batch partial completion,
- chargeback/dispute after downstream spend,
- and replay or duplicate-submission from weak-network mobile conditions.

### 32.4 Compact risk register
| Risk | Why it matters | Required control |
|---|---|---|
| Bank/API access uncertainty | blocks or delays product rollout | provider-neutral switch + commercial integration track |
| Regulatory approval delays | can stall launch or expansion | compliance operating model + regulator-ready reporting |
| Reconciliation mismatch | creates silent financial exposure | daily close + exception workflows + suspense controls |
| Weak mobile trust posture | exposes high-risk actions | device attestation, session binding, step-up controls |
| Poor role isolation | enables internal-control failures | segmented panels + maker-checker + immutable logs |
| Contract/pricing rigidity | slows B2B2C scale | effective-dated product and fee configuration |

### 32.5 Production-readiness gate
The platform should not be treated as scale-ready until at least the following are true:
- GL and posting templates are fully implemented,
- wallet balances are derived from ledger truth,
- reconciliation engine is live,
- AML/compliance case workflows are active,
- idempotency is universal,
- webhook inbox/outbox patterns are enforced,
- business/personal context isolation is in production,
- treasury close controls are operating daily,
- and operational panels are split by real duties, not only generic RBAC.
