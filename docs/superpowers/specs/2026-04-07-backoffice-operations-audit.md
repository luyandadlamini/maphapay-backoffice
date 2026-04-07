# Backoffice Operations, Panel Separation, And Approvals Audit

Date: 2026-04-07

## Summary

This section validates the source audit’s claims about operational workbenches, panel separation, role entitlements, and approval controls against the current Filament admin implementation.

Main conclusion:

- The codebase already has substantial backoffice surface area: dedicated support, compliance, reconciliation, payout, fund-management, and operational pages/resources exist.
- It does **not** yet operate as a truly segmented operations control plane with clean workspace boundaries and consistently enforced action rights for support, compliance, finance/treasury, and platform administration.
- The correct recommendation is not “the back office is mostly missing.” The correct recommendation is “formalize the existing admin surface into role-scoped operational workbenches with explicit maker-checker and evidence rules.”

## Evidence Reviewed

Primary backend evidence:

- [`app/Providers/Filament/AdminPanelProvider.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Providers/Filament/AdminPanelProvider.php)
- [`app/Filament/Admin/Pages/PayoutApprovalQueue.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Pages/PayoutApprovalQueue.php)
- [`app/Filament/Admin/Pages/BankOperations.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Pages/BankOperations.php)
- [`app/Filament/Admin/Pages/FundManagement/TreasuryPoolPage.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Pages/FundManagement/TreasuryPoolPage.php)
- [`app/Filament/Admin/Pages/FundManagement/AdjustBalancePage.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Pages/FundManagement/AdjustBalancePage.php)
- [`app/Filament/Admin/Pages/FundManagement/TransferBetweenAccountsPage.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Pages/FundManagement/TransferBetweenAccountsPage.php)
- [`app/Filament/Admin/Pages/Settings.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Pages/Settings.php)
- [`app/Filament/Admin/Pages/Modules.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Pages/Modules.php)
- [`app/Filament/Admin/Pages/MoneyMovementInspector.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Pages/MoneyMovementInspector.php)
- [`app/Filament/Admin/Resources/SupportCaseResource.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/SupportCaseResource.php)
- [`app/Filament/Admin/Resources/KycDocumentResource.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/KycDocumentResource.php)
- [`app/Filament/Admin/Resources/AdjustmentRequestResource.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/AdjustmentRequestResource.php)
- [`app/Filament/Admin/Resources/ReconciliationReportResource.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/ReconciliationReportResource.php)
- [`app/Filament/Admin/Resources/AuditLogResource.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/AuditLogResource.php)
- [`app/Filament/Admin/Resources/FeatureFlagResource.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/FeatureFlagResource.php)
- [`app/Filament/Admin/Resources/DataSubjectRequestResource.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/DataSubjectRequestResource.php)
- [`app/Filament/Admin/Resources/AmlScreeningResource.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/AmlScreeningResource.php)
- [`app/Filament/Admin/Resources/ApiKeyResource/Pages/EditApiKey.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/ApiKeyResource/Pages/EditApiKey.php)
- [`app/Filament/Admin/Resources/AccountResource/Pages/ViewAccount.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/AccountResource/Pages/ViewAccount.php)
- [`app/Filament/Admin/Resources/AccountResource.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/AccountResource.php)
- [`app/Filament/Admin/Resources/UserResource.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/UserResource.php)
- [`app/Filament/Admin/Resources/UserResource/Pages/ViewUser.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/UserResource/Pages/ViewUser.php)
- [`app/Filament/Admin/Resources/ExchangeRateResource.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/ExchangeRateResource.php)
- [`app/Filament/Admin/Resources/WebhookResource.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/WebhookResource.php)
- [`app/Filament/Admin/Pages/SubProducts.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Pages/SubProducts.php)
- [`app/Filament/Admin/Pages/BroadcastNotificationPage.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Pages/BroadcastNotificationPage.php)
- [`app/Filament/Admin/Resources/UserInvitationResource.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/UserInvitationResource.php)
- [`app/Filament/Admin/Resources/AssetResource.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/AssetResource.php)
- [`app/Filament/Admin/Resources/AssetResource/RelationManagers/ExchangeRatesRelationManager.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/AssetResource/RelationManagers/ExchangeRatesRelationManager.php)
- [`app/Filament/Admin/Resources/AssetResource/RelationManagers/AccountBalancesRelationManager.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Resources/AssetResource/RelationManagers/AccountBalancesRelationManager.php)
- [`app/Filament/Admin/Pages/FundManagement/FundAccountPage.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Pages/FundManagement/FundAccountPage.php)
- [`app/Filament/Admin/Pages/ProjectorHealthDashboard.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Filament/Admin/Pages/ProjectorHealthDashboard.php)
- [`app/Models/TeamUserRole.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Models/TeamUserRole.php)
- [`app/Models/User.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/app/Models/User.php)

Supporting tests:

- [`tests/Feature/Filament/PayoutApprovalQueueTest.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/tests/Feature/Filament/PayoutApprovalQueueTest.php)
- [`tests/Feature/Filament/ReconciliationTriggerTest.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/tests/Feature/Filament/ReconciliationTriggerTest.php)
- [`tests/Feature/Filament/Admin/Resources/SupportCaseResourceTest.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/tests/Feature/Filament/Admin/Resources/SupportCaseResourceTest.php)
- [`tests/Feature/Filament/Admin/Resources/KycDocumentResourceTest.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/tests/Feature/Filament/Admin/Resources/KycDocumentResourceTest.php)
- [`tests/Feature/Filament/PiiMaskingTest.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/tests/Feature/Filament/PiiMaskingTest.php)
- [`tests/Feature/Filament/AnomalyTriageTest.php`](/Users/Lihle/Development/Coding/maphapay-backoffice/tests/Feature/Filament/AnomalyTriageTest.php)

External operational references:

- SDK.finance backoffice UI and manual for role-scoped workspaces, finance/CFO sections, support, investigations, and cash desk separation[^sdk-backoffice-ui][^sdk-backoffice-manual]
- SDK.finance knowledge-base docs for KYC, investigations, scam-prevention roles, and cash-desk accountant/cashier split[^sdk-kyc][^sdk-investigations][^sdk-scam-prevention][^sdk-cashdesks]
- Apache Fineract maker-checker and fine-grained access control references[^fineract-docs]

## Claim Validation

### 1. “Back-office role separation must be stricter.”

Verdict: `Confirmed`

What the code shows:

- There are role and permission checks in resources and pages.
- Support, compliance, reconciliation, payouts, and fund-management surfaces already exist.
- PII masking tests already distinguish between lower-support and higher-operations roles.

What remains missing:

- one explicitly modeled operations control plane where each workspace has tightly defined allowed actions, evidence rules, and escalation paths.

Conclusion:

The source audit is right on the need for stricter separation.

### 2. “Support, compliance, treasury, and revenue operations need different workbenches.”

Verdict: `Partial`

What the code shows:

- `SupportCaseResource` provides a support workspace.
- `KycDocumentResource` provides compliance review actions.
- `PayoutApprovalQueue`, `ReconciliationReportResource`, and fund-management pages provide finance/operations surfaces.
- navigation groups already hint at operational segmentation.

What remains missing:

- those workbenches are not yet consistently organized or isolated as domain workspaces with clear action contracts.
- some page grouping is inconsistent, for example `BankOperations` declares a navigation group that does not match the top-level panel groups exactly.

Corrected finding:

- workbenches exist in partial form,
- but they are not yet formalized into a consistent operating model.

### 3. “Maker-checker and approval controls are incomplete.”

Verdict: `Confirmed`

What the code shows:

- `AdjustmentRequestResource` already enforces a maker-checker rule that the requester cannot approve or reject their own request.
- `PayoutApprovalQueue` already creates an approval-oriented surface.
- finance actions use permission-gated access in several places.
- several live high-risk compliance and platform actions still execute as direct privileged actions:
  - `Settings::save()`, `Settings::resetToDefaults()`, and `Settings::exportSettings()`
  - `Modules::enableModule()`, `Modules::disableModule()`, and `Modules::verifyModule()`
  - `ApiKeyResource` edit, revoke, bulk revoke, and delete actions
  - `BankOperations` manual reconciliation and settlement-freeze actions
  - `DataSubjectRequestResource` fulfill deletion, fulfill export, and reject actions
  - `AuditLogResource` audit-trail export and selected-export actions
  - `FeatureFlagResource` toggle, delete, enable selected, and disable selected actions
  - `AccountResource\ViewAccount` freeze, unfreeze, request-adjustment, and replay-projector actions
  - `AccountResource` deposit, withdraw, freeze, unfreeze, and bulk freeze actions
  - `UserResource` freeze, unfreeze, bulk KYC approve/reject, and bulk delete actions
  - `UserResource\ViewUser` reset 2FA, force password reset, edit, and delete actions
  - `ExchangeRateResource` set-rate, refresh, delete, and bulk activate/deactivate/refresh actions
  - `WebhookResource` test, reset failures, activate/deactivate, and delete actions
  - `SubProducts::save()` enable/disable sub-products and features
  - `BroadcastNotificationPage` send action
  - `UserInvitationResource` invitation creation, resend, revoke, and copy-link actions
  - `AssetResource` edit/delete and bulk activate/deactivate actions
  - `AssetResource` relation-manager actions for exchange rates and account balances
  - `AmlScreeningResource` submit SAR, clear flag, and escalate actions
  - `ReconciliationReportResource` run reconciliation, download, and export CSV actions
  - `FundAccountPage::fund` action
  - `ProjectorHealthDashboard::rebuildAll` action

What remains missing:

- a single approval policy model across all high-risk actions,
- standardized evidence capture,
- and consistent treatment of direct fund-management actions versus request/approve flows.

The source audit is correct that approval controls must be generalized beyond the places where they already exist.

### 4. “Operational visibility is missing.”

Verdict: `Incorrect`

What the code shows:

- there are multiple dedicated admin pages and resources for support, reconciliation, payouts, fund operations, exceptions, and money movement inspection.
- tests confirm several of these surfaces are active and permission-sensitive.

Corrected finding:

- visibility exists,
- but it is fragmented and unevenly aligned to operator roles.

### 5. “Compliance workbench is incomplete.”

Verdict: `Partial`

What the code shows:

- KYC document review exists with bulk approve/reject actions and role-gated controls.
- anomaly/fraud-related resources and tests exist.

What remains missing:

- a more explicit investigation/case-management posture matching real-world compliance workbenches such as the role-scoped AML/fraud/KYC separation described by SDK.finance.[^sdk-investigations][^sdk-scam-prevention]

### 6. “Treasury and cash-desk style finance controls are missing.”

Verdict: `Partial`

What the code shows:

- treasury pool and fund-management pages exist,
- reconciliation and payout approval surfaces exist,
- and adjustment requests already use controlled review.

What remains missing:

- explicit cashier/accountant-style role splits for operational finance actions,
- working-day style controls for cash-operations analogs,
- and one finance/CFO workspace with consistent evidence and approval semantics, which is a pattern highlighted clearly in SDK.finance’s operational model.[^sdk-cashdesks][^sdk-backoffice-ui]

## Corrected Findings

### What already exists

- large Filament admin surface
- support case workspace
- KYC/compliance document review
- reconciliation reporting
- payout approval queue
- money-movement inspection
- treasury and fund-management pages
- some permission-gated actions
- at least one enforced maker-checker flow

### What is materially missing

- explicit workspace model for support, compliance, finance/treasury, and platform administration,
- consistent page/resource grouping aligned to those workspaces,
- one approval policy model for all high-risk admin actions,
- standard evidence requirements for approval/rejection/manual intervention,
- and consistent operator-role boundaries across the full backoffice.

## Inventory Baseline

The first implementation slice must start from a full admin inventory instead of a partial one.

Current high-level placement baseline:

| Surface family | Current examples | Current group pattern | Target workspace | First-slice status |
|---|---|---|---|---|
| Support | `SupportCaseResource`, transaction-linked support flows | `Support Hub` | Support | In scope |
| Compliance / investigation | `KycDocumentResource`, `AmlScreeningResource`, `AuditLogResource`, `DataSubjectRequestResource`, KYC actions on `UserResource` | mostly `Compliance` | Compliance | In scope |
| Finance / treasury / reconciliation | `AdjustmentRequestResource`, `PayoutApprovalQueue`, `ReconciliationReportResource`, `TreasuryPoolPage`, fund-management pages including `FundAccountPage`, `AccountResource`, `ExchangeRateResource`, `AssetResource`, and account-level freeze/unfreeze and replay surfaces | mixed `Transactions`, `Finance & Reconciliation`, `Fund Management`, `Operations` | Finance | In scope |
| Platform administration | `Settings`, `Modules`, `FeatureFlagResource`, `ApiKeyResource`, `WebhookResource`, `SubProducts`, `BroadcastNotificationPage`, `UserInvitationResource`, `ProjectorHealthDashboard`, system/platform controls | mixed `System`, `Platform` | Platform Administration | In scope |
| Customer account controls | `UserResource` freeze/unfreeze and destructive user actions | mixed `Customers`, `Compliance`, `Operations` | Explicitly assigned during phase 1; split between Compliance and Platform Administration by action type | In scope |
| Shared diagnostic views | `MoneyMovementInspector`, exceptions/projector dashboards | mixed `Banking`, `Operations`, `Platform` | Explicitly assigned during phase 1 | In scope |
| Provider operations / bank controls | `BankOperations` with manual reconciliation and settlement-freeze actions | `Operations` | Finance | In scope |
| Product / customer-domain admin surfaces | users, merchants, orders, rewards, cards, social, governance, etc. | mixed domain groups | To be assigned in the full matrix | In scope for inventory, not all for immediate hardening |

The detailed implementation must produce the complete matrix for every page/resource/action before changing permissions.

Current action classes that must be explicitly classified in that matrix:

- `Settings`: save, reset to defaults, export
- `Modules`: enable, disable, verify
- `ApiKeyResource`: edit, revoke, bulk revoke, delete
- `BankOperations`: trigger reconciliation, freeze settlement
- `DataSubjectRequestResource`: fulfill deletion, fulfill export, reject
- `AuditLogResource`: export audit trail, export selected
- `FeatureFlagResource`: toggle, delete, enable selected, disable selected
- `AccountResource`: deposit, withdraw, freeze, unfreeze, bulk freeze
- `AccountResource\ViewAccount`: freeze, unfreeze, request adjustment, replay projector
- `UserResource`: freeze, unfreeze, bulk KYC approve, bulk KYC reject, bulk delete
- `UserResource\ViewUser`: reset 2FA, force password reset, edit, delete
- `ExchangeRateResource`: set rate, refresh, delete, activate, deactivate, refresh rates
- `WebhookResource`: test, reset failures, activate, deactivate, delete
- `SubProducts`: save sub-product and feature enablement changes
- `BroadcastNotificationPage`: send broadcast notification
- `UserInvitationResource`: create invitation, resend invitation, revoke invitation, copy invitation link
- `AssetResource`: edit, delete, activate, deactivate
- `AssetResource::ExchangeRatesRelationManager`: add, edit, delete, refresh, activate, deactivate
- `AssetResource::AccountBalancesRelationManager`: add, edit, delete
- `AmlScreeningResource`: submit SAR, clear flag, escalate
- `ReconciliationReportResource`: run reconciliation, download, export CSV
- `FundAccountPage`: fund account
- `ProjectorHealthDashboard`: rebuild all projectors

### What the source audit overstates

- the backoffice is not missing,
- approval controls are not absent,
- compliance tooling is not absent,
- finance operations tooling is not absent.

The actual issue is incomplete operational segmentation and inconsistent control hardening.

## Recommendation

Reframe the current Filament admin as four primary workbenches:

- Support
- Compliance / Investigations
- Finance / Treasury / Reconciliation
- Platform Administration

Then align navigation, permissions, approval policies, and evidence capture to those workbenches instead of continuing to grow a mixed shared admin surface.

SDK.finance is a useful benchmark here because its backoffice materials make the role/workspace split explicit, including KYC, investigations, finance/CFO views, and cashier/accountant separation.[^sdk-backoffice-ui][^sdk-backoffice-manual][^sdk-cashdesks]

## Final Verdict

The right rewrite for this section is:

“MaphaPay already has a meaningful backoffice. The unresolved problem is not absence of operator tooling; it is the lack of a consistent, role-scoped operating model that turns those tools into a controlled financial operations platform.”

## Footnotes

[^sdk-backoffice-ui]: SDK.finance backoffice UI overview: <https://sdk.finance/back-office-ui/>
[^sdk-backoffice-manual]: SDK.finance backoffice manual: <https://sdk.finance/backofficemanual/>
[^sdk-kyc]: SDK.finance KYC knowledge-base article: <https://sdk.finance/knowledge-base/kyc/>
[^sdk-investigations]: SDK.finance investigations knowledge-base article: <https://sdk.finance/knowledge-base/investigations/>
[^sdk-scam-prevention]: SDK.finance scam-prevention roles article: <https://sdk.finance/knowledge-base/scam-prevention/>
[^sdk-cashdesks]: SDK.finance cashdesks article: <https://sdk.finance/knowledge-base/cashdesks/>
[^fineract-docs]: Apache Fineract documentation: <https://fineract.apache.org/docs/current/>
