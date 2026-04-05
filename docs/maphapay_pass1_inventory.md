# MaphaPay System Inventory & Forensic Discovery

## 1. Mobile App Capability Inventory (`maphapayrn`)
Extracted from `/Users/Lihle/Development/Coding/maphapayrn/src/features`

| Feature | User-Visible State | Lifecycle | Backend Dependency | Admin Must Be Able To |
|:---|:---|:---|:---|:---|
| **Auth** | Login, Register, Biometrics | Onboarding -> Authenticated | `/auth/*` routes, Passkey, 2FA | View Auth status, Reset 2FA, Block User |
| **Wallet** | Balance, Transaction History | Created -> Active -> Frozen | `Account`, `Transactions` API | View balances, Edit limits, Freeze/Unfreeze |
| **Send Money** | Recipient via Contact/Phone | Draft -> Sent -> Finalized | `Transfer`/`SendMoney` API | View, Reverse (if possible), Investigate |
| **Request Money** | Pending Requests, Pay Links | Requested -> Paid/Declined | `PaymentIntent` API | View, Cancel pending |
| **Scan (QR Pay)**| QR Scanner interface | Scan -> Confirm -> Paid | Merchant Payment API | View, Reverse |
| **Pay (Merchant)**| Merchant List, Pay Screen | Input -> Paid | Commerce/Merchant API | View Merchant, Refund |
| **Linked Wallets**| MoMo Auth, Other Banks | Linked -> Synchronized | Integration API (MTN/Bank) | Unlink, View Sync Status |
| **Top-up (MoMo)** | Add money via mobile money | Pending -> Success | MobilePayment API | View status, Retry, Refund |
| **Cash-out** | Withdraw to bank/MoMo | Pending -> Disbursed | Payout API | Approve (if large), Cancel, Investigate |
| **Rewards** | Points Balance, Quests | Earned -> Claimed | Rewards API, `RewardQuest` | View Points, Adjust |
| **Social** | Social Feed, Chat, Notes | Draft -> Sent -> Read | SocialMoney API | View Activity (audit), Moderate |
| **Notifications**| Alert List | Unread -> Read | Websocket, Push API | Send System Notification |
| **KYC/Profile** | Verification level, Docs | Unverified -> Complete | Kyc API (`/kyc/documents`) | View Docs, Approve/Reject KYC |
| **Savings** | Pockets, Goals | Created -> Funded -> Closed | Pocket API | View balances, Adjust |
| **Group Savings**| Stash/Stokvel groups | Joined -> Contributing | GroupSavings API | View Members, Mediate disputes |
| **Services** | Utilities, Airtime purchase | Selection -> Paid -> Delivered | Utility API | View, Retry failed purchase |
| **MCard** | Virtual/Physical cards | Issued -> Active -> Blocked | CardIssuance API | Block, Re-issue |
| **Finance** | Budget, Analytics graphs | Calculated dynamically | Analytics/Transactions | *(No admin actions needed)* |

---

## 2. Old Backend Capability Inventory (`maphapay-backend`)
*Status: Verified via forensic extraction of `/core/` Laravel instance in legacy repo.*

### A. Routes
Verified in `core/routes`:
- `/api` (Directory containing multiple API route files)
- `/merchant.php`
- `/agent.php`
- `/admin.php`
- `/user.php`
- `/ipn.php` (Instant Payment Notifications)

### B. Capabilities
Verified via `core/app/Http/Controllers/Admin` functionality logic:
- Auth, Wallet, Transactions, Send/Request money.
- **Mobile Recharge (Airtime):** Handled by `AirtimeController` and `MobileRechargeController`.
- **Budget/Reports:** Handled by `ReportController`.
- **Rewards:** `RewardFeatureController`.
- **Social Money:** `SocialMoneyFeatureController`.
- **Linked Wallets/Transfers:** `BankTransferController`, `BankController`.
- **Utilities:** `UtilityBillController`.
- **Additional (Found):** `DonationController`, `NgoController`, `EducationalFeeController`, `MicrofinanceController`, `VirtualCardController`.

### C. Admin Capabilities
Admin operations were densely grouped under `ManageUsersController` and specific operational controllers tracking user balances manually:
- **What Admins Could Do:**
  - Manually Add/Subtract Wallet Balances per user (`addSubBalance`).
  - Manually login as the user account (`login` using `Auth::loginUsingId()`).
  - Ban/Unban user with explicit reason strings (`status`).
  - Force override EV (Email Verify), SV (SMS Verify), and TS (Two-step) fields directly.
  - Approve/Reject KYC Documents manually with reasons (`kycApprove`, `kycReject`).
  - Push explicit dynamic notifications (Email, SMS, Push) to single users or batches (`sendNotificationAll`).
- **What Operators Could See:**
  - Extensive metric dashboards per user calculating total aggregates across 11 discrete transaction types (e.g. `total_deposit`, `total_send_money`, `total_education_fee`, `total_utility_bill`).
  - User Login histories tracking IP details.

---

## 3. New Back Office Capability Inventory (`maphapay-backoffice`)

### A. Filament Admin
Admin surfaces are located in `app/Filament/Admin/Resources`.
- **Pages**: `Dashboard`
- **Resources**: `AccountResource`, `AnomalyDetectionResource`, `ApiKeyResource`, `AssetResource`, `AuditLogResource`, `BasketAssetResource`, `BridgeTransactionResource`, `CertificateResource`, `CgoInvestmentResource`, `DeFiPositionResource`, `ExchangeRateResource`, `FinancialInstitutionApplicationResource`, `KycDocumentResource`, `MerchantResource`, `MultiSigWalletResource`, `OrderResource`, `PartnerResource`, `PaymentIntentResource`, `PocketResource`, `PollResource`, `RewardProfileResource`, `SubscriberResource`, `UserResource`, `VirtualsAgentResource`, `WebhookResource`
- **Relation Managers**: `TransactionsRelationManager` and `TurnoversRelationManager` under `AccountResource`.

### B. Domains/Modules
Enabled modules (extracted from `app/Domain`):
- `AI`, `Account`, `Activity`, `AgentProtocol`, `Asset`, `AuthorizedTransaction`, `Banking`, `Basket`, `Batch`, `CardIssuance`, `Cgo`, `Commerce`, `Compliance`, `Contact`, `CrossChain`, `Custodian`, `DeFi`, `Exchange`, `FinancialInstitution`, `Fraud`, `FundManagement`, `Governance`, `GroupSavings`, `KeyManagement`, `Lending`, `MachinePay`, `Mobile`, `MobilePayment`, `Monitoring`, `MtnMomo`, `Newsletter`, `Onboarding`, `Payment`, `Performance`, `Privacy`, `Product`, `Ramp`, `Referral`, `RegTech`, `Regulatory`, `Relayer`, `Rewards`, `SMS`, `Security`, `Shared`, `SocialMoney`, `Stablecoin`, `Treasury`, `TrustCert`, `User`, `VirtualsAgent`, `VisaCli`, `Wallet`, `Webhook`, `X402`

### C. APIs
From `routes/api.php`:
- **REST**: `/auth/*`, `/v1/users/*`, `/webhooks/*` (Paysera, Santander, Coinbase, Ondato, Helius, VisaCli, etc.), `/v1/monitoring/*`, `/v1/banners`, `/v1/ramp/*`, `/v1/referrals/*`, `/v1/sponsorship/*`.
- Module Route Loader loads decoupled `/api` paths implicitly via `app(ModuleRouteLoader::class)->loadRoutes()`.

### D. Operational Surfaces (UI Existence)
| Area | Extracted Admin Resource | Status |
|:---|:---|:---|
| Transactions | `TransactionsRelationManager` | Exists (inside Account) |
| Users | `UserResource` | Exists |
| Wallets | `AccountResource`, `MultiSigWalletResource` | Exists |
| Compliance | `KycDocumentResource`, `AuditLogResource` | Exists |
| Monitoring | `AnomalyDetectionResource`, Dashboard | Exists |
| Notifications | `CgoNotificationResource` | Partial |
| Merchants | `MerchantResource` | Exists |
| Treasury | `BasketAssetResource`, `ExchangeRateResource` | Exists |
| Fraud | `AnomalyDetectionResource` | Exists |

### E. Workflows
- Spatie Event Sourcing is used, workflows are event-driven (`ProjectorHealthController` tracks `stream-status` and `projector-lag`). Most workflow states are implicit in aggregates. Monitoring enables `startWorkflow/stopWorkflow` manually via API.

### F. Permissions
- Middleware observed: `is_admin`, `auth:sanctum`, `webhook.signature:*`, `require.2fa.admin`. Roles/Policies exist via standard Filament mechanisms tied to the User resource.

---

## 4. Admin UI Inventory (Sidebar + Pages)

- **Banking Group**: Appears to include items like Accounts, Assets, Bridge Transactions, DeFi Positions, Exchange Rates, Financial Institution Apps, Pockets, VirtualsAgents.
- **System Group**: Api Keys, Audit Logs, Certificates, Kyc Documents, Polls, Subscribers, Webhooks.
*(Note: Navigation groups are explicitly defined in AdminPanelProvider as `Banking` and `System`)*.

---

## 5. Capability Classification Table

| Capability | Source | Exists in Old Backend | Exists in New Backend Domain | Exists in Admin UI | Workflow Complete | Notes |
|:---|:---|:---|:---|:---|:---|:---|
| **Auth** | App / New Back Office | Y | Y (`User`/`Auth`) | Y (`UserResource`) | Y | Modern Passkey & 2FA migrated. |
| **Wallet/Balances** | App / New Back Office | Y | Y (`Account`) | Y (`AccountResource`) | Y | Balances driven by event projectors instead of direct SQL. |
| **Transactions/Transfers**| App / New Back Office | Y | Y (`AuthorizedTransaction`) | Y (Via RelationMgr) | Y | Atomic event-sourced transfers replacing SQL updates. |
| **Send/Req Money** | App / New Back Office | Y | Y (`Payment`) | Y (`PaymentIntent`) | Y | P2P capabilities natively supported. |
| **MTN MoMo** | App | Y (Unverified specifically, but related to MobilePayment) | Y (`MtnMomo`) | N | Partial | Domain exists but no explicit Admin UI resource found. |
| **Rewards** | App / New Back Office | Y (`RewardFeature`) | Y (`Rewards`) | Y (`RewardProfile`) | Y | Reward quests/shop items are modeled natively. |
| **Social Money** | App | Y (`SocialMoneyFeature`) | Y (`SocialMoney`) | N | Partial | Domain exists, no explicit Filament resource found. |
| **KYC/Compliance** | App / New Back Office | Y (`KycController`) | Y (`Compliance`) | Y (`KycDoc`) | Y | Ondato Webhooks verified. |
| **Utilities/Airtime**| App | Y (`Airtime`, `UtilityBill`) | Y (`Commerce`/`Mobile`) | N | Partial | Commerce domains exist, UI not explicitly visible. |
| **Banners/Ramp** | New Back Office | Y (`BannerController`) | Y (`Ramp`) | N | Partial | API exists `v1/ramp`, `v1/banners`. UI missing. |
| **Savings/Stokvel** | App | Y (`PocketFeature`) | Y (`GroupSavings`) | Y (`PocketResource`) | Y | Managed by Pocket mechanism. |
| **Donations/Edu/Ngo**| Old Backend Only | Y | N | N | N | These features are legacy specific and not prominently migrated. |

---

## 6. Observations (Factual)
1. **Admin Power Reduction Strategy**: In the old backend (`ManageUsersController.php`), administrators had explicit UI capabilities to directly modify user balances, impersonate users (`loginUsingId()`), and alter EV/SV fields instantly. These raw manual overrides are absent from the event-driven new back office UI constraints.
2. **Missing Admin Surfaces**: Modifiers/Admin capabilities for `MtnMomo`, `SocialMoney`, `Banners`, and `Ramp` lack dedicated CRUD interfaces inside `app/Filament/Admin/Resources`.
3. **Admin UI Global Gap**: The new back office relies heavily on `AccountResource` -> `TransactionsRelationManager` to view transactions. A top-level global transaction viewer does not explicitly exist as a standalone primary Filament resource.
4. **Dropped Legacy Features**: The old backend specifically contained controllers for `Microfinance`, `Donations`, `NGO`, and `EducationFee` that do not map 1:1 to new domains cleanly (aside from broad Payments generalizations).
