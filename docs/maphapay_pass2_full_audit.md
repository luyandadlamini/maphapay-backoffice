# MaphaPay Back Office: Full Audit & Gap Analysis

## 1. Executive Summary
MaphaPay’s migration to the FinAegis-based event-sourced back office represents a massive architectural upgrade in backend data integrity, traceability, and decoupling. However, the **Operational UI (Filament Admin)** drastically lags behind the mobile app's features and the backend's capabilities. While the foundation is highly secure and scalable, operations teams are currently flying blind. Key operational surfaces (global transaction search, dispute workflows, and legacy feature controls) are absent, rendering the platform unready for full-scale production support.

---

## 2. Gap Analysis
Comparing the mobile app realities and backend structures vs. current Filament UI:

*   **Wallet & Balances**: *Improved (Backend)* / *Missing (UI)*. Moved from raw SQL updates to robust event-sourcing projectors. However, there is no UI workflow for authorized, tracked manual adjustments.
*   **Transactions**: *Over-Engineered (Backend)* / *Missing (UI)*. The bridge/transfer primitives are robust, but there is no global `TransactionResource` for operators to search by hash, amount, or date independently of selecting a specific User Account first.
*   **Mobile Recharge / Utilities / MoMo**: *Partial*. APIs exist (`Commerce`/`Mobile`), but zero UI visibility to retry failed utility hookups or track MoMo failures.
*   **Social Money / Rewards**: *Backend Only*. Strong domain models exist, but operators cannot moderate chat, dispute grouped funds, or manually adjust reward points.
*   **Support & Ticketing**: *Missing*. The old backend had `SupportTicketController`. The new system relies entirely on externalising support.

---

## 3. Regression Analysis vs Old Backend
The old backend heavily favoured "God-Mode" controllers (`ManageUsersController`), allowing dangerous but operationally convenient actions.
*   **Missing (Positive Regression)**: Raw manual balance adjustments (`addSubBalance` without limits) and user impersonation (`loginUsingId()`). These were security/audit disasters and correctly removed.
*   **Missing (Negative Regression)**: The old backend had top-level Deposit/Withdrawal approval queues (`CashInController`, `CashOutController`). The new backend handles this via atomic states, but lacks the necessary UI to pause, approve, or reject high-risk external payouts.
*   **Lost Product Verticals**: Specific controllers for Education Fees, NGO Donations, and Microfinance are gone. If these must persist, they require modeling into the new Commerce domains.

---

## 4. Operational Readiness Assessment
*Can an operator fully manage this in production from the UI?*

| Capability | Verdict | Reason |
| :--- | :--- | :--- |
| **User/Auth Reset** | PARTIAL | `UserResource` exists, but lacks dedicated 2FA/Passkey reset macro buttons. |
| **KYC Approval** | YES | `KycDocumentResource` exists and hooks into Ondato webhooks. |
| **Transaction Investigation** | NOT POSSIBLE | No global table to trace an isolated Tx Hash. Ops must blindly hunt through specific `AccountResources`. |
| **Manual Adjustments (Refunds)** | NOT POSSIBLE | No UI macro exists for a safe, dual-control balance adjustment. |
| **External Integrations (Ramp/Utilities)** | BACKEND ONLY | APIs exist, but no operational observability or retry buttons. |

---

## 5. Navigation Redesign (Target Sidebar)
The current simple `Banking` / `System` grouping is inadequate for a scaled fintech.

**Proposed Sidebar Structure:**
1.  **Dashboard** (Live graphs, Projector Lags, Alert summaries)
2.  **Customers** (`UserResource`, Profile Management, Limits)
3.  **Merchants & Orgs** (`MerchantResource`, `FinancialInstitution`)
4.  **Wallets & Ledgers** (`AccountResource`, `MultiSigWallet`, `PocketResource`)
5.  **Transactions** (NEW: Global Ledger, Bridge Tx, Failed Transfers)
6.  **Compliance & KYC** (Ondato Docs, Suspicious Activity, Case Management)
7.  **Risk & Fraud** (`AnomalyDetectionResource`, Velocity Rules)
8.  **Support Hub** (NEW: Disputes, Ticket integration, Chat Moderation)
9.  **Treasury & Finance** (`AssetResource`, `ExchangeRateResource`, `BasketAsset`, Reconciliation)
10. **Growth & Rewards** (Banners, Referrals, Reward Profiles)
11. **Platform (Advanced)** (Webhooks, API Keys, System Audit Logs, Projector Health)

---

## 6. Capability Matrix

| Capability | Actor | UI Presence | Workflow Complete | Auditability | Priority |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Global Transactions** | Ops / Finance | 🔴 None | Partial | High (Events) | **P0** (Blocker) |
| **Manual Adjustments** | Finance | 🔴 None | Missing | High (Needed) | **P0** (Blocker) |
| **KYC Reviews** | Compliance | 🟢 High | Yes | High | P1 |
| **Fraud Triage** | Risk | 🟡 Medium | Partial | High | P1 |
| **Utility/MoMo Support** | Ops / Support | 🔴 None | Missing API-UI | Low | P2 |
| **Reward Moderation** | Growth Ops | 🟡 Medium | Partial | Low | P3 |

---

## 7. Target Back Office Blueprint
### A. Screens Required
*   **Global Transaction Viewer**: A unified list of all `AuthorizedTransactions` and `PaymentIntents` filterable by date, amount, user, and status.
*   **Transaction Detail View**: Visual timeline of the transaction lifecycle (Initiated -> Event Handled -> Projector Updated -> Webhook Fired).
*   **Dispute/Case Management**: A dedicated page tying a disputed transaction to a user conversation and a freeze/refund action.
*   **Adjustment Panel**: A dedicated modal in the Wallet screen specifying Amount, CR/DR, Reason Code, and requires a 2nd admin approval.

### B. Workflows & Controls
*   **Dual Control (4-Eyes Principle)**: Any manual ledger adjustment over $0.00 must require User A to request and User B (Finance Role) to approve.
*   **Projector Recovery Workflow**: A UI button to replay event streams for a specific account if a projection diverges (leverages existing `ProjectorHealthController`).

---

## 8. Roles & Permissions Model
Filament Shields or native SPATIE permissions must enforce:
*   **Super Admin**: Sys-admin only. Manages webhooks, modules, tech health.
*   **Compliance Lead**: Full access to KYC, Anomaly rules, and User Freezing. Cannot move money.
*   **Finance/Treasury**: Controls `ExchangeRates`, `BasketAssets`, and Approves Manual Adjustments.
*   **Operations L2**: Can view global transactions, push UI banners, retry failed utility transactions.
*   **Support L1 (Read-Only)**: View balances, view transaction status, view user KYC status. PII masked.

---

## 9. Roadmap

### A. Quick Wins (Days)
*   **Priority**: High | **Impact**: High | **Complexity**: Low
*   Build a global read-only `TransactionResource` in Filament aggregating the Ledger.
*   Redesign the Sidebar into the proposed Groups (Phase 5).

### B. Phase 1: Operational Base (Weeks)
*   **Priority**: High | **Impact**: Critical | **Complexity**: Medium
*   Implement standard Roles/Permissions (Support vs Compliance vs Finance).
*   Add action buttons to `UserResource` (e.g., Toggle 2FA, Force Reset Password, Freeze Wallet).

### C. Phase 2: Financial Controls (Months)
*   **Priority**: Medium | **Impact**: High | **Complexity**: High
*   Build the Dual-Control Manual Ledger Adjustment workflow (replaces the old `addSubBalance`).
*   Build the Withdrawal/Payout Approval queue for large sums.

### D. Phase 3: Edge Features
*   **Priority**: Low | **Impact**: Medium | **Complexity**: High
*   Connect the Social Money, Utility, and Banners domains to Filament Resources for Growth/Marketing ops.

---

## 10. Scoring & Verdict

*   User Ops: 2/5 (Too rigid, hard to find transactions)
*   Merchant Ops: 3/5
*   Compliance: 4/5 (Ondato + Anomaly resources exist)
*   Fraud: 3/5 (Needs triage workflows)
*   Finance/Treasury: 3/5 (Baskets exist, but manual interventions missing)
*   Support: 1/5 (No ticketing or user emulation safeguards)
*   Permissions: 2/5 (Needs strict enforcement beyond `is_admin`)
*   Auditability: 5/5 (Event sourcing baseline is flawless)
*   Navigation Clarity: 1/5 (Too chaotic and developer-centric)

**OVERALL READINESS SCORE:** 2.6 / 5

### FINAL VERDICT
🛑 **NOT READY FOR PRODUCTION LAUNCH**  
While the backend is architecturally superior and extremely secure, the operational interface is fatally incomplete. Operations teams cannot trace global transactions natively, enact safe refunds, or troubleshoot failed external integrations (Utilities/Ramp) without developer DB access. Execute Phase 1 Roadmap strictly before migrating live users.
