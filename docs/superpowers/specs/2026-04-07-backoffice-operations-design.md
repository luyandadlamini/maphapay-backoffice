# Backoffice Operations, Panel Separation, And Approvals Design

Date: 2026-04-07

## Summary

This design formalizes the existing Filament admin into a role-scoped operations control plane.

The goal is not to create a second admin product. The goal is to:

- organize the current pages and resources into explicit operational workbenches,
- normalize permission and approval semantics,
- reduce cross-role overexposure,
- and make operator actions auditable and consistent.

## Current State

The current admin already provides:

- navigation groups for major functional areas
- support cases
- KYC document review
- payout approval queue
- reconciliation reports
- money-movement inspection
- treasury and fund-management pages
- role and permission checks in resources and tests

The current admin does **not** yet clearly define:

- one canonical workspace model,
- one approval policy model for high-risk actions,
- one consistent entitlement scheme across pages/resources,
- or one clear distinction between direct operational actions and request/approve flows.

## Target State

The target backoffice operates as four workbenches:

- Support Workspace
- Compliance Workspace
- Finance Workspace
- Platform Administration Workspace

Each workspace has:

- explicit scope,
- explicit allowed actions,
- explicit evidence requirements,
- and explicit escalation boundaries.

This direction is consistent with the operational patterns shown in SDK.finance’s backoffice references, where KYC, investigations, finance/CFO views, and cashdesk/accountant-vs-cashier roles are clearly separated.[^sdk-backoffice-ui][^sdk-backoffice-manual][^sdk-cashdesks]

## Core Decisions

### 1. Workspace model

The current single Filament panel remains the first deployment model.

First-slice decision:

- do not split into multiple Filament panels yet,
- instead, enforce workspace separation inside the current panel through navigation, resource visibility, action policy, and tests.

This avoids a big structural migration while still tightening operational boundaries.

### 1A. Full inventory requirement

Before implementation starts, every current Filament page and resource must be placed in an inventory matrix with:

- surface name
- current navigation group
- current access gate
- target workspace
- in-scope or out-of-scope status for this hardening pass
- notes on mixed-role actions

No permission or navigation refactor should start before that matrix exists.

### 1B. Current-group to target-workspace mapping

The current navigation groups collapse into target workspaces like this:

| Current group | Target workspace |
|---|---|
| `Support Hub` | Support |
| `Compliance`, `Risk & Fraud` | Compliance |
| `Finance & Reconciliation`, `Transactions`, `Wallets & Ledgers`, `Operations`, `Banking`, `Fund Management` pages | Finance |
| `Platform`, `System`, `Configuration` | Platform Administration |
| `Customers`, `Merchants & Orgs`, `Growth & Rewards`, `Notifications` | Assigned in the full matrix based on action rights; not all are first-slice hardening targets |

Outlier rule:

- pages such as `BankOperations` and `MoneyMovementInspector` must be explicitly assigned in the inventory instead of left as implicit shared utilities.

### 2. Support workspace

Scope:

- customer profile support context
- support cases
- transaction lookup and triage
- limited non-destructive operational actions

Allowed actions:

- view customer cases and linked transaction references
- assign and update support case state
- inspect money movement through dedicated inspection views

Forbidden without escalation:

- manual balance changes
- treasury transfers
- KYC approval/rejection
- payout approval

### 3. Compliance workspace

Scope:

- KYC/KYB review
- AML / fraud investigations
- document verification
- compliance case escalation

Allowed actions:

- review and approve/reject KYC documents
- open and manage investigations
- freeze/escalate when policy allows

Required improvement:

- evolve from isolated review screens to a clearer investigation workbench with explicit case progression and related-entity linking, matching the investigation patterns seen in SDK.finance’s references.[^sdk-investigations][^sdk-scam-prevention]

### 4. Finance workspace

Scope:

- payout approvals
- reconciliation
- treasury visibility
- controlled fund operations

Allowed actions:

- run reconciliation
- inspect payout queues
- review treasury state
- approve or reject high-risk financial requests

Critical rule:

- direct balance adjustment and inter-account transfers must not remain unconstrained convenience actions
- these operations should either require an approved request or be restricted to a narrowly defined elevated finance role with mandatory evidence capture

This aligns with the cashier/accountant and finance-control patterns described by SDK.finance.[^sdk-cashdesks]

### 5. Platform administration workspace

Scope:

- system configuration
- feature flags
- API keys and platform controls
- modules and marketplace/system administration

Allowed actions:

- manage technical platform settings
- manage administrative users and permissions
- manage infrastructure-facing configuration surfaces

This workspace should remain distinct from finance and compliance operations.

### 6. Approval policy model

Introduce one explicit admin approval policy for high-risk actions.

In-scope action classes:

- manual ledger-affecting adjustments
- payouts/disbursements above policy thresholds
- treasury reallocations with financial effect
- sensitive compliance overrides

Minimum approval metadata:

- requester
- reviewer
- request type
- business reason
- evidence / notes
- decision timestamp
- target object/reference

Existing `AdjustmentRequestResource` becomes the reference pattern for maker-checker, not a one-off exception.

### 6A. Approval mode matrix

Every high-risk action must be assigned one of these modes:

- `request_approve`
- `direct_elevated`
- `blocked`

First-slice action matrix:

| Action class | Current pattern | Target mode | Persistence target |
|---|---|---|---|
| Manual balance adjustment | direct action page with confirmation modal | `request_approve` by default; `direct_elevated` only for explicitly designated finance-breakglass role | approval request record with requester, reviewer, reason, evidence, target account, amount |
| Inter-account treasury/fund transfer | direct action page with confirmation modal | `request_approve` by default; `direct_elevated` only for designated finance-breakglass role | approval request record with requester, reviewer, reason, evidence, source, destination, amount |
| Adjustment request approval | existing maker-checker | `request_approve` | existing request record extended with standardized evidence fields if needed |
| Payout/disbursement approval | queue and gated widget actions | `request_approve` | payout approval record or existing payout domain record with requester/reviewer/evidence fields |
| KYC approve/reject | bulk action with role gate and email-level provenance | `direct_elevated` in first slice | KYC review record must persist reviewer user id, decision reason, reviewed_at, target document |
| Compliance override / escalation | mixed or partial | `direct_elevated` or `request_approve` per action in inventory | compliance case / review record with reviewer and evidence |
| Feature-flag/platform setting changes | direct gated action | `direct_elevated` | platform audit record with actor, reason, changed object, timestamp |
| Settings save | direct page submit | `direct_elevated` | platform settings audit record with actor, reason, changed keys, before/after snapshot hash, timestamp |
| Settings reset to defaults | direct header action with confirmation | `request_approve` by default; `direct_elevated` only for designated platform-breakglass role | approval request or platform change record with actor, reviewer, reason, affected groups, reset scope, timestamp |
| Settings export | direct header action | `direct_elevated` | platform export audit record with actor, reason, export scope, filename/reference, timestamp |
| Module enable/disable | direct page actions | `request_approve` by default; `direct_elevated` only for designated platform-breakglass role | module change request or module audit record with actor, reviewer, domain, reason, warnings, timestamp |
| Module verify | direct page action | `direct_elevated` | module verification audit record with actor, domain, result summary, timestamp |
| API key edit | direct resource edit | `direct_elevated` | API key audit record with actor, target key id, changed fields, reason, timestamp |
| API key revoke / bulk revoke | direct action and bulk action | `request_approve` for bulk revoke; `direct_elevated` for single revoke | API key lifecycle record with actor, reviewer when applicable, target key ids, reason, timestamp |
| API key delete | direct header action on edit page | `request_approve` by default; `direct_elevated` only for designated platform-breakglass role | API key lifecycle record with actor, reviewer when applicable, target key id, deletion reason, timestamp |
| Feature-flag toggle | direct row action with mandatory reason | `direct_elevated` | feature-flag audit record with actor, target flag, previous value, new value, reason, timestamp |
| Feature-flag bulk enable / disable | direct bulk action | `request_approve` by default; `direct_elevated` only for designated platform-breakglass role | feature-flag change request or audit record with actor, reviewer when applicable, target flag ids, desired state, reason, timestamp |
| Feature-flag delete | direct row delete action | `request_approve` by default; `direct_elevated` only for designated platform-breakglass role | feature-flag lifecycle record with actor, reviewer when applicable, target flag id, reason, timestamp |
| Account direct deposit / withdraw | direct resource row action | `request_approve` by default; `direct_elevated` only for designated finance-breakglass role | adjustment or account-operation record with requester, reviewer when applicable, target account, amount, reason, timestamp |
| Fund-account page funding action | direct fund-management page action | `request_approve` by default; `direct_elevated` only for designated finance-breakglass role | funding-operation or adjustment record with actor, reviewer when applicable, target account, asset, amount, reason, notes, timestamp |
| Account resource freeze / unfreeze | direct resource row action | `direct_elevated` in first slice | account-control audit record with actor, target account, action, reason when applicable, timestamp |
| Account bulk freeze | direct bulk action | `request_approve` by default; `direct_elevated` only for designated finance/compliance-breakglass role | account-control batch record with actor, reviewer when applicable, target account ids, reason, timestamp |
| Exchange-rate set / refresh / activate / deactivate | direct finance-control actions | `direct_elevated` for single-rate changes; `request_approve` for bulk state changes | exchange-rate audit or approval record with actor, reviewer when applicable, pair(s), old/new values or state, reason, timestamp |
| Exchange-rate delete | direct delete action | `request_approve` by default; `direct_elevated` only for designated finance-breakglass role | exchange-rate lifecycle record with actor, reviewer when applicable, target rate id, reason, timestamp |
| Asset edit / activate / deactivate | direct finance-control actions | `direct_elevated` | asset-admin audit record with actor, target asset(s), changed fields or state, reason when applicable, timestamp |
| Asset delete | direct delete action | `request_approve` by default; `direct_elevated` only for designated finance-breakglass role | asset-lifecycle record with actor, reviewer when applicable, target asset id(s), reason, timestamp |
| Asset exchange-rate relation-manager actions | inherits parent exchange-rate and asset finance policy | inherit from `ExchangeRateResource` and `AssetResource` by action class | same persistence targets as exchange-rate and asset control records; inheritance must be tested explicitly |
| Asset account-balance relation-manager actions | inherits parent finance-control policy | inherit from `AccountResource` / finance account-control policy by action class | same persistence targets as account-balance or account-control records; inheritance must be tested explicitly |
| Bank manual reconciliation trigger | direct page action | `direct_elevated` | reconciliation run record with actor, custodian, trigger source, reason, timestamp |
| Bank settlement freeze | direct page action with confirmation | `request_approve` by default; `direct_elevated` only for designated finance-breakglass role | settlement control record with actor, reviewer, custodian, reason, freeze state, timestamp |
| Wallet freeze / unfreeze | direct account action with confirmation | `direct_elevated` in first slice | account-control audit record with actor, target account, action, reason when applicable, timestamp |
| Wallet adjustment request | request workflow | `request_approve` | adjustment request record with requester, reviewer, reason, attachment, amount, target account |
| Wallet projector replay | direct account repair action | `request_approve` by default; `direct_elevated` only for designated finance/platform-breakglass role | projector repair record with actor, reviewer when applicable, target account, reason, replay scope, timestamp |
| User freeze / unfreeze | direct row action | `direct_elevated` in first slice | user-control audit record with actor, target user, action, reason when applicable, timestamp |
| User bulk KYC approve / reject | direct bulk action | `request_approve` by default; `direct_elevated` only for designated compliance-breakglass role | KYC decision batch record with actor, reviewer when applicable, target user ids, decision, reason, timestamp |
| User bulk delete | direct bulk action | `blocked` in first slice unless a separate governed deletion workflow exists | no direct delete path allowed without explicit governed deletion record |
| User reset 2FA / force password reset | direct user-view action | `direct_elevated` | user-security-control record with actor, target user, action, reason when applicable, timestamp |
| User edit | direct user-view action | `direct_elevated` | user-admin audit record with actor, target user, changed fields, reason, timestamp |
| User delete | direct user-view action | `request_approve` by default; `direct_elevated` only for designated platform-breakglass role | user-lifecycle record with actor, reviewer when applicable, target user, deletion reason, timestamp |
| AML submit SAR | direct compliance action with required narrative/reference | `direct_elevated` or `request_approve` per filing threshold/policy | AML case or SAR filing record with actor, screening id, description, reference, timestamp |
| AML clear flag | direct compliance decision action | `direct_elevated` | AML review record with actor, screening id, decision, reason, reviewed_at |
| AML escalate | direct compliance escalation action | `direct_elevated` | AML case/escalation record with actor, screening id, escalation target, timestamp |
| Data subject request fulfill deletion | direct compliance action with confirmation | `request_approve` by default; `direct_elevated` only for designated privacy-breakglass role | data subject request record with actor, reviewer when applicable, request id, reason, outcome, timestamp |
| Data subject request fulfill export | direct compliance action with confirmation | `direct_elevated` | data subject request record with actor, request id, export reference, outcome, timestamp |
| Data subject request reject | direct compliance action with mandatory reason | `direct_elevated` | data subject request record with actor, request id, rejection reason, timestamp |
| Audit-log export trail / export selected | direct compliance actions | `direct_elevated` | audit export record with actor, export scope, filter criteria, filename/reference, timestamp |
| Reconciliation run | direct finance action that dispatches reconciliation job | `direct_elevated` | reconciliation run record with actor, trigger source, parameters, timestamp |
| Reconciliation download / export CSV | direct finance export action | `direct_elevated` | reconciliation export record with actor, report ids, export format, timestamp |
| Webhook test / reset failures / activate / deactivate | direct platform-control actions | `direct_elevated` | webhook-control audit record with actor, target webhook(s), action, reason when applicable, timestamp |
| Webhook delete | direct action or bulk delete | `request_approve` by default; `direct_elevated` only for designated platform-breakglass role | webhook-lifecycle record with actor, reviewer when applicable, target webhook ids, reason, timestamp |
| Sub-product save / feature enable-disable | direct configuration save path | `request_approve` by default; `direct_elevated` only for designated platform-breakglass role | sub-product configuration change record with actor, reviewer when applicable, changed products/features, reason, timestamp |
| Broadcast notification send | direct platform action | `direct_elevated` | broadcast-notification record with actor, audience type, target selector, subject/body hash or reference, timestamp |
| User invitation create / resend / revoke / copy link | direct platform-admin action | `direct_elevated` for create, resend, and revoke; `direct_elevated` or `blocked` for raw-link copy depending on final policy | invitation lifecycle record with actor, invited role/email, invitation token reference, action, timestamp |
| Projector rebuild all | destructive platform repair action | `request_approve` by default; `direct_elevated` only for designated platform-breakglass role | projector-rebuild record with actor, reviewer when applicable, rebuild scope, reason, timestamp |

This matrix is mandatory. No high-risk action can remain “ad hoc.”

Classification rule:

- every live high-risk action exposed by `Settings`, `Modules`, `ApiKeyResource`, `BankOperations`, fund-management pages, compliance resources, and payout/reconciliation surfaces must appear in the inventory with workspace, mode, permission gate, evidence fields, and persistence target before the hardening pass is complete.
- every live high-risk action exposed by `Settings`, `Modules`, `ApiKeyResource`, `FeatureFlagResource`, `AccountResource`, `AccountResource\ViewAccount`, `UserResource`, `UserResource\ViewUser`, `ExchangeRateResource`, `AssetResource`, `WebhookResource`, `SubProducts`, `BroadcastNotificationPage`, `UserInvitationResource`, `AmlScreeningResource`, `BankOperations`, `DataSubjectRequestResource`, `AuditLogResource`, `ReconciliationReportResource`, `FundAccountPage`, `ProjectorHealthDashboard`, fund-management pages, compliance resources, and payout/reconciliation surfaces must appear in the inventory with workspace, mode, permission gate, evidence fields, and persistence target before the hardening pass is complete.

### 7. Entitlement model

Use the existing roles/permissions foundation, but standardize it around workspace capabilities.

Required outcome:

- every page/resource/action is mapped to one workspace,
- each workspace has a documented minimum permission set,
- tests assert both visibility and denied destructive actions for non-authorized roles.
- platform-administration and finance-control actions use the same explicit mode taxonomy instead of bespoke per-page logic.

## Public Interface And Data Model Changes

### New backend concepts

- `AdminWorkspace` enum or equivalent mapping
- `AdminApprovalPolicy` definition
- `AdminActionEvidence` metadata structure

### Existing concepts that remain

- current Filament panel
- `SupportCaseResource`
- `KycDocumentResource`
- `AdjustmentRequestResource`
- `PayoutApprovalQueue`
- `ReconciliationReportResource`
- fund-management pages
- role/permission system

### Existing concepts whose semantics change

- fund-management direct actions become governed finance actions rather than generic admin utilities
- existing resources/pages must declare workspace ownership
- approval semantics become standardized across resources instead of resource-local only

## Flow Design

### Support triage flow

1. operator enters support workspace
2. support case and customer context are reviewed
3. linked transaction / money movement references are inspected
4. if financial intervention is required, escalation or request flow is created rather than executing unsupported direct action

### Compliance review flow

1. operator enters compliance workspace
2. documents, alerts, and investigation context are reviewed
3. decision or escalation is recorded with evidence
4. downstream customer/account action occurs only within allowed policy

### Finance approval flow

1. finance operator enters finance workspace
2. pending approvals, reconciliation exceptions, or payout queues are reviewed
3. supporting evidence is captured
4. approval/rejection is executed through standardized policy
5. audit trail is preserved

## Failure Modes

The design must explicitly handle:

- support staff seeing finance-only actions
- direct balance operations bypassing request/approval flow
- compliance operators lacking linked investigation context
- payout approvals without standardized evidence
- workspace navigation group drift causing inconsistent discoverability
- role mismatch between resource visibility and action permissions

## Testing And Acceptance

The implementation is not complete unless these scenarios are covered:

- the full inventory matrix exists for every current Filament page/resource before migration work starts
- support roles can access support resources but cannot execute finance-only or compliance-only actions
- compliance roles can review KYC and investigation surfaces but cannot execute finance-only actions
- finance roles can access reconciliation and payout queues but are still blocked from unauthorized platform-admin actions
- for each high-risk action class, tests assert the configured mode (`request_approve`, `direct_elevated`, or `blocked`)
- denied-action assertions exist for out-of-scope roles on every in-scope high-risk action
- every in-scope page/resource is assigned to one workspace and appears in the correct navigation area
- high-risk actions persist requester/reviewer/evidence metadata in the chosen persistence target
- `Settings` save/reset/export, `Modules` enable/disable/verify, `ApiKeyResource` edit/revoke/bulk revoke, and `BankOperations` trigger/freeze actions each have an assigned workspace, permission gate, mode, and persistence target
- `Settings` save/reset/export, `Modules` enable/disable/verify, `ApiKeyResource` edit/revoke/bulk revoke/delete, `FeatureFlagResource` toggle/bulk/delete, `AccountResource` direct/bulk control actions, `AccountResource\ViewAccount` control actions, `UserResource` and `UserResource\ViewUser` control actions, `ExchangeRateResource` actions, `AssetResource` actions, `WebhookResource` actions, `SubProducts` save actions, `BroadcastNotificationPage` send action, `UserInvitationResource` actions, `AmlScreeningResource` actions, `BankOperations` trigger/freeze, `DataSubjectRequestResource` fulfill/reject, `AuditLogResource` export actions, `ReconciliationReportResource` actions, `FundAccountPage` funding action, and `ProjectorHealthDashboard` rebuild action each have an assigned workspace, permission gate, mode, and persistence target
- `AssetResource` relation-manager actions must either have explicit rows or inherit parent policy via an explicitly tested inheritance rule
- tests prove metadata persistence for those concrete action classes, not just generic approval flows

## Assumptions

- first slice keeps one Filament panel
- the immediate goal is operational segmentation, not a visual redesign
- existing resources/pages are reused where possible
- SDK.finance and Fineract are reference patterns, not implementation templates

## Footnotes

[^sdk-backoffice-ui]: SDK.finance backoffice UI overview: <https://sdk.finance/back-office-ui/>
[^sdk-backoffice-manual]: SDK.finance backoffice manual: <https://sdk.finance/backofficemanual/>
[^sdk-investigations]: SDK.finance investigations knowledge-base article: <https://sdk.finance/knowledge-base/investigations/>
[^sdk-scam-prevention]: SDK.finance scam-prevention roles article: <https://sdk.finance/knowledge-base/scam-prevention/>
[^sdk-cashdesks]: SDK.finance cashdesks article: <https://sdk.finance/knowledge-base/cashdesks/>
