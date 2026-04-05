# MaphaPay Back Office: Master Specification

> [!WARNING]
> This is a comprehensive, production-grade technical and operational specification covering requirements, UI mapping, entity models, workflows, and implementation roadmaps for the MaphaPay Back Office upgrade to Filament v3.x/v4.x/v5.x on Laravel 12.

## 1. Executive Summary
The MaphaPay back office is migrating from a legacy "God-mode" monolithic backend to a modern, decoupled, Event Sourced FinAegis architecture on Laravel 12. While the backend data integrity is robust, the current operational UI (Filament Admin) exposes only generic CRUD wrappers rather than targeted, workflow-driven surfaces. Real-world operator needs (manual exception handling, resolving failed payouts, tracing global money movement) are unsupported. This specification outlines a phased implementation strategy to provide safe maker-checker controls, guided case management, and complete operational visibility without sacrificing the security of the underlying ledger.

## 2. Sources Reviewed and Validation Method
- **Mobile App**: Inspected `maphapayrn/src` implicitly through prior pass data—verified capabilities for Biometrics, Send/Request Money, QR Pay, Linked Wallets, Rewards, Group Savings, and MCard.
- **Old Backend**: Forensic extract of `/core/app/Http/Controllers/Admin` mapping raw, un-audited powers like `addSubBalance` and `Auth::loginUsingId()`.
- **Current Back Office**: Verified `app/Filament/Admin/Resources`, Laravel routes, and Spatie Event Sourcing (`app/Domain` modules like `AuthorizedTransaction`, `Account`, `Compliance`) against `pass1` and `pass2` audits. Validated Filament Navigation Group capabilities.
- **Context7 MCP**: Queried Laravel 12, Filament, and Spatie Event Sourcing best practices to inform structural recommendations.

## 3. Product Reality Extracted from the Mobile App
The mobile app relies heavily on backend support that demands rich operational interfaces:
- **Auth & Onboarding**: Re-trigger 2FA, biometric reset, block/unblock flow. (Touches `UserResource`).
- **Wallets & Ledgers**: Investigate failed/frozen accounts, adjust pocket savings. (Touches `AccountResource`, `PocketResource`).
- **P2P & Transactions**: Support teams must trace isolated `SendMoney` failures or `PaymentIntent` links without hunting per-user.
- **QR Pay & Merchant Pay**: Reverse a fraudulent/disputed QR scan.
- **MTN MoMo & Linked Wallets**: Investigate MoMo sync failures and retry MobilePayment aggregators.
- **Utility/Airtime & Mobile Recharges**: Refund or retry failed commerce/API calls.
- **Rewards & Social Money**: Moderate chat/notes, verify reward quest claims.

## 4. Legacy Operational Baseline from the Old Backend
The legacy `/core/` application provided high productivity but extremely low safety:
- **What Admins Could Do**: `addSubBalance` (direct SQL update, no logs), user emulation (`Auth::loginUsingId()`), manual KYC manipulation, explicit push notification blasts.
- **What We Keep Conceptually**: The ability to adjust balances (refunds/appeals) and support users actively.
- **What We Replace**: Direct SQL mutations must be replaced by **Maker-Checker Adjustment Workflows** that dispatch Event Sourcing commands. User emulation must be replaced by a "Support Safe-View".

## 5. Current Back Office Capability Inventory
- **Strengths (Backend)**: Spatie Event Souring enforces immutable ledgers. Strong domains (`AuthorizedTransaction`, `Account`, `Compliance`, `Fraud`).
- **Weaknesses (UI)**: Operates like a developer's DB viewer. Relies completely on nested `RelationManagers` (e.g., global transactions are hidden inside `AccountResource`). Missing top-level capabilities for `SocialMoney`, `MtnMomo`, `Banners`.

## 6. Current Admin IA / Sidebar Audit
The current groups (`Banking`, `System`) are poorly organized for scaled fintech operations. The UX is developer-centric and clutters compliance, finance, and support tasks.

## 7. Management Capability Matrix

| Capability | Actor | Mobile App Need | Backend Domain | Admin UI | Operational Completeness | Launch Critical |
|:---|:---|:---|:---|:---|:---|:---|
| **Global Tx Search** | Support/Fin | High | `AuthorizedTransaction`| None | 1/5 | Y |
| **Manual Adjustments** | Finance | High | `Account` | None | 0/5 | Y |
| **KYC Approvals** | Compliance | High | `Compliance` | `KycDocResource`| 4/5 | Y |
| **Fraud Review** | Risk | High | `Fraud`/`Anomaly` | `AnomalyResource`| 3/5 | Y |
| **Utility/MoMo Support**| Ops | High | `Commerce`/`Mobile` | None | 0/5 | Y |
| **Case Management** | Support | High | N/A | None | 0/5 | Y |

## 8. Key Gaps and Regressions
- **Missing Global Transaction Resource**: Operators cannot search a Tx Hash directly.
- **Missing Payout/TopUp Exception Queues**: If MTN MoMo Webhooks fail, there is no UI queue dedicated to pending unresolved exceptions.
- **Missing Guided Adjustments**: No safe equivalent to legacy `addSubBalance` with reasons/dual-control approvals.
- **Missing Support Desk**: No consolidated "Customer 360" or Case Management logic tying Notes + Transactions + Compliance flags together.

## 9. What Must Be Preserved from Legacy
- Fast, single-click access to a user's recent movement history.
- Granular capability to push transactional or broadcast system notifications.
- Ability to override stuck/frozen profiles after off-platform identity verification.

## 10. What Must Be Intentionally Replaced with Safer Controls
- **Impersonation**: Replace with "Support Shield View" (Read-only representation of user's mobile screen states).
- **Direct Balance Updates**: Replace with `DispatchManualAdjustmentCommand`, enforcing a pending state until a secondary `FinanceAdmin` approves the action.

## 11. Recommended Target Operating Model
An exception-led, deeply structured Filament V5.x architecture utilizing Custom Pages, robust Actions, and explicit Workflows over default generic Resources. Operations must become predictable, structured, and aggressively separated by Role (Compliance vs. Finance vs. L1 Support).

## 12. Recommended Navigation / Information Architecture
- **Dashboard**: Live aggregates, Projector Lag status (`ProjectorHealthController`).
- **Customers**: Users, Profiles, Limits.
- **Merchants & Orgs**: Merchants, Financial Institutions.
- **Wallets & Ledgers**: Accounts, Pockets, Sub-wallets.
- **Transactions**: Global Ledger (All AuthorizedTx), Failed Transfers log.
- **Compliance**: KYC Reviews, Watchlists.
- **Risk & Fraud**: Suspicious Activity, Anomaly Detections.
- **Support Hub**: Cases, Disputes, Feedback.
- **Finance & Reconciliation**: Asset Baskets, Exception Rules, Approvals Queue.
- **Platform (Advanced/Dev)**: API Keys, Webhooks, Feature Flags.

## 13. Required Screens and Page Specifications
- **Global Transaction Viewer**: Custom Filament Resource mapping the Read Model (Projected Transactions). Includes filters for Type, Status, Date. *This aligns with current best practices from Context7 MCP using Custom Query Builders in Filament Tables.*
- **Approval Queue Page**: A dedicated page for Maker-Checker verifications, utilizing Filament Action Modals with required text input (`reason_code`).
- **Customer 360 (User Detail)**: An InfoList deeply tying `TransactionsRelationManager`, `KycRelationManager`, and `AuditLogRelationManager` under Tabs.

## 14. Entity Detail Page Specifications
- **Wallets/Account InfoList**:
    - *Overview*: Balance, Hold Amount, Frozen Status.
    - *Actions*: "Request Ledger Adjustment" (triggers modal, dispatches to Approvals Queue), "Freeze Wallet" (Requires Reason).
    - *Related*: Pockets, Linked MTN MoMo profiles.
- **Dispute/Case Management**:
    - Links to exactly 1 `PaymentIntent` or `AuthorizedTransaction`. Connects an `operator_id` to resolution timeline.

## 15. Workflow Specifications
- **Manual Adjustment Flow**:
    1. L2 Support clicks "Adjust Ledger" on Wallet.
    2. Input UI: Amount, Direction (Cred/Deb), Reason Code, File Attachment.
    3. State: `PendingApproval`.
    4. Notification: Fired to `Finance` Group.
    5. Finance Admin reviews UI -> Clicks "Approve".
    6. System dispatches Spatie Command: `ProcessManualLedgerAdjustment`.
    7. Event emitted, Projectors update balances. Immutable Audit log created.

## 16. Role and Permission Model
Use Spatie Permissions integrated with Filament Shields/Policies.
- **Support L1**: View Users, View Transactions, Cannot Edit, PII masked.
- **Operations L2**: Edit Non-Financial limits, Trigger Notification resends, Raise Adjustments (Maker).
- **Compliance Manager**: Approve KYC, Freeze/Unfreeze, View identity docs.
- **Finance Lead**: Approve Adjustments (Checker), View Reconciliation exports.
- **Super Admin**: View Platform / Webhooks.

## 17. Audit, Risk, and Control Model
*This aligns with current best practices from Context7 MCP* for strictly recording Spatie Event payload metadata:
- All sensitive Filament Actions MUST include an `Action::make()->requiresConfirmation()->form([ Textarea->name('reason')->required() ])`.
- The 'reason' is injected directly into the dispatched Event metadata payload mapping to the `operator_id`. Ensure `ProjectorHealth` is visible exclusively to Super Admins.

## 18. Data / Read Model / Search Specification
- **Flat Transaction Projection**: The existing Spatie Event streams may be too slow for global complex searching. We must create a new Read Model: `GlobalTransactionProjector` that flattens `AuthorizedTransaction`, `Account`, and `Merchant` states into an Elastic-friendly or index-heavy SQL table for instant Global Filament searching.

## 19. Implementation Plan and Sequencing

### Phase 1: Quick Wins & Launch-Blockers (W1)
- **Dependency**: Filament UI configuration (`app/Providers/Filament/AdminPanelProvider.php`).
- **Action**: Hide/Regroup existing Resources into the new Navigation Structure.
- **Action**: Build `GlobalTransactionResource` connecting to the existing Read Model.

### Phase 2: Maker-Checker & Support Readiness (W2-W3)
- **Action**: Create `AdjustmentRequest` Eloquent Model (Outside Spatie Event streams initially) to act as the State Machine for Approvals.
- **Action**: Create `Customer360` Filament InfoList Page combining relationships.

### Phase 3: Dispute & MoMo Observability (W4)
- **Action**: Scaffold `SupportCaseResource`.
- **Action**: Hook `MtnMomo` aggregate failures directly to Filament Notification badges.

## 20. File-Path-Aware Build Guidance
- Navigation: Modify `app/Providers/Filament/AdminPanelProvider.php` using `$panel->navigationGroups([])`.
- New Resource: `php artisan make:filament-resource GlobalTransaction --view`. Will live in `app/Filament/Admin/Resources/GlobalTransactionResource.php`.
- Action Form: Implement adjustments inside `app/Filament/Admin/Resources/AccountResource/Pages/ViewAccount.php` via `getHeaderActions()`.
- Re-architecturing old modules: Move `ManageUsersController` concepts fully into `UserResource` Action macros.

## 21. Pseudocode / Snippet Appendix
*This aligns with current best practices from Context7 MCP.*
```php
// app/Filament/Admin/Resources/AccountResource/Pages/ViewAccount.php

protected function getHeaderActions(): array
{
    return [
        Action::make('requestAdjustment')
            ->label('Request Ledger Adjustment')
            ->color('warning')
            ->icon('heroicon-o-scale')
            ->requiresConfirmation()
            ->form([
                TextInput::make('amount')->numeric()->required(),
                Select::make('type')->options(['credit' => 'Credit', 'debit' => 'Debit'])->required(),
                Textarea::make('reason')->required()->minLength(10),
            ])
            ->action(function (array $data, Account $record) {
                // Creates a holding ticket, does NOT update ledger directly
                AdjustmentTicket::create([
                    'account_id' => $record->id,
                    'requester_id' => auth()->id(),
                    'amount' => $data['amount'],
                    'type' => $data['type'],
                    'reason' => $data['reason'],
                    'status' => 'pending_finance_approval'
                ]);
                Notification::make()->title('Adjustment sent to Finance queue')->success()->send();
            })
            ->visible(fn (): bool => auth()->user()->can('request-adjustments')),
    ];
}
```

## 22. Testing and Acceptance Plan
- **Unit Tests**: `tests/Unit/MakerCheckerTest.php` ensuring L1 Support cannot dispatch an adjustment command without Finance Approval.
- **Feature Tests**: `tests/Feature/Filament/GlobalTransactionSearchTest.php`.
- **UAT Checklist**: Ensure Risk team can successfully freeze an account via UI with an attached reason log.

## 23. Launch-Critical Minimum Viable Back Office
🛑 **We cannot launch without:**
1. Global Transaction Viewer Resource.
2. Maker-Checker Manual Ledger Adjustments (Replacing un-auditable updates).
3. The Customer 360 User Detail Page.
4. Rigid separation of Roles (Support vs Finance vs Compliance).

## 24. Future Module Strategy
- *To Archive/Hide*: Legacy configurations, Microfinance APIs (unless explicitly rebranded), Developer API-Key generation (Move to Advanced).
- *To Expand Later*: Custom Rewards Engine UI configuration, Group Savings / Stokvel moderation interfaces, and custom UI for Social Chat moderation within the app.

## 25. Final Verdict
The current FinAegis foundation is highly capable and architecturally secure, but purely from an operations standpoint, the Administration interface is **not yet launch-ready**. By deploying the `GlobalTransactionResource`, the Maker-Checker Action hooks, and reorganizing the Filament Navigation, the system will instantly jump from a developer toolkit to an enterprise-grade fintech operations center.

## 26. Dependency Map
- **UI Rewrite** depends on **Filament V3/V4 upgrades** (Ensure no deprecation issues if upgrading to 5.x).
- **Customer 360** depends on stable `TransactionsRelationManager`.
- **Global Search** depends on optimized projector tables (`GlobalTransactionProjector`).

## 27. Assumptions / Unknowns
- How deep the `MtnMomo` payload failures are structured inside Spatie events (May require an intermediate projector to be safely readable by Filament).
- It is assumed `Spatie/Laravel-Permission` is already installed, mapped, and ready for integration into Filament policies. (Needs verification).
