# Backoffice Audit Report

> **Date**: March 31, 2026  
> **Status**: Comprehensive Audit Complete  
> **Prepared for**: FinAegis/Maphapay Development Team

---

## Executive Summary

The FinAegis backoffice (Filament v3) has **41 admin resources**, but there are **significant gaps** in mobile app feature management. Many mobile services lack admin interfaces, while some non-essential features consume development resources.

---

## PART 1: Current State Analysis

### 1.1 Existing Filament Admin Resources (41 total)

| Category | Resources |
|----------|-----------|
| **Banking** | AccountResource |
| **System** | UserResource, UserBankPreferenceResource, AuditLogResource, ApiKeyResource |
| **Relayer** | SmartAccountResource |
| **Mobile** | MobileDeviceResource |
| **Rewards** | RewardProfileResource, RewardQuestResource, RewardShopItemResource |
| **Commerce** | MerchantResource, OrderResource, PaymentIntentResource, OrderBookResource |
| **Lending** | LoanResource |
| **Compliance** | KycDocumentResource, AnomalyDetectionResource, FilingScheduleResource |
| **Governance** | PollResource, VoteResource, GcuVotingProposalResource |
| **Investments** | CgoInvestmentResource, CertificateResource |
| **Technical** | AssetResource, BasketAssetResource, ExchangeRateResource, BridgeTransactionResource, WebhookResource, PartnerResource, MultiSigWalletResource, DeFiPositionResource, ReconciliationReportResource, PortfolioSnapshotResource, SubscriberResource, UserInvitationResource, VirtualsAgentResource, DelegatedProofJobResource, FinancialInstitutionApplicationResource, CgoNotificationResource, KeyShardRecordResource |

### 1.2 Mobile App Services (from API Controllers)

Based on the mobile API structure, the app supports these **core mobile services**:

| Service | Key Endpoints | Admin Status |
|---------|--------------|--------------|
| **Wallet** | `/api/v1/wallet/balances`, `/state`, `/addresses`, `/transactions` | PARTIAL |
| **Cards** | Card issuance, provisioning, JIT funding, transactions | MISSING |
| **Rewards** | Profile, quests, shop, redemption | PARTIAL |
| **Commerce** | Payment requests, QR parsing, merchants | PARTIAL |
| **Privacy** | Shielded balances, proofs (RAILGUN) | MISSING |
| **TrustCert** | Certificates, trust levels, limits | MISSING |
| **Ramp** | On/off ramp, quotes, sessions | MISSING |
| **Relayer** | Smart accounts, paymaster, gas sponsoring | PARTIAL |
| **Referral** | Sponsorship, referrals | MISSING |

---

## PART 2: Critical Gaps - Missing Admin Features

### 2.1 Wallet Management (CRITICAL)

#### A. Savings Pockets / Piggy Banks

- **Status**: NO RESOURCE EXISTS
- **What's needed**:
  - List all savings pockets per user
  - View pocket balance, target, auto-save rules
  - Manual deposit/withdrawal from pockets
  - Freeze/unfreeze pockets
  - View auto-save transaction history

#### B. Crypto Wallet Balances

- **Status**: PARTIAL (only SmartAccountResource exists)
- **Current gap**: No consolidated multi-network balance view
- **What's needed**:
  - Per-token, per-network balance dashboard
  - Total USD value aggregation
  - Balance across: Polygon, Base, Arbitrum, Ethereum, Solana
  - Supported tokens: USDC, USDT, WETH, WBTC

#### C. Wallet Addresses

- **Status**: PARTIAL (view only)
- **Current gap**: Can't manage addresses
- **What's needed**:
  - View all addresses per user
  - Address labeling/aliasing
  - Deployment status per network
  - Pending operations visibility

---

### 2.2 Card Management (MISSING - HIGH PRIORITY)

Based on `CardController.php` and `CardholderController.php`:

| Feature | Status | Admin Action Needed |
|---------|--------|---------------------|
| **Card List** | MISSING | View all cards, filter by status |
| **Card Issuance** | MISSING | Issue new physical/virtual cards |
| **Card Provisioning** | MISSING | View device provisioning status |
| **JIT Funding** | MISSING | Configure JIT funding rules |
| **Card Transactions** | MISSING | View card authorization history |
| **Card Limits** | MISSING | Set per-card spending limits |
| **Card Freeze/Unfreeze** | MISSING | Toggle card status |
| **Card Replacement** | MISSING | Request replacement |

**Filament Resource to create**: `CardResource` for `Card` model

---

### 2.3 TrustCert Management (MISSING)

Based on `MobileTrustCertController.php` and `CertificateApplicationController.php`:

| Feature | Status | Admin Action Needed |
|---------|--------|---------------------|
| **Trust Levels** | MISSING | View/override user trust levels |
| **Certificate Applications** | PARTIAL | Approve/reject applications |
| **Trust Limits** | MISSING | View/modify daily/monthly/single limits |
| **Limit Upgrades** | MISSING | Manual trust level upgrades |
| **Certificate Download** | MISSING | View/download user certificates |

**Filament Resources to create**:
- `TrustLevelResource`
- `TrustCertificateResource`

---

### 2.4 Privacy/Shielding Management (MISSING)

Based on `PrivacyController.php`:

| Feature | Status | Admin Action Needed |
|---------|--------|---------------------|
| **Shielded Balances** | MISSING | View RAILGUN shielded balances |
| **Privacy Proofs** | MISSING | View generated proofs |
| **Shield/Unshield History** | MISSING | Transaction history |
| **Privacy Settings** | MISSING | View user privacy config |

**Note**: Privacy features are EVM-only (Polygon, Base, Arbitrum). No Solana support.

**Filament Resource to create**: `PrivacyShieldResource`

---

### 2.5 Ramp (On/Off Ramp) Management (MISSING)

Based on `RampController.php` and `RampService`:

| Feature | Status | Admin Action Needed |
|---------|--------|---------------------|
| **Ramp Sessions** | MISSING | View all buy/sell sessions |
| **Session Status** | MISSING | Monitor pending/completed/failed |
| **Provider Quotes** | MISSING | Configure provider preferences |
| **Limits** | CONFIG ONLY | Modify min/max/daily limits |
| **Provider Toggle** | MISSING | Enable/disable providers |

**Filament Resource to create**: `RampSessionResource`

---

### 2.6 Rewards Management (PARTIAL - Needs Enhancement)

**Existing**: RewardProfileResource, RewardQuestResource, RewardShopItemResource

**Missing**:

| Feature | Status | Admin Action Needed |
|---------|--------|---------------------|
| **XP Adjustment** | MISSING | Manually add/deduct XP per user |
| **Points Adjustment** | MISSING | Manually add/deduct points |
| **Level Override** | MISSING | Set user to specific level |
| **Quest Triggering** | MISSING | Manually complete quests for users |
| **Streak Management** | MISSING | View/reset user streaks |
| **Quest Configuration** | BASIC | Create/edit quest definitions |

**Filament Resources to enhance/create**:
- `UserRewardProfileResource` (for per-user reward management)

---

### 2.7 Relayer / Paymaster Management (PARTIAL)

**Existing**: SmartAccountResource (view only)

**Missing**:

| Feature | Status | Admin Action Needed |
|---------|--------|---------------------|
| **Paymaster Configuration** | MISSING | Configure gas sponsorship |
| **Supported Tokens** | MISSING | Manage paymaster token support |
| **UserOps Visibility** | MISSING | Monitor pending user operations |
| **Gas Price Updates** | MISSING | View/configure gas settings |
| **Network Configuration** | MISSING | Enable/disable networks |

**Filament Resources to create**:
- `PaymasterConfigResource`
- `UserOperationResource`

---

### 2.8 Referral/Sponsorship Management (MISSING)

Based on `ReferralController.php` and `SponsorshipController.php`:

| Feature | Status | Admin Action Needed |
|---------|--------|---------------------|
| **Referral List** | MISSING | View all referrals |
| **Referral Status** | MISSING | Pending/completed referrals |
| **Referral Payouts** | MISSING | View/modify referral rewards |
| **Sponsorship Programs** | MISSING | Configure sponsorship tiers |
| **Sponsorship Claims** | MISSING | Approve/reject claims |

**Filament Resource to create**: `ReferralResource`

---

### 2.9 User Management Enhancements (NEEDED)

**Existing**: UserResource (basic)

**Missing**:

| Feature | Status | Admin Action Needed |
|---------|--------|---------------------|
| **Consolidated Wallet View** | MISSING | All accounts + wallets per user |
| **Transaction Limits** | MISSING | Per-user spending limits |
| **KYC Workflow** | PARTIAL | Full KYC case management |
| **Bank Preferences** | PARTIAL | UserBankPreferenceResource exists |
| **Notification Preferences** | MISSING | Push/email preferences |
| **Session Management** | MISSING | Active sessions per user |
| **Device Management** | PARTIAL | View devices, block/unblock |

**Filament Resources to enhance**:
- Extend UserResource with relation managers for:
  - Wallets (smart accounts)
  - Bank accounts
  - Cards
  - Transactions

---

### 2.10 Commerce Management (PARTIAL)

**Existing**: MerchantResource, OrderResource, PaymentIntentResource

**Missing**:

| Feature | Status | Admin Action Needed |
|---------|--------|---------------------|
| **Payment Request Management** | MISSING | View/cancel payment requests |
| **QR Payment Debugging** | MISSING | Parse/verify QR codes |
| **Merchant Settlement** | MISSING | View pending settlements |
| **Refund Management** | MISSING | Process refunds |

---

## PART 3: Unnecessary / Deprecatable Features

### 3.1 Features That Appear Unused or Demo-Only

| Feature | Evidence | Recommendation |
|---------|----------|----------------|
| **Foodo** | Restaurant analytics completely unrelated to banking | Remove or deprioritize |
| **SubProducts Page** | Unclear if used in production | Evaluate necessity |
| **Some GCU Features** | Voting may not be in production | Verify before investing |
| **External Exchange Connectors** | Complex integrations | Evaluate usage |

### 3.2 Module Visibility Trait

The codebase has `RespectsModuleVisibility` trait - verify which modules are actually enabled in production.

---

## PART 4: Comparison with Best-in-Class Admin Panels

### Industry Standard Features (Missing Here)

| Feature | Description |
|---------|-------------|
| **User Segment Analysis** | Group users by behavior/Risk |
| **Automated Alerts** | Configurable thresholds |
| **Bulk Operations** | Mass freeze, limit changes |
| **Audit Trail Dashboard** | Consolidated logging view |
| **Approval Workflows** | Multi-level approvals for sensitive ops |
| **Scheduled Reports** | Automated email reports |
| **Dashboard Customization** | Drag-drop widgets |
| **Search Across Resources** | Global search (partially exists) |

---

## PART 5: Recommended Implementation Priority

### PHASE 1: Critical (Mobile App Blocking)

| Priority | Resource | Effort | Reason |
|----------|----------|--------|--------|
| 1 | **CardResource** | Medium | Mobile card feature needs admin |
| 2 | **TrustLevelResource** | Medium | TrustCert limits needed |
| 3 | **RampSessionResource** | Low | On/off ramp visibility |
| 4 | **User Wallet View** (extend UserResource) | Medium | Consolidated user view |

### PHASE 2: Important (Operational Efficiency)

| Priority | Resource | Effort | Reason |
|----------|----------|--------|--------|
| 5 | **SavingsPocketResource** | Medium | Savings pockets feature |
| 6 | **PrivacyShieldResource** | Medium | Privacy feature admin |
| 7 | **ReferralResource** | Low | Referral program management |
| 8 | **UserOperationResource** | Medium | Paymaster visibility |

### PHASE 3: Enhancement (Polish)

| Priority | Resource | Effort | Reason |
|----------|----------|--------|--------|
| 9 | **PaymasterConfigResource** | Medium | Gas sponsorship config |
| 10 | **XP/Points Adjustment** (extend RewardProfile) | Low | Rewards management |
| 11 | **User Session Management** | Low | Security enhancement |
| 12 | **Bulk Operations** (extend existing) | Medium | Operational efficiency |

### PHASE 4: Deprecate/Remove

| Item | Action |
|------|--------|
| **Foodo** | Remove or archive |
| **Unused demo features** | Disable via module system |

---

## PART 6: Proposed Implementation Plan

### Step 1: Create CardResource

```
app/Filament/Admin/Resources/CardResource/
├── CardResource.php
└── Pages/
    ├── ListCards.php
    ├── ViewCard.php
```

**Key fields**:
- Card number (masked), status, type (virtual/physical)
- Owner user, cardholder name
- Spending limits, expiration
- JIT funding configuration
- Linked smart account

**Actions**: Freeze/Unfreeze, Replace, Update Limits

---

### Step 2: Create TrustLevelResource

```
app/Filament/Admin/Resources/TrustLevelResource/
├── TrustLevelResource.php (for TrustLevel model)
└── Pages/
```

**Key management**:
- View user trust levels
- Manual level upgrades
- Limit overrides
- Certificate issuance

---

### Step 3: Create RampSessionResource

```
app/Filament/Admin/Resources/RampSessionResource/
├── RampSessionResource.php
└── Pages/
    ├── ListRampSessions.php
    └── ViewRampSession.php
```

**Key features**:
- Session status monitoring
- Provider breakdown
- Volume analytics
- Issue resolution

---

### Step 4: Enhance UserResource with Relation Managers

Add to `UserResource.php`:

```php
public static function getRelations(): array
{
    return [
        RelationManagers\WalletsRelationManager::class,
        RelationManagers\CardsRelationManager::class,
        RelationManagers\BankAccountsRelationManager::class,
        RelationManagers\TrustLevelRelationManager::class,
    ];
}
```

---

### Step 5: Create SavingsPocketResource

**Model assumption**: `SavingsPocket` or `PiggyBank` model exists or needs creation

**Features**:
- List pockets per user
- Balance, target amount, auto-save rules
- Transaction history
- Manual operations

---

## PART 7: Comprehensive Fund Management System

### 7.1 Overview

The fund management system is critical for:
1. **Testing/Development**: Funding user accounts without demo mode
2. **Customer Support**: Resolving issues, processing refunds
3. **Reconciliation**: Fixing discrepancies, balance corrections
4. **Operations**: Day-to-day fund management for the back office team

### 7.2 Current State Analysis

**Existing Infrastructure**:
- `AccountService` - deposit, withdraw, freeze/unfreeze
- `AccountBalance` model - multi-currency support via `asset_code`
- `Asset` model - supports fiat, crypto, commodity types
- `TransactionAggregate` - event-sourced transactions
- `TransactionProjection` - transaction history tracking
- `TreasuryAggregate` - treasury management (for funding sources)

**Current Gaps in AccountResource**:
| Feature | Current Status |
|---------|---------------|
| USD deposits only | ❌ Only works with hardcoded USD |
| No multi-currency | ❌ Cannot select asset type |
| No transfers | ❌ No account-to-account transfers |
| No manual adjustments | ❌ Cannot correct balances |
| No bulk funding | ❌ Cannot fund multiple accounts |
| Limited reversal | ⚠️ API exists but no UI |

---

### 7.3 Required Fund Management Features

#### A. Multi-Currency Fund Operations

**Current Problem**: AccountService only handles USD (hardcoded)

**Required**:
| Feature | Description |
|---------|-------------|
| **Asset Selection** | Dropdown to select USD, EUR, GBP, USDC, etc. |
| **Per-Currency Balances** | Show all balances (AccountBalance relation) |
| **Currency-Specific Deposits** | Deposit specific amounts in each currency |
| **Currency-Specific Withdrawals** | Withdraw specific amounts in each currency |

**Implementation**:
```php
// New methods in AccountService
public function depositForAsset(mixed $uuid, Money $money): void
public function withdrawForAsset(mixed $uuid, Money $money): void
```

---

#### B. Test Funding System (Non-Demo Mode)

**Requirement**: Fund user accounts for testing without enabling demo mode

**Components Needed**:

| Component | Description |
|-----------|-------------|
| **Treasury Pool Account** | Central account for test funding source |
| **Funding Request Form** | UI to request funds for a user account |
| **Funding Audit Trail** | Track all test fundings with reason/approver |
| **Funding Limits** | Per-user, per-day, per-transaction limits |
| **Bulk Funding** | Fund multiple accounts at once (CSV upload) |

**Filament Resources to Create**:

```
app/Filament/Admin/Resources/FundManagement/
├── TestFundingResource/
│   ├── TestFundingResource.php
│   └── Pages/
│       ├── ListTestFundings.php
│       ├── CreateTestFunding.php
│       └── BulkFundAccounts.php
├── TreasuryPoolResource/
│   ├── TreasuryPoolResource.php
│   └── Pages/
│       └── ViewTreasuryPool.php
└── FundAdjustmentResource/
    ├── FundAdjustmentResource.php
    └── Pages/
```

---

#### C. Account Transfer System

**Required for**:
- Moving funds between user accounts
- Internal corrections
- Refund processing

**Filament Components**:

| Feature | Description |
|---------|-------------|
| **Transfer Form** | From account, To account, Amount, Currency, Reason |
| **Transfer History** | View all internal transfers |
| **Transfer Reversal** | Reverse an internal transfer |

---

#### D. Manual Balance Adjustments

**Required for**:
- Error corrections
- Goodwill adjustments
- Compensations
- Refunds processed outside system

**Components**:

| Feature | Description |
|---------|-------------|
| **Adjustment Form** | Account, Amount (+/-), Reason, Supporting docs |
| **Approval Workflow** | Manager approval for large adjustments |
| **Adjustment Journal** | Audit trail of all adjustments |

---

#### E. Transaction Reversal Management

**Existing**: `TransactionReversalController` (API only)

**Required UI**:

| Feature | Description |
|---------|-------------|
| **Reversal Queue** | List pending/completed reversals |
| **Reverse Transaction** | Select transaction, enter reason, process |
| **Bulk Reversals** | Reverse multiple transactions at once |
| **Reversal Reports** | Track all reversals by user, date, reason |

---

#### F. Reconciliation Tools

**Required for**:
- Finding discrepancies
- Verifying balances
- Generating reports

**Components**:

| Feature | Description |
|---------|-------------|
| **Balance Verification** | Compare system balance vs expected |
| **Discrepancy Report** | List accounts with balance issues |
| **Transaction Search** | Advanced search with filters |
| **Account Statement** | Generate statement for date range |
| **Audit Export** | Export transactions for audit |

---

### 7.4 Proposed Fund Management Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    FUND MANAGEMENT                          │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌───────────────┐    ┌───────────────┐    ┌─────────────┐ │
│  │  FUNDING      │    │  OPERATIONS   │    │  AUDIT      │ │
│  ├───────────────┤    ├───────────────┤    ├─────────────┤ │
│  │ Test Funding  │    │ Deposits      │    │ Adjustment  │ │
│  │ Bulk Funding  │    │ Withdrawals   │    │ Log        │ │
│  │ Treasury Pool │    │ Transfers     │    │ Reversal    │ │
│  │ Refunds       │    │ Adjustments   │    │ History     │ │
│  └───────────────┘    └───────────────┘    └─────────────┘ │
│                                                             │
│  ┌─────────────────────────────────────────────────────────┐│
│  │              SUPPORTING INFRASTRUCTURE                  ││
│  ├─────────────────────────────────────────────────────────┤│
│  │ TreasuryAggregate (funding source)                      ││
│  │ AccountBalance (multi-currency)                         ││
│  │ TransactionProjection (audit trail)                     ││
│  │ Workflows (deposit, withdraw, transfer, reverse)        ││
│  └─────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────┘
```

---

### 7.5 Implementation Details

#### 7.5.1 Treasury Pool Setup

**Purpose**: Central account that holds funds for test funding

**Requirements**:
```php
// TreasuryAggregate to track pool balance
class TreasuryAggregate extends AggregateRoot {
    public int $usdBalance = 0;
    public int $usdcBalance = 0;
    // ... other assets
}

// Admin ability to add funds to treasury pool
// (simulates receiving funds from bank/integration)
```

**Filament UI**:
- View treasury pool balances per currency
- Add funds to treasury pool
- View treasury transaction history

---

#### 7.5.2 Test Funding Workflow

```
1. Admin selects user account to fund
2. Admin enters amount and currency
3. Admin selects reason (testing, refund, compensation, etc.)
4. System validates treasury has sufficient funds
5. System creates transaction from treasury to user account
6. Transaction recorded with full audit trail
7. User account balance updated
8. Admin receives confirmation
```

**Filament Resource**: `TestFundingResource`

**Form Fields**:
- Target Account (search by user/UUID)
- Amount
- Currency (USD, EUR, USDC, etc.)
- Reason (dropdown: Testing, Refund, Compensation, Error Correction, Other)
- Notes (optional text)

---

#### 7.5.3 Bulk Funding Workflow

```
1. Admin uploads CSV with: account_uuid, amount, currency, reason
2. System validates all rows
3. System shows preview with total amounts per currency
4. Admin confirms bulk funding
5. System processes sequentially (or in batches)
6. System reports success/failure per row
7. Admin downloads result report
```

**CSV Format**:
```csv
account_uuid,amount,currency,reason
uuid-1,100.00,USD,Testing
uuid-2,50.00,EUR,Compensation
uuid-3,25.50,USDC,Refund
```

---

#### 7.5.4 Transfer Between Accounts

```
1. Admin selects source account (or treasury)
2. Admin selects destination account (or user)
3. Admin enters amount and currency
4. Admin enters reason/description
5. System validates:
   - Source has sufficient balance
   - Source is not frozen
   - Destination is not frozen
6. System creates debit transaction on source
7. System creates credit transaction on destination
8. Both transactions linked via transaction_group_uuid
9. Audit trail recorded
```

---

#### 7.5.5 Balance Adjustment Workflow

```
1. Admin selects account
2. Admin enters adjustment amount (+/-)
3. Admin selects reason category
4. Admin enters detailed description
5. If amount > threshold, requires approval
6. On approval:
   - Create adjustment transaction
   - Record in adjustment_journal table
   - Link to approver/creator
```

**Adjustment Journal Schema**:
```php
class AdjustmentJournal {
    public string $account_uuid;
    public string $asset_code;
    public int $adjustment_amount; // can be negative
    public string $reason_category; // error, goodwill, regulatory, etc.
    public string $description;
    public string $created_by; // admin user
    public ?string $approved_by; // manager if required
    public ?string $supporting_document;
    public string $created_at;
}
```

---

#### 7.5.6 Reversal Management

```
1. Admin searches for transaction to reverse
2. Admin selects transaction
3. Admin enters reversal reason
4. System validates:
   - Transaction is reversible
   - Account not frozen
   - Sufficient balance for reversal (if debit reversal)
5. System creates reversal transaction
6. Original transaction marked as reversed
7. Both linked via parent_transaction_id
8. Audit trail recorded
```

**Reversal Types**:
- Full reversal - entire amount
- Partial reversal - portion of amount

---

### 7.6 Filament Resources Required

#### Phase 1: Fund Management Core (CRITICAL)

| Resource | Description | Effort |
|----------|-------------|--------|
| `FundManagement/FundAccountPage` | Single account funding | Medium |
| `FundManagement/BulkFundPage` | CSV bulk funding | Medium |
| `FundManagement/TransferBetweenAccountsPage` | Internal transfers | Medium |
| `FundManagement/AdjustBalancePage` | Manual adjustments | Medium |

#### Phase 2: Fund Operations Support

| Resource | Description | Effort |
|----------|-------------|--------|
| `FundManagement/TestFundingResource` | Funding history | Low |
| `FundManagement/AdjustmentJournalResource` | Adjustment audit | Low |
| `FundManagement/TreasuryPoolPage` | Treasury view | Low |
| `TransactionResource` (enhance) | Better transaction search | Medium |

---

### 7.7 Data Model Changes

#### New Models Required

```php
// app/Models/TestFunding.php
class TestFunding {
    public string $uuid;
    public string $account_uuid; // funded account
    public string $asset_code;
    public int $amount;
    public string $reason;
    public ?string $notes;
    public string $created_by;
    public string $created_at;
}

// app/Models/AdjustmentJournal.php
class AdjustmentJournal {
    public string $uuid;
    public string $account_uuid;
    public string $asset_code;
    public int $amount;
    public string $reason_category;
    public string $description;
    public string $created_by;
    public ?string $approved_by;
    public ?string $supporting_document;
    public string $created_at;
}

// app/Models/InternalTransfer.php
class InternalTransfer {
    public string $uuid;
    public string $source_account_uuid;
    public string $destination_account_uuid;
    public string $asset_code;
    public int $amount;
    public string $reason;
    public string $transaction_group_uuid;
    public string $created_by;
    public string $created_at;
}
```

---

### 7.8 Security & Compliance

#### Approval Workflows

| Amount Threshold | Requirement |
|-----------------|-------------|
| < $100 | No approval needed |
| $100 - $1,000 | Self-approved |
| $1,000 - $10,000 | Manager approval |
| > $10,000 | Director approval + audit log |

#### Audit Requirements

All fund operations must record:
- Who performed the action
- When it was performed
- What was changed (before/after)
- Why it was performed (reason/notes)
- Supporting documentation (if required)

---

## PART 8: Production Feature Parity

### 8.1 Non-Demo Mode = Production Features

The app is **NOT in demo mode** but should have all production features available for:
1. Internal testing
2. QA testing
3. UAT with stakeholders

### 8.2 Production Features to Enable

| Feature | Purpose | Status |
|---------|---------|--------|
| **Real Wallet Operations** | Test actual blockchain transactions | Available via Relayer |
| **Card Management** | Full card lifecycle | Needs CardResource |
| **TrustCert System** | KYC/AML compliance | Needs TrustLevelResource |
| **Fiat Ramp** | Buy/sell crypto | Needs RampSessionResource |
| **Privacy Shielding** | RAILGUN privacy | Needs PrivacyShieldResource |
| **Rewards System** | Gamification | Partial (needs admin UI) |
| **Referral Program** | User acquisition | Needs ReferralResource |

### 8.3 Test Environment Configuration

```php
// config/app.php
return [
    // Instead of DEMO_MODE flag
    'environment' => env('APP_ENVIRONMENT', 'production'),
    
    // When testing, use test funding instead of demo faucet
    'funding_mode' => env('FUNDING_MODE', 'treasury'), // 'treasury' | 'demo_faucet'
];
```

---

## PART 9: Mobile App Backend Integration

### 9.1 Overview

Mobile app at `/Users/Lihle/Development/Coding/maphapayrn` is a React Native + Expo app that communicates with this Laravel backend via `/api/*` endpoints. Many endpoints are stubs or need completion.

### 9.2 Mobile App API Status

Based on `walletDataSource.ts` and `usePockets.ts`:

| Endpoint | Status | Mobile Need |
|----------|--------|-------------|
| `GET /api/pockets` | **STUB** - Returns `[]` | Full CRUD needed |
| `POST /api/pockets/store` | **MISSING** | Create pocket |
| `POST /api/pockets/update/{id}` | **MISSING** | Update pocket |
| `POST /api/pockets/add-funds/{id}` | **MISSING** | Deposit to pocket |
| `POST /api/pockets/withdraw-funds/{id}` | **MISSING** | Withdraw from pocket |
| `POST /api/pockets/update-rules/{id}` | **MISSING** | Smart rules |
| `GET /api/dashboard` | Partial | User balance, profile |
| `GET /api/transactions` | Partial | Transaction history |
| `GET /api/budget` | Partial | Monthly budget |
| `GET /api/budget/categories` | Partial | Budget categories |
| `GET /api/wallet-linking` | Partial | Linked wallets |
| `GET /api/rewards` | **MOCK** | Rewards profile |
| `GET /api/cards` | **MOCK** | Card management |

### 9.3 Pockets Implementation Requirements

Mobile expects these fields per `usePockets.ts`:

```typescript
interface RawPocket {
  id: number;
  user_id: number;
  name: string;
  target_amount: string;        // Decimal as string
  current_amount: string;       // Decimal as string
  target_date: string | null;
  category: string | null;
  color: string | null;
  is_completed: boolean;
  smart_rules: RawSmartRules | null;
}

interface RawSmartRules {
  id: number;
  pocket_id: number;
  round_up_change: boolean;
  auto_save_deposits: boolean;
  auto_save_salary: boolean;
  lock_pocket: boolean;
}
```

**API Response Format**:
```json
{
  "remark": "pockets",
  "status": "success",
  "data": {
    "pockets": [...]
  }
}
```

### 9.4 Mobile Wallet Data Requirements

From `walletDataSource.ts`:

```typescript
interface WalletData {
  wallets: WalletAccount[];           // Main + linked wallets
  totalBalance: number;
  totalNetWorth: number;
  totalWalletBalance: number;
  totalSavingsBalance: number;
  totalIncome: number;
  totalExpenses: number;
  transactions: WalletTransaction[];
  recentTransactions: WalletTransaction[];
  expenseCategories: ExpenseCategory[];
  savingsGoals: SavingsGoal[];
  featuredSavingsGoal: SavingsGoal;
  savingsStatistics: SavingsStatistics;
  weeklyCashFlow: WeeklyCashFlow[];
  monthlyBudget: number | null;
  budgetCategoryLines: BudgetCategoryLineWithSpent[];
}
```

### 9.5 Backend Routes for Mobile

Located in `routes/api-compat.php`:
- `GET /api/dashboard` - DashboardController
- `GET /api/pockets` - PocketsController (STUB)
- `GET /api/transactions` - TransactionHistoryController
- `GET /api/budget` - BudgetController
- `GET /api/budget/categories` - BudgetCategoriesController
- `GET /api/wallet-linking` - WalletLinkingController

### 9.6 Mobile Backend TODO

1. **Implement PocketsController** - Full CRUD
2. **Implement Savings Domain** - If not exists
3. **Wire up Dashboard** - User balance data
4. **Implement Card Management** - For mobile
5. **Implement Rewards** - Replace mock store
6. **Add Savings Pockets to Admin** - Admin management

---

## PART 10: Summary

### Current State
- 41 Filament resources
- Significant mobile feature gaps
- Missing comprehensive fund management
- Mobile app has stubs for many endpoints

### Mobile App Backend Needs
1. **Pockets (SAVINGS)** - Full implementation needed
2. **Dashboard** - Complete user data
3. **Cards** - Real backend for card management
4. **Rewards** - Replace mock store

### Missing (High Priority - Backoffice)
1. **Fund Management System** (NEW - CRITICAL)
   - Test funding via treasury pool
   - Multi-currency operations
   - Account transfers
   - Manual adjustments
   - Reversal management
   - Reconciliation tools

2. Card management (CardResource)
3. TrustCert/TrustLevel management
4. Ramp session monitoring
5. User wallet consolidation
6. **Savings Pockets Admin** - For managing user pockets
7. Privacy shield visibility
8. Referral management
9. Paymaster/UserOps visibility

### Deprecate/Remove
- Foodo (unrelated demo)
- Other unused features

### Estimated Work
- **Mobile Backend (Pockets)**: ~1 week
- **Fund Management System**: ~2-3 weeks (new)
- Phase 1 (Critical): 4 resources, ~2 weeks
- Phase 2 (Important): 4 resources, ~2 weeks
- Phase 3 (Enhancement): 4 resources, ~2 weeks

### Total: ~9-11 weeks for full implementation

---

## Appendix: Related Documentation

- [Admin Dashboard Documentation](./14-TECHNICAL/ADMIN_DASHBOARD.md)
- [Mobile Launch Handover](./MOBILE_LAUNCH_HANDOVER.md)
- [Backend Handoff](./BACKEND_HANDOFF.md)
