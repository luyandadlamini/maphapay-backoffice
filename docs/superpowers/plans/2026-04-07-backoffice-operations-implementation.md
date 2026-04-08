# Backoffice Operations, Panel Separation, And Approvals Implementation Plan

Date: 2026-04-07

## Summary

Implement operational segmentation over the current Filament admin without splitting the application into multiple panels in the first slice.

The plan assumes:

- current pages/resources remain the basis,
- existing role and permission infrastructure remains in place,
- and the primary work is reorganizing, hardening, and standardizing operator actions.

## Phase 1: Workspace Inventory

- Inventory every existing Filament admin page/resource/action.
- Produce a matrix with:
  - surface name
  - current navigation group
  - current access gate
  - target workspace
  - in-scope or out-of-scope status
  - high-risk action classes present on the surface
- Assign each one to exactly one workspace:
  - Support
  - Compliance
  - Finance
  - Platform Administration
- Flag mixed-ownership surfaces that currently expose actions spanning multiple workspaces.

Done when:

- every in-scope admin surface has workspace ownership,
- mixed-role problem areas are listed explicitly,
- and the inventory includes every current admin page/resource, not just the first hardening targets.

## Phase 2: Navigation And Visibility Alignment

- Align navigation groups with the workspace model.
- Create an explicit current-group to target-workspace mapping and apply it.
- Fix inconsistent group naming such as pages assigned to groups not declared in the panel configuration.
- Ensure each workspace’s resources/pages are visible only to the intended roles.

Done when:

- navigation reflects the workspace model,
- and visibility tests prove that out-of-scope surfaces are hidden for lower-privilege roles.

## Phase 3: Approval Policy Standardization

- Define one shared policy for high-risk admin actions.
- For each high-risk action class, choose exactly one mode:
  - `request_approve`
  - `direct_elevated`
  - `blocked`
- Apply it first to:
  - balance adjustments
  - payout approvals
  - direct fund transfers between accounts
  - sensitive overrides where currently applicable
- Explicitly classify and wire:
  - `Settings` save, reset, and export
  - `Modules` enable, disable, and verify
  - `ApiKeyResource` edit, revoke, bulk revoke, and delete
  - `FeatureFlagResource` toggle, bulk enable/disable, and delete
  - `AccountResource` deposit, withdraw, freeze, unfreeze, and bulk freeze
  - `ExchangeRateResource` set-rate, refresh, delete, and bulk activate/deactivate/refresh
  - `AssetResource` edit, delete, and bulk activate/deactivate
  - `BankOperations` manual reconciliation and settlement freeze
  - `AccountResource\\ViewAccount` freeze, unfreeze, request adjustment, and replay projector
  - `UserResource` freeze, unfreeze, bulk KYC approve/reject, and bulk delete
  - `UserResource\\ViewUser` reset 2FA, force password reset, edit, and delete
  - `WebhookResource` test, reset failures, activate/deactivate, and delete
  - `SubProducts` save actions for sub-product and feature enablement
  - `BroadcastNotificationPage` send action
  - `UserInvitationResource` create, resend, revoke, and copy-link actions
  - `AssetResource` relation-manager actions for exchange rates and account balances
  - `AmlScreeningResource` submit SAR, clear flag, and escalate
  - `ReconciliationReportResource` run reconciliation, download, and export CSV
  - `FundAccountPage` fund action
  - `ProjectorHealthDashboard` rebuild-all action
  - `DataSubjectRequestResource` fulfill deletion, fulfill export, and reject
  - `AuditLogResource` export actions
- Standardize required evidence fields and requester/reviewer attribution.
- Define persistence target per action class, not just generic metadata.
- First execution slice note:
  - harden `Settings` and `BankOperations` end-to-end first,
  - keep `Modules` in the workspace-ownership/access-alignment pass for now,
  - and defer module enable/disable request wiring to the next slice because the current custom grid needs dedicated evidence-capture UI to stay within the narrow phase-1 boundary.

Done when:

- all in-scope high-risk actions either follow request/approve semantics or are explicitly blocked for non-eligible roles,
- maker-checker behavior is no longer resource-local only,
- and each action class has a named persistence target for requester/reviewer/evidence data.

## Phase 4: Finance Workspace Hardening

- Rework fund-management pages so they align with finance workspace policy.
- Rework `BankOperations` so manual reconciliation and settlement controls follow the same explicit finance policy model.
- Decide which actions stay direct and which must become approval-backed.
- Enrich reconciliation and payout surfaces with consistent evidence and escalation hooks.

Done when:

- finance actions no longer behave like generic admin utilities,
- and high-risk operations have consistent approval/evidence requirements.

## Phase 5: Compliance Workspace Hardening

- Strengthen KYC and investigation surfaces into a clearer compliance workspace.
- Add or standardize case context, linked entities/transactions, and escalation flow where needed.
- Ensure permissions align with compliance roles and not broad admin defaults.

Done when:

- compliance actions are grouped coherently,
- and role-based access matches the intended compliance operating model.

## Phase 6: Support Workspace Hardening

- Keep support focused on triage, customer context, and safe non-destructive actions.
- Route finance/compliance interventions through escalation or request flows.
- Ensure support-facing resources expose enough diagnostic context without overexposing sensitive actions or PII.

Done when:

- support roles can resolve common issues and triage effectively,
- without receiving finance/compliance control rights they should not have.

## Test Plan

- Feature tests mapping each workspace role to expected page/resource visibility
- Action-level tests proving denied access for out-of-scope roles on every in-scope high-risk action class
- Tests for maker-checker consistency across all `request_approve` action classes
- Tests for `direct_elevated` gating on every allowed direct high-risk action
- Tests for persisted requester/reviewer/evidence metadata per action class and persistence target
- Navigation tests confirming current-group to workspace mapping
- Explicit tests for `Settings` save/reset/export, `Modules` enable/disable/verify, `ApiKeyResource` edit/revoke/bulk revoke/delete, `FeatureFlagResource` toggle/bulk/delete, `AccountResource` direct and bulk control actions, `ExchangeRateResource` actions, `AssetResource` actions, `BankOperations` trigger/freeze, `AccountResource\\ViewAccount` control actions, `UserResource` and `UserResource\\ViewUser` control actions, `WebhookResource` actions, `SubProducts` save actions, `BroadcastNotificationPage` send action, `UserInvitationResource` actions, `AmlScreeningResource` actions, `ReconciliationReportResource` actions, `FundAccountPage` fund action, `ProjectorHealthDashboard` rebuild-all action, `DataSubjectRequestResource` fulfill/reject, and `AuditLogResource` export action classes
- Explicit inheritance tests proving `AssetResource` relation-manager actions enforce the same workspace/mode/persistence policy as their parent asset, exchange-rate, and account-control action classes
- Regression tests for existing support, payout, reconciliation, and KYC flows

## Assumptions

- one Filament panel remains in place for this slice
- workspace segregation is enforced by visibility, action policies, and tests
- existing resources/pages are evolved rather than replaced wholesale
