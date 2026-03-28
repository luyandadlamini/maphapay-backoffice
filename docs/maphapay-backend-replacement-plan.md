# MaphaPay Backend Replacement Plan (to FinAegis)

This document describes a production-grade migration plan to replace the legacy MaphaPay backend with the FinAegis backend, while:

- Keeping the mobile app as the primary UX contract (screens/flows preserved) by implementing **FinAegis-side compatibility endpoints** that match the legacy mobile request/response contracts, while delegating all money movement to FinAegis primitives (not redesigning the FinAegis wallet engine to match the app).
- Localizing all monetary currency handling from USD semantics to SZL semantics (Swazi Lilangeni, display prefix `E`, 2 decimals).
- Ensuring financial integrity for wallet movements: precision correctness, idempotency/duplicate protection, auditable event history, and consistent read-model projections.

Architecture sketch:

```mermaid
flowchart LR
  OldBackend[Old MaphaPay Backend] -->|"Current REST API"| Mobile[Mobile App API Client]
  Mobile -->|"Mobile adapts"| FinAegis[FinAegis Backend APIs]
  FinAegis --> Ledger[Ledger + Double-Entry Integrity (target)]
  FinAegis --> Wallet[Wallet Service + Transaction Engine]
  FinAegis --> MoMo[MTN MoMo Integration + Callbacks]
```

## Non-negotiable migration invariants (make the plan “airtight”)

These are the rules that prevent silent double-credit/double-debit, idempotency false mismatches, and cutover ambiguity. Every phase below assumes these invariants are enforced.

### A) Canonical amount contract (major-units string in API, deterministic minor-units in ledger)

1. **Incoming request amounts**
   - All mobile-facing compatibility endpoints that accept an amount MUST accept `amount` as a **string** in **major units** with a fixed decimal format for the asset precision (SZL=2), e.g. `"25.10"`.
   - Do not accept `numeric` floats for money-moving endpoints; floats cause rounding drift and can break idempotency request-body equality checks.

2. **Ledger mutation amounts**
   - Convert major-units strings into **minor units** deterministically using asset precision (SZL precision=2) with bcmath (or a deterministic decimal parser).
   - Wallet engine/workflows operate on minor units only.

3. **Outgoing response amounts**
   - Any response field named `amount` MUST be major units (string) consistently across create/show/history endpoints.
   - If the client needs minor units, add a separate explicit field name (e.g. `amount_minor_units`)—do not overload `amount`.

### B) Idempotency is a financial guarantee (not just HTTP response caching)

1. **HTTP idempotency middleware is helpful but insufficient**
   - FinAegis `IdempotencyMiddleware` caches 2xx responses for 24h; that is not a correctness guarantee beyond the cache TTL.
   - The legacy backend’s API idempotency helper (`maphapay-backend/core/app/Traits/ApiIdempotency.php`) caches successful responses for **48 hours** and uses a short lock window to reject concurrent identical keys. That still does not provide a permanent “exactly once money movement” guarantee.

2. **Domain-level operation idempotency is required for money-moving side effects**
   - For every wallet mutation initiated by a compatibility endpoint (send money finalize, request-money accept finalize, scheduled send execution, MTN credit on success, MTN debit initiation), persist an “operation record” keyed by:
     - `(user_id, operation_type, idempotency_key)` and the normalized request payload hash.
   - Before applying side effects, the handler MUST check if the operation record is already completed and return the same logical result without reapplying the mutation.

3. **Idempotency mismatch behavior**
   - If the same idempotency key is reused with a different normalized payload for the same operation type, return a deterministic conflict error (409-style) with a stable error envelope.

### C) Single-writer rule during cutover (feature-by-feature)

At any moment in time, for each “money-moving feature family” (send money, request acceptance, MTN collection/disbursement settlement), exactly one backend is allowed to execute wallet mutations for the cohort.

- Compatibility endpoints must be gated by flags that control BOTH:
  - route enablement, and
  - side-effect enablement (write vs read-only mode), especially for MTN callbacks and reconciliation.

### D) MTN settlement must be “exactly once” under callback + polling + reconciliation concurrency

1. **State machine**
   - MTN transaction rows must enforce a strict state machine, e.g.:
     - `PENDING -> SUCCESSFUL | FAILED | EXPIRED | CANCELLED`
   - Only one transition into a terminal success state may trigger a wallet credit (collection) or the finalization marker (disbursement).

2. **Atomic credit/debit guards**
   - Persist a guard field (e.g. `credited_at`, optionally `debited_at`) and enforce it atomically (single statement / transaction) so concurrent workers cannot both credit.
   - Callback, polling, and reconciliation must be safe under repeated execution and concurrent execution.
   - In the legacy backend, both **status polling** and the **IPN callback** can credit a successful collection (see `maphapay-backend/core/app/Http/Controllers/Api/MtnMomoController.php::status()` and `maphapay-backend/core/app/Http/Controllers/Gateway/MtnMomo/CallbackController.php`). The FinAegis replacement must avoid this dual-credit risk by ensuring exactly one settlement path (or both paths share a single atomic credit guard).

### E) Backfill semantics must not look like user actions

Stage 1 balance backfill MUST use a migration-specific operation type/reference/metadata so:
- fees/limits/risk rules are not applied,
- notifications are not emitted (or are filtered),
- audit logs clearly indicate “migration seed”.

### F) Tenancy and identity mapping is a prerequisite

FinAegis is multi-tenant/team isolated. Before enabling any write endpoint:
- define and persist a mapping from legacy user identity to:
  - tenant/team scope, and
  - FinAegis wallet/account UUIDs,
- ensure all reads/writes are scoped by tenant and authenticated user.

## Phase 1: FinAegis Deep Analysis

### 1) FinAegis system strengths to preserve (based on implementation)

1. Event-sourced domain state transitions via Spatie aggregates and stored events.
   - Events intended for storage use Spatie's `ShouldBeStored` (example: `app/Domain/Account/Events/MoneyAdded.php`, `app/Domain/Account/Events/AssetBalanceAdded.php`).
   - Aggregates centralize mutation using `AggregateRoot::recordThat(...)` and `persist()` / snapshot repositories:
     - `app/Domain/Account/Aggregates/AssetTransactionAggregate.php`
     - `app/Domain/Account/Aggregates/TransactionAggregate.php`
     - `app/Domain/Account/Aggregates/TransferAggregate.php`
     - `app/Domain/Account/Aggregates/LedgerAggregate.php`

2. Workflow-based orchestration for multi-step operations with compensation.
   - Example transfer workflow (wallet transfer with withdraw+deposit and compensations):
     - `app/Domain/Wallet/Workflows/WalletTransferWorkflow.php`
   - Example asset transfer workflow delegating to wallet transfers:
     - `app/Domain/Asset/Workflows/AssetTransferWorkflow.php`
   - Deposit/withdraw workflows are thin wrappers around activities:
     - `app/Domain/Account/Workflows/DepositAccountWorkflow.php`
     - `app/Domain/Account/Workflows/WithdrawAccountWorkflow.php`

3. Idempotency infrastructure at the HTTP layer for critical POST/PUT/PATCH endpoints.
   - Global middleware:
     - `app/Http/Middleware/IdempotencyMiddleware.php`
   - It:
     - reads an idempotency key from the `Idempotency-Key` header,
     - caches successful 2xx responses for 24 hours,
     - uses a cache lock (30s) to reject concurrent identical keys.

4. Precision-aware asset model design (per-asset `precision` field).
   - `app/Domain/Asset/Models/Asset.php` defines:
     - `$precision` (decimal places),
     - conversion and formatting helpers:
       - `formatAmount(int $amount): string` (smallest unit -> display with asset precision)
       - `toSmallestUnit(float $amount): int` (display decimal -> smallest unit)
       - `fromSmallestUnit(int $amount): float` (smallest unit -> display decimal)

5. Cache invalidation and projection mechanics.
   - Example projector invalidating cached balances after events:
     - `app/Domain/Account/Projectors/AssetBalanceProjector.php`
     - invalidates via `app/Domain/Account/Services/Cache/CacheManager.php` (called in projector)

### 2) FinAegis limitations / risks to address during migration

1. Inconsistent amount formatting assumptions in some read models.
   - `app/Domain/Account/Models/TransactionProjection.php` hard-codes `getFormattedAmountAttribute()` as `number_format($this->amount / 100, 2)`.
   - Risk: if the system later supports non-2-decimal assets (or localization away from USD-centric cent semantics), this formatter must be updated to use the asset's `precision` or a derived divisor.

2. Idempotency header naming consistency must be validated across endpoints.
   - Middleware expects `Idempotency-Key` (`app/Http/Middleware/IdempotencyMiddleware.php`).
   - Some controllers document and accept `X-Idempotency-Key` (example):
     - `app/Http/Controllers/Api/MobilePayment/PaymentIntentController.php` (OpenAPI and logic checks `X-Idempotency-Key`).
   - Migration risk: the mobile app may send one header name; the middleware may only recognize another.

3. Hash-chain integrity is enforced in some aggregates but appears incomplete/placeholder for asset aggregates.
   - `app/Domain/Account/Utils/ValidatesHash.php` provides a chaining model using `currentHash` and `Money` amount.
   - `TransactionAggregate` uses the trait to validate (`applyMoneyAdded`, `applyMoneySubtracted`):
     - `app/Domain/Account/Aggregates/TransactionAggregate.php`
   - `AssetTransactionAggregate` uses `validateHashForAsset(...)` but the current implementation is a format check placeholder (verifies hash exists and length, but does not recompute the chain).
     - `app/Domain/Account/Aggregates/AssetTransactionAggregate.php` (`validateHashForAsset`)
   - Migration risk: ledger integrity guarantees must be reviewed end-to-end (including asset transfer/projector read models).

4. Atomicity across multi-step wallet operations must be treated carefully.
   - `WalletOperationsService` starts workflow stubs (`WalletTransferWorkflow`, `WalletWithdrawWorkflow`, `WalletDepositWorkflow`).
   - `WalletTransferWorkflow` compensates on failure but success/failure boundaries still need careful mapping for:
     - MTN asynchronous callbacks,
     - mobile idempotency replay,
     - read-model projection lags.

### 3) Money representation and precision model (what “amount” means)

FinAegis uses a mixture of:

- Smallest-unit integer amounts for wallet ledger movement (especially in account aggregates and asset formatting).
- Optional per-asset decimal precision for display and conversion.
- Contract-level guidance that some domain interfaces expect amounts as strings for precision via bcmath.

Concretely:

1. Smallest-unit movement amounts
   - Money value object stores an integer amount:
     - `app/Domain/Account/DataObjects/Money.php`
       - `__construct(private int $amount)`
       - `getAmount(): int`
   - Transaction aggregates mutate state using `Money->getAmount()` (atomic units):
     - `app/Domain/Account/Aggregates/TransactionAggregate.php`

2. Per-asset precision for conversion and formatting
   - `app/Domain/Asset/Models/Asset.php` defines:
     - `precision` = decimal places used for asset display/rounding.
     - `formatAmount(int $amount): string`:
       - divisor `10 ** $this->precision`
       - display decimal = `$amount / $divisor`, formatted to `$this->precision` decimals.
     - `toSmallestUnit(float $amount): int`:
       - returns `(int) round($amount * (10 ** $this->precision))`
     - `fromSmallestUnit(int $amount): float`:
       - returns `$amount / (10 ** $this->precision)`

3. Precision-aware API contracts for asset/wallet operations (string precision guidance)
   - `app/Domain/Shared/Contracts/AssetTransferInterface.php` states:
     - amounts are strings for precision and that bcmath should be used.
   - `app/Domain/Shared/Contracts/WalletOperationsInterface.php` similarly states:
     - all amounts are strings for precision and IDs are UUID strings.

4. Endpoint-level expectation mismatch risk (cents vs smallest-unit)
   - Some HTTP controllers explicitly document smallest-unit/cents expectations:
     - `app/Http/Controllers/Api/TransferController.php` documents `amount` as integer with example “Amount in cents”.
   - Meanwhile some domain contracts say “amounts are strings for precision”.
   - Phase 3 must reconcile these into a single canonical interpretation for SZL (2 decimals => smallest unit = integer cents).

**Hard decision for this migration (to avoid subtle rounding/idempotency bugs):**
- Compatibility endpoints MUST accept **major-unit amounts as strings** (see invariants §A) and convert deterministically to minor units.
- Do not rely on PHP float arithmetic (`numeric`) for any money-moving path.

### 4) Transaction lifecycle (from API call to persisted events + read model)

For the migration scope, “wallet transfer / transfer lifecycle” is the critical path because it underpins:

- internal wallet movement used by send-money,
- wallet linking funds movements (e.g., MTN disbursement/collection),
- any bill-split settlements that require actual money transfers.

The concrete lifecycle in FinAegis (example path):

1. HTTP entry point
   - Transfer example controller:
     - `app/Http/Controllers/Api/TransferController.php`
   - (This controller is where request validation and conversion into domain workflow inputs must align with SZL amount semantics.)

2. Workflow orchestration
   - Wallet transfers:
     - `app/Domain/Wallet/Workflows/WalletTransferWorkflow.php`
       - Step 1: withdraw from source (`WalletWithdrawWorkflow::class`)
       - Compensation: re-deposit to source if deposit fails
       - Step 2: deposit to destination (`WalletDepositWorkflow::class`)
       - Compensation: withdraw from destination if further steps fail
       - Uses `try/catch` + `yield from $this->compensate()`

3. Domain operations layer
   - `app/Domain/Wallet/Services/WalletOperationsService.php` implements `WalletOperationsInterface`.
   - It:
     - validates inputs using `FinancialInputValidator` (UUID/assetCode/amount/reference/metadata),
     - records audit events via `AuditLogger` (`auditOperationStart`/`auditOperationSuccess`/`auditOperationFailure`),
     - starts workflows using `WorkflowStub::make(...)->start(...)`.

4. Event-sourced mutation + projection
   - Account aggregate(s) store balance changes via events:
     - `AssetTransactionAggregate` records:
       - `AssetBalanceAdded` on credit
       - `AssetBalanceSubtracted` on debit
       - state stored as `$balances[$assetCode]` in atomic units
     - `app/Domain/Account/Projectors/AssetBalanceProjector.php` updates read models/cache after events:
       - calls `CreditAssetBalance` / `DebitAssetBalance` actions
       - invalidates cache via `CacheManager`

### 5) Idempotency and integrity mechanisms

1. HTTP-layer idempotency replay (global)
   - Middleware:
     - `app/Http/Middleware/IdempotencyMiddleware.php`
   - Important behavior:
     - applies to POST/PUT/PATCH only,
     - uses header `Idempotency-Key`,
     - caches only successful (2xx) JSON responses,
     - rejects mismatched request bodies with the same key (409),
     - sets response headers:
       - `X-Idempotency-Key` and `X-Idempotency-Replayed`.

2. Domain aggregate-level hash chaining (integrity)
   - `Hash` VO:
     - `app/Domain/Account/DataObjects/Hash.php`
     - validates SHA3-512 hex string length (128 chars).
   - Hash chaining trait:
     - `app/Domain/Account/Utils/ValidatesHash.php`
     - `generateHash()` uses:
       - `currentHash` + optional Money amount
   - TransactionAggregate uses chain validation:
     - `app/Domain/Account/Aggregates/TransactionAggregate.php`

### 6) Phase 1 implementation checklist (must-do before Phase 3 currency localization)

1. Confirm the canonical unit for mobile-sent `amount` for each FinAegis endpoint used by:
   - internal transfers (`send-money`),
   - MTN wallet linking flows,
   - bill-split settlement flows.
   - Compare controller validation and domain interfaces:
     - `app/Http/Controllers/Api/TransferController.php`
     - `app/Domain/Wallet/Services/WalletOperationsService.php`
     - `app/Domain/Shared/Contracts/WalletOperationsInterface.php`

2. Identify every read-model formatter that assumes “2 decimals / cents”.
   - Starting point:
     - `app/Domain/Account/Models/TransactionProjection.php::getFormattedAmountAttribute()`

3. Standardize idempotency header naming across:
   - mobile app requests,
   - FinAegis idempotency middleware,
   - any controller-specific idempotency parsing.
   - Starting points:
     - `app/Http/Middleware/IdempotencyMiddleware.php`
     - `app/Http/Controllers/Api/MobilePayment/PaymentIntentController.php`

4. Validate integrity model boundaries:
   - Determine whether hash chaining guarantees apply uniformly across all relevant aggregates:
     - `TransactionAggregate` (uses trait validation)
     - `AssetTransactionAggregate` (currently format-check placeholder)

5. Confirm where `precision` is sourced for fiat assets and how defaults are set:
   - `app/Domain/Asset/Models/Asset.php` (`precision` field and conversion helpers)
   - governance / asset registration workflows (for default fiat precision):
     - `app/Domain/Governance/Workflows/AddAssetWorkflow.php` (contains fiat `precision => 2` examples)

### 7) Output of Phase 1 (for the rest of this migration document)

This Phase 1 analysis yields the following constraints for Phase 3 (USD -> SZL):

- For SZL, smallest unit should be “cents” (2 decimals) consistently across:
  - API validation,
  - wallet operations,
  - projections/read models used by mobile.
- Any USD- or cents-hard-coded display/format logic must be identified and replaced with `Asset.precision`-based conversion.
- Idempotency must be uniform: mobile must send whatever header FinAegis middleware expects, or middleware must accept mobile’s header.

## Phase 2: Gap Analysis (reuse/extend/build)

This table lists the MaphaPay backend capabilities required by the mobile app, then maps each one to the closest existing FinAegis primitive (or marks it as a new bounded-context build).

**Important scope note (to avoid hallucinated “source of truth”):**
- The legacy backend (`maphapay-backend/...`) and the mobile app (`maphapayrn/...`) are **not present in this repository**.
- Any file paths under `maphapay-backend/` or `maphapayrn/` below are **external references** that must be verified against those repos (ideally pinned to a commit SHA or link) before implementation.

Legend:

- `Reuse` = FinAegis already has the core engine and we mainly add a thin HTTP adapter.
- `Extend` = FinAegis has the core engine but we must add missing pieces (validation, payload mapping, charge/limit logic, settlement hooks).
- `Build` = FinAegis is missing the domain concept entirely; we must implement it as new aggregates/models/read-models + HTTP endpoints.

| MaphaPay feature (mobile user flow) | Legacy endpoints / controllers (source of truth) | FinAegis status | FinAegis anchor code to reuse/extend | What must be built/extended to match MaphaPay |
|---|---|---|---|---|
| P2P “Send Money” (text-to-user transfer, with TX reference history) | Legacy reference (verifiable locally): `maphapay-backend/core/routes/api/api.php` registers `POST /api/send-money/store` and uses `maphapay-backend/core/app/Traits/SendMoneyOperation.php` (via `SendMoneyController`). Legacy send money uses `ApiIdempotency` (`maphapay-backend/core/app/Traits/ApiIdempotency.php`) and writes an authorized transaction via `storeAuthorizedTransactionData('send_money', $details)`. | Extend | **Do not treat `app/Http/Controllers/Api/TransferController.php` as a reliable anchor** (it is internally inconsistent and currently returns an ID that cannot be used to fetch the created aggregate/event history). Use FinAegis wallet workflows/services as anchors: `app/Domain/Wallet/Workflows/WalletTransferWorkflow.php` + `app/Domain/Wallet/Services/WalletOperationsService.php`. | Create a MaphaPay-compatible “send-money” HTTP adapter that: (1) resolves `from/to` to FinAegis `AccountUuid`s, (2) converts amount semantics to SZL semantics using canonical **major-unit strings** -> deterministic minor units, (3) re-implements charge/limit validation from legacy `SendMoneyOperation` (min/max, daily/monthly, fees/cap), and (4) preserves replay safety using **domain-level idempotency** (see invariants §B) in addition to HTTP middleware. |
| P2P “Request Money” (create request, accept/receive, reject) | Routes are under `/api/request-money/...` in `maphapay-backend/core/routes/api/api.php`; controller `maphapay-backend/core/app/Http/Controllers/Api/RequestMoneyController.php` (logic lives in `maphapay-backend/core/app/Traits/RequestMoneyOperation.php`) | Build | Settlement can reuse FinAegis transfer primitives (`TransferController` + `WalletTransferWorkflow`) | Build a new “money request” bounded context: (1) persistent request state (pending/approved/rejected/cancelled), (2) limits/fees logic from `RequestMoneyOperation`, (3) acceptance flow that triggers settlement via FinAegis transfer engine, (4) API payload mapping for the mobile request lifecycle. |
| Social Money hub: `summary`, `friends`, `threads`, `messages` | `maphapay-backend/core/app/Http/Controllers/Api/SocialMoneyController.php` (endpoints under `/api/social-money/...`) | Build | Payment settlement lines can reuse FinAegis transfer primitives; read models are missing | Implement a new Social domain in FinAegis that stores: (1) accepted friendships (or reuse existing “friendship” table if already present in FinAegis; current FinAegis repo has no `ChatMessage`/`FriendRequest` equivalents), (2) threads + message types (text/payment/request/bill_split), (3) summary projections for unread counts and pending bill splits. Use `SocialMoneyController` as reference for payload shapes and state transitions. |
| Social Money message sending: `send` + “payment/request” message types | Legacy endpoints: `/api/social-money/send`, `/send-payment-message`, `/send-request-message`, `/amend-request-message`, `/decline-request-message`, `/cancel-request-message` in `SocialMoneyController` | Build | Reuse FinAegis transfer engine only when recording payment completion (ledger movement exists separately) | Implement message creation/updating, including: (1) friendship gating, (2) idempotency for “record payment message” using `trx` reference (legacy checks `ChatMessage::where('source_trx', $sendMoney->trx')`), and (3) state updates for request messages (pending -> paid/declined/cancelled). |
| Bill Split: `send-bill-split` + `mark-paid` | Legacy endpoints: `/api/social-money/send-bill-split` and `/api/social-money/mark-paid` in `SocialMoneyController` | Build | Reuse FinAegis transfer engine only if bill split settlement must move actual funds | Implement bill split state: (1) `BillSplit` object with participants and per-user amounts/status, (2) notification/broadcast behavior can be approximated with standard cache invalidation + polling APIs (depending on mobile expectations), and (3) “mark-paid” semantics must either (a) trigger settlement transactions in FinAegis or (b) stay metadata-only if legacy already uses another settlement path. |
| Wallet Linking: MTN MoMo `is-account-active`, `link`, `index`, `unlink` | Legacy: `maphapay-backend/core/app/Http/Controllers/Api/WalletLinkingController.php` endpoints under `/api/wallet-linking/mtn-momo/...` and `/api/wallet-linking/generic/link` | Build | FinAegis has no linked-wallet domain in `app/` (no `LinkedWallet`/`MtnMomoTransaction` equivalents). | Build `LinkedWallet` domain + API: (1) MTN MSISDN activity check integration, (2) persistence of linked-wallet records, (3) currency association for the linked wallet (legacy defaults to `SZL`), and (4) idempotency/duplicate prevention (`LinkedWallet::updateOrCreate` behavior from legacy). Start by porting controller logic from `WalletLinkingController.php` and adapting settlement calls to FinAegis wallet primitives. |
| Top-up / Collection: MTN MoMo `request-to-pay` | Legacy: `maphapay-backend/core/app/Http/Controllers/Api/MtnMomoController.php::requestToPay` at `/api/wallet-transfer/mtn-momo/request-to-pay` | Build | FinAegis provides deposit/credit engine via wallet workflows (`WalletOperationsService::deposit`, `WalletDepositWorkflow`) | Build MTN MoMo collection integration: (1) create a transaction record with legacy semantics (`idempotency_key`, `mtn_reference_id`), (2) enforce PIN rules if needed (legacy uses `requireValidTransactionPin`), (3) on MTN callback success, credit the user via FinAegis deposit/credit workflow (not direct SQL balance update). Also unify idempotency: legacy checks DB for idempotent requests; FinAegis also caches HTTP responses via `IdempotencyMiddleware` (header `Idempotency-Key`). |
| Send Money / Disbursement: MTN MoMo `disbursement` | Legacy: `maphapay-backend/core/app/Http/Controllers/Api/MtnMomoController.php::disbursement` at `/api/wallet-transfer/mtn-momo/disbursement` | Build | FinAegis provides withdraw/debit engine via wallet workflows (`WalletOperationsService::withdraw`, `WalletWithdrawWorkflow`) | Build disbursement integration: (1) lock/debit source balance atomically using FinAegis workflows, (2) trigger MTN transfer, (3) on MTN failure, reverse the workflow via compensation (legacy does DB increment rollback), and (4) ensure polling returns terminal states identical enough for the mobile UI. |
| Async status polling for MTN: `status/{idempotencyKey}` | Legacy: `MtnMomoController::status` at `/api/wallet-transfer/mtn-momo/status/{idempotencyKey}` | Build | FinAegis needs an MTN transaction read model | Build MTN status polling: (1) query MTN using stored `mtn_reference_id`, (2) map MTN statuses -> MaphaPay statuses, (3) update transaction record, (4) credit/debit via FinAegis workflows only on terminal success, and (5) return payload shape compatible with `maphapayrn/src/features/wallet/hooks/useMtnMomoTransfer.ts`. |
| MTN IPN callback: `/ipn/mtn-momo` (signature verification + idempotent update) | Legacy callback: `maphapay-backend/core/app/Http/Controllers/Gateway/MtnMomo/CallbackController.php` | Build | FinAegis has no IPN controllers for MoMo; create one | Port the callback controller and adapt: (1) signature verification based on `mtn_momo.php`, (2) locate transaction by `X-Reference-Id` (`mtn_reference_id`) with fallback idempotency key lookup, (3) update transaction record idempotently and credit via FinAegis deposit workflow, (4) respond `200` with empty body to prevent retries. |
| Reconciliation / backfill for MTN pending transactions | Legacy command: `maphapay-backend/core/app/Console/Commands/ReconcileMtnMomoTransactions.php` (polls pending txs and credits on success) | Build | FinAegis scheduled tasks / console commands | Implement the same reconciliation strategy in FinAegis: create a console command mirroring `ReconcileMtnMomoTransactions` but calling FinAegis workflows/services instead of updating legacy `balance` directly. |

### Phase 2 “integration contract” findings (to drive Phase 3-5)

1. FinAegis HTTP-layer idempotency is keyed by `Idempotency-Key` (`app/Http/Middleware/IdempotencyMiddleware.php`).
2. Legacy MTN endpoints are idempotent by `idempotency_key` in the *request body* (`MtnMomoController`), and also locate records by `idempotency_key`.
3. FinAegis transaction amount formatting still contains assumptions like `amount / 100` in read models (`TransactionProjection::getFormattedAmountAttribute()`), so the Phase 3 SZL localization must make “precision divisor” asset-driven rather than a hard-coded `100`.

## Phase 3: Currency Localization (USD -> SZL) dependency map

Goal: remove USD-only assumptions in the MaphaPay replacement APIs so the mobile app always receives SZL amounts in consistent “major units” (two decimals) and displays them with its `E` prefix convention.

### 3.1 Seeders / asset definitions (what “SZL” must become)

FinAegis fiat assets are seeded by `database/seeders/AssetSeeder.php`, which currently seeds `USD` (precision 2) and metadata symbol `$`, but there is no `SZL` asset at all.

Change list:

1. Add `SZL` to `database/seeders/AssetSeeder.php`
   - Mirror `precision => 2` (like USD)
   - Set `metadata` symbol to match MaphaPay display convention:
     - If API responses return formatted strings and you want backend to include the `E` prefix, store `metadata: ['symbol' => 'E']`.
     - If API responses should return raw numbers (recommended, because mobile already prefixes `E`), backend should still store the correct symbol for any “formatted” fields, but primary correctness is about amount units.

2. (Optional / future) Update basket-related seeders only if MaphaPay flows use basket/base-currency logic
   - `database/seeders/PrimaryBasketSeeder.php` and `app/Filament/Admin/Widgets/PrimaryBasketWidget.php` include `USD` component weights.
   - For a MaphaPay replacement focused on wallet/transfer APIs, keep basket logic unchanged unless you confirm mobile uses basket-derived numbers.

Code to treat as a dependency root:

- `database/seeders/AssetSeeder.php`
- `app/Domain/Asset/Models/Asset.php` (supports per-asset precision, but note: `toSmallestUnit(float)` is float-based; for idempotency-critical paths you must introduce a deterministic string-based converter)

### 3.2 Schema defaults (currency columns that may affect API responses)

Hard-coded default currency appears in multiple migrations. Not all of them affect the MaphaPay wallet flows, but they should be reviewed because they can leak into:

- “display currency” fields in API responses,
- card/partner/invoice style surfaces that may appear in the mobile app.

Examples found in migrations/factories:

- `database/migrations/2026_03_17_000002_create_cards_table.php` (cards default currency `USD`)
- `database/migrations/tenant/0001_01_01_000013_create_tenant_cgo_tables.php` (tenant cgo default currency `USD`)
- `database/migrations/2025_07_06_143527_create_loan_collaterals_table.php` (loan collaterals default currency `USD`)
- `database/migrations/2026_02_01_100003_create_partner_invoices_table.php` (partner invoices `display_currency` default `USD`)
- `database/migrations/2026_03_23_100002_create_mpp_monetized_resources_table.php` (mpp monetized resources default currency `USD`)
- `.env.*` + config defaults:
  - `.env.example`, `.env.demo`, `.env.production.example`, `.env.zelta.example`, `DEMO_DEFAULT_CURRENCY=USD`
  - `config/machinepay.php` -> `default_currency` default `USD`
  - `config/agent_protocol.php` -> `default_currency` default `USD`

Recommendation (scoping):

- For MaphaPay replacement APIs, prefer changing *only* what powers wallet/account/transfer display + reconciliation.
- If you change global defaults like `DEMO_DEFAULT_CURRENCY`, validate all non-MaphaPay domains that use those defaults (GCU/baskets/treasury) so you do not create hidden regressions.

### 3.3 Business logic / formatting dependencies (remove fixed “cents => /100” assumptions)

These are the concrete “precision divisor” breakpoints we found. For SZL (precision=2) `/100` is correct *only by coincidence*. Production-grade behavior requires using `asset.precision`, not hard-coded cents divisors.

1. Transaction amount display accessor (hard-coded divisor)
   - `app/Domain/Account/Models/TransactionProjection.php::getFormattedAmountAttribute()`
     - currently does `number_format($this->amount / 100, 2)`
     - fix: compute divisor via `asset_code` -> `Asset.precision` (or drop the accessor if API should return raw amounts only).

2. Transaction reversal history (hard-coded cents conversion)
   - `app/Http/Controllers/Api/TransactionReversalController.php::getReversalHistory()`
     - currently does `amount => $transaction->amount / 100`
     - fix: use `$transaction->asset_code` -> asset precision conversion (or compute major units using `Asset::fromSmallestUnit()`).

3. Account balance endpoint “USD equivalent” (USD-only summary)
   - `app/Http/Controllers/Api/AccountBalanceController.php`
     - `calculateUsdEquivalent()` filters `asset_code === 'USD'` and uses `/100`
     - fix options:
       - If mobile MaphaPay flows only need `SZL` totals, rename to `total_szl_equivalent` and compute SZL (or remove the USD equivalent field entirely).
       - If you keep USD totals for analytics, ensure conversion uses `ExchangeRateService`, not a USD-only `/100` assumption.

4. Transaction history endpoint still defaults legacy events to `asset_code = 'USD'`
   - `app/Http/Controllers/Api/TransactionController.php::history()`
     - sets:
       - `asset_code` default `'USD'` for `MoneyAdded`/`MoneySubtracted`
       - and `'USD'` for legacy event parsing branches
     - fix: for MaphaPay flows, ensure all persisted events include correct `asset_code`/`fromAsset`/`money` context and the transformer does not hard-code `'USD'`.

5. Deposit/withdraw special-cases “USD workflow” by asset_code literal
   - `app/Http/Controllers/Api/TransactionController.php::deposit()` and `::withdraw()`
     - currently checks `if ($validated['asset_code'] === 'USD')` and uses `DepositAccountWorkflow`/`WithdrawAccountWorkflow` legacy Money flow
     - fix: remove special-casing or include SZL in the “legacy/simple” bucket only if that legacy aggregate supports it safely; otherwise use the multi-asset workflow (`app/Domain/Asset/Workflows/AssetDepositWorkflow.php` / `AssetWithdrawWorkflow.php`) for SZL.

6. Transfer create endpoint: amount units contract + response inconsistency
   - `app/Http/Controllers/Api/TransferController.php::store()`
     - converts request amount using `10 ** $asset->precision` and starts `WalletTransferWorkflow`
     - but the OA block currently says “Amount in cents” while validation accepts numeric decimals
     - response `data.amount` echoes the request amount (`$validated['amount']`) (major units), while `show()/history()` read `money.amount` directly from event properties (smallest units)
     - fix required for consistency:
       - Ensure every response field named `amount` is always major-unit SZL (2 decimals) for MaphaPay mobile flows.

Key code locations:

- `app/Domain/Account/Models/TransactionProjection.php`
- `app/Http/Controllers/Api/TransferController.php`
- `app/Http/Controllers/Api/AccountBalanceController.php`
- `app/Http/Controllers/Api/TransactionController.php`
- `app/Http/Controllers/Api/TransactionReversalController.php`
- `app/Domain/Asset/Models/Asset.php` (use its `precision` conversion methods)

### 3.4 Canonical API response contract for SZL (must be consistent end-to-end)

To align with the mobile app (`formatCurrency()` + `roundToSZL()`), MaphaPay replacement APIs should follow:

1. Amount fields in JSON responses:
   - represent “major units” for the requested asset (SZL -> two decimals) as **strings** (e.g. `"25.10"`) to preserve determinism and avoid float serialization differences.
   - must not be “smallest unit integers” unless the field name makes that explicit (e.g., `amount_minor_units`)

2. Currency representation:
   - keep `asset_code: 'SZL'` in responses so the UI can pick precision/format rules.
   - avoid returning `E` prefixed strings unless you have confirmed the mobile UI uses them as-is (mobile already prefixes `E` in `maphapayrn/src/utils/currency.ts`).

Mobile contract reference:

- `maphapayrn/src/utils/currency.ts`
  - `formatCurrency(amount)` => `E` + `Intl.NumberFormat('en-US', 2 decimals)`
  - `roundToSZL(amount)` => `Math.round(amount * 100) / 100` (explicit 2-decimal rounding before sending to APIs)

### 3.5 Rounding + precision risk analysis (what can break)

Main rounding risks we must explicitly guard:

1. Float rounding mismatch (client vs server)
   - Mobile uses `roundToSZL()` before sending (good), but the server currently validates `amount` as `numeric` and multiplies by `10 ** $precision` using PHP float arithmetic:
     - `app/Http/Controllers/Api/TransferController.php` and `app/Http/Controllers/Api/TransactionController.php`
   - Risk: `25.1`/`25.10` style float serialization differences can cause minor-unit rounding drift and idempotency mismatches.
   - Mitigation (non-negotiable for migration correctness):
     - compatibility endpoints accept `amount` as **string** and convert with deterministic decimal parsing/bcmath (see invariants §A).
     - Do not use `Asset::toSmallestUnit(float $amount)` for idempotency-critical paths; it is float-based.

Additional formatting warning:
- `Asset::formatAmount(int $amount)` is presentation-oriented and may include symbols and thousands separators. Do not use it to produce canonical API amount fields for mobile. Prefer returning numeric strings + `asset_code` separately.

2. Read-model formatting hard-codes divisor
   - `TransactionProjection::getFormattedAmountAttribute()` uses `/100`.
   - Fix is required even if SZL precision is 2, because:
     - it can break when other fiat assets are enabled,
     - it can break if SZL precision is ever changed.

3. Idempotency key matching vs request-body serialization
   - FinAegis `app/Http/Middleware/IdempotencyMiddleware.php` caches based on `json_encode($request->all())`.
   - Risk: if “amount” is serialized with a different float representation across retries, the request body comparison may fail (409).
   - Mitigation:
     - define canonical request serialization rules for amount inputs (e.g., amount string with fixed decimals) for all MaphaPay endpoints.

## Phase 4: Architecture Redesign (wallet-first)

This phase defines the target architecture for replacing MaphaPay backend logic with FinAegis primitives, with one overriding rule:

> Every stateful money movement (send money, request acceptance, MTN collection/disbursement success/failure, and bill-split settlements) must flow through the wallet/account transaction engine, not ad-hoc balance updates.

### 4.1 Wallet-first design principles

1. Single source of truth for balances
   - All “money in/out” must be represented as event-sourced ledger mutations executed via wallet/account workflows:
     - `app/Domain/Wallet/Services/WalletOperationsService.php`
     - `app/Domain/Wallet/Workflows/WalletTransferWorkflow.php`
     - `app/Domain/Wallet/Workflows/WalletDepositWorkflow.php`
     - `app/Domain/Wallet/Workflows/WalletWithdrawWorkflow.php`

2. Thin HTTP adapters
   - Controllers should only:
     - validate request payloads,
     - normalize currency + amount units,
     - call domain services/workflows,
     - return canonical API response shapes.
   - Example of a thin adapter style in FinAegis:
     - `app/Http/Controllers/Api/TransferController.php`

3. Deterministic financial integrity
   - Domain aggregates enforce integrity through hash chaining and event integrity checks where available:
     - `app/Domain/Account/Utils/ValidatesHash.php`
     - `app/Domain/Account/DataObjects/Hash.php`

### 4.2 Service boundaries (what lives where)

FinAegis already provides a “wallet domain” boundary. For MaphaPay parity, we add integration/social domains that orchestrate wallet engine calls.

1. HTTP/API layer (MaphaPay-compatible endpoints)
   - Reuse patterns from:
     - `app/Http/Middleware/IdempotencyMiddleware.php` (request replay protection)
     - `app/Http/Controllers/Api/TransferController.php` (thin transfer creation)
   - New or extended controllers (to be created in Phase 5):
     - `api/social-money/*` adapter: records chat/request/bill-split state and triggers ledger settlements via wallet engine.
     - `api/wallet-linking/*` adapter: persists linked wallets and calls MTN “account active” check integration.
     - `api/wallet-transfer/mtn-momo/*` adapter: creates MTN transactions, debits/credits wallet engine, handles polling.

2. Wallet engine (financial state mutation; already present)
   - Core service:
     - `app/Domain/Wallet/Services/WalletOperationsService.php`
   - Workflows:
     - `app/Domain/Wallet/Workflows/WalletTransferWorkflow.php`
     - `app/Domain/Wallet/Workflows/WalletDepositWorkflow.php`
     - `app/Domain/Wallet/Workflows/WalletWithdrawWorkflow.php`
   - Underlying account workflows:
     - `app/Domain/Account/Workflows/DepositAccountWorkflow.php`
     - `app/Domain/Account/Workflows/WithdrawAccountWorkflow.php`

3. Audit logging (must wrap wallet engine calls)
   - Already provided via:
     - `app/Domain/Shared/Logging/AuditLogger.php`
   - `WalletOperationsService` uses `AuditLogger` directly (`auditOperationStart`/`auditOperationSuccess`/`auditOperationFailure`).
   - Requirement for new domains (MTN/social):
     - use the same `AuditLogger` trait or invoke `WalletOperationsService` in a way that naturally triggers audit events.

### 4.3 Transaction engine execution model (target sequence)

For each MaphaPay operation, the “wallet-first” flow should be:

1. Validate + normalize
   - Parse amount as canonical “major units” (for SZL, 2 decimals) and convert to smallest unit using the asset precision where required.
   - Do not let request payload float rounding leak into smallest-unit conversion.

2. Create an idempotency-protected “operation request”
   - For synchronous operations (send money / request acceptance):
     - rely on HTTP idempotency middleware: `app/Http/Middleware/IdempotencyMiddleware.php`
   - For MTN async operations:
     - persist an MTN transaction row keyed by `idempotency_key` (legacy behavior) and treat MTN callback + polling as updates to that row.

3. Execute wallet mutations through workflows
   - Debit:
     - `WalletWithdrawWorkflow` via `WalletOperationsService->withdraw()`
   - Credit:
     - `WalletDepositWorkflow` via `WalletOperationsService->deposit()`
   - Transfer:
     - `WalletTransferWorkflow` via `WalletOperationsService->transfer()`
   - Allow compensation on partial failures (wallet transfer workflow already implements compensation):
     - `app/Domain/Wallet/Workflows/WalletTransferWorkflow.php`

4. Project read models
   - Balances and transaction history must come from projections/read models built off event sourcing.
   - Avoid returning raw smallest-unit values under ambiguous field names (Phase 3 already identified divisors).

### 4.4 Idempotency design (HTTP + domain + external callbacks)

MaphaPay legacy uses different idempotency strategies across domains. The target must unify them into a consistent replay model.

1. HTTP idempotency (FinAegis)
   - Middleware:
     - `app/Http/Middleware/IdempotencyMiddleware.php`
   - Keying rules:
     - only `POST`, `PUT`, `PATCH`
     - header: `Idempotency-Key`
     - caches only successful responses (2xx)
     - request match uses JSON body comparison

2. MTN idempotency (external)
   - Legacy:
     - `MtnMomoController` checks `MtnMomoTransaction::where('idempotency_key', ...)`
   - Target:
     - preserve a DB-level idempotency key for MTN operations because retries can happen long after HTTP middleware cache TTL.

3. Replay-safe callback + polling
   - MTN callback must be safe to receive multiple times:
     - callback controller must locate the transaction and check terminal state before applying side effects.
   - Then (on success) credit/debit must be done through wallet workflows, not direct increments.

4. Domain-level idempotency (required for all money-moving compatibility endpoints)
   - For send money finalize, request-money accept finalize, scheduled-send execute, MTN credit on success, and MTN debit initiation:
     - persist an operation record keyed by `(user_id, operation_type, idempotency_key)`
     - check-and-set completion status atomically before applying side effects
     - return stable responses on replays even outside the HTTP middleware TTL window

### 4.5 Audit logging and operational traceability

Minimum audit trace requirements:

1. For every wallet engine call initiated by:
   - send-money,
   - request acceptance,
   - MTN collection/disbursement success,
   - MTN callback retry,
   - reconciliation backfill,
   you must emit audit events using `app/Domain/Shared/Logging/AuditLogger.php` via:
   - `app/Domain/Wallet/Services/WalletOperationsService.php`

2. For external integrations (MTN):
   - attach MTN reference IDs and idempotency keys to audit context to correlate callback/poll results.

3. For domain integrity:
   - keep hash chaining boundaries observable (if applicable) so you can diagnose mismatches during replays:
     - `app/Domain/Account/Utils/ValidatesHash.php`

## Phase 5: API Contract Alignment (old -> FinAegis)

This phase makes the mobile app's “public API contract” work against FinAegis without reworking FinAegis internal financial integrity.

The key rule for Phase 5 is: keep the mobile app contract stable by implementing FinAegis-side compatibility controllers (or contracts) that return the same JSON envelope shapes, idempotency behavior, and amount semantics that the mobile app currently expects.

### 5.1 Mobile contract invariants (what FinAegis must match)

1. Auth: every authenticated mobile API call uses a Bearer token (`Authorization: Bearer <token>`), and backend code must rely on `request()->user()` (FinAegis uses Sanctum; legacy uses `auth()->user()`).
2. Action response envelope:
   - legacy mobile expects `remark`, `status`, optional `message`, and `data`:
     - `src/core/api/apiClient.ts` documents the Laravel-style envelope (`status: 'success' | 'error'`)
     - examples in mobile:
       - `src/features/send-money/api/useSendMoney.ts` expects `SendMoneyResponse` with `status` + `data.next_step|trx`.
       - `src/features/social/api/useSocial.ts` expects `ApiResponse<TData>` with `remark/status/message/data`.
3. Idempotency:
   - Wallet transfer (send money): mobile sends `Idempotency-Key` header (`useSendMoney.ts`).
     - FinAegis HTTP idempotency middleware reads `Idempotency-Key` (`app/Http/Middleware/IdempotencyMiddleware.php`).
   - MTN MoMo: mobile sends `idempotency_key` in the request body (`useMtnMomoTransfer.ts`), and uses it as the polling key (`GET /api/wallet-transfer/mtn-momo/status/{idempotencyKey}`).
     - Phase 5 must support body-key idempotency at the MTN integration layer even if global HTTP middleware is not applied for those endpoints (or update mobile to also send the header; see §5.6).
4. Amount semantics (currency localization already handled in Phase 3):
   - mobile currently sends SZL amounts in “major units” as numbers (`amount: number`) for:
     - `/api/send-money/store`
     - `/api/request-money/*`
     - `/api/social-money/send-bill-split`, `/api/social-money/send-request-message`, etc.
     - MTN: `amount` as number (`useMtnMomoTransfer.ts`)
   - Phase 7 updates these request payloads to send `amount` as a **major-units string** with exactly 2 decimals (e.g. `"25.10"`).
   - FinAegis wallet engine operates on smallest units (integer) using `Asset.precision`.
   - Therefore each FinAegis compatibility endpoint must:
     - resolve the `Asset` by `asset_code` (SZL),
     - normalize incoming amount into a canonical **string** form (e.g. `"25.10"`) and convert deterministically into minor units using asset precision (see invariants §A),
     - return mobile-friendly amount formats only where mobile expects them (usually it parses/rounds on the client).

**Phase 5 contract decision (required for correctness):**
- All Phase 5 compatibility endpoints that accept an amount MUST accept `amount` as a **string** in major units (e.g. `"25.10"`). Phase 7 updates the mobile app request payloads accordingly.
- During any temporary transition window, do not “helpfully” accept float amounts on the backend for money-moving endpoints; doing so reintroduces non-determinism and idempotency conflicts. If a legacy client still sends numbers, treat it as contract drift and fix the client.

Verified mobile + legacy notes (so Phase 5 matches reality):
- Mobile send-money currently sends `amount: number` and an `Idempotency-Key` header (see `maphapayrn/src/features/send-money/api/useSendMoney.ts`).
- Mobile MTN MoMo initiation currently sends `amount: number` in the body, but it expects `transaction.amount: string` in the response type (see `maphapayrn/src/features/wallet/hooks/useMtnMomoTransfer.ts`). The backend must therefore return amounts in a stable string form even if legacy returned numeric.
- Legacy MTN MoMo backend validates `amount` as `numeric` and credits/debits by mutating `users.balance` inside DB transactions (see `maphapay-backend/core/app/Http/Controllers/Api/MtnMomoController.php` and `.../Gateway/MtnMomo/CallbackController.php`). FinAegis replacement must preserve the same idempotency keys and terminal status behavior, but must move settlement side effects into wallet workflows with exactly-once guards.
5. Verification/authorization flow contract:
   - mobile uses:
     - `/api/verification-process/verify/otp`
     - `/api/verification-process/verify/pin`
   - those endpoints must accept:
     - `remark` (one of the legacy authorized transaction remarks like `send_money`, `request_money`, `request_money_received`, `scheduled_send`, etc.)
     - `trx` (optional, used when present)
     - `otp` or `pin`
   - legacy contract is implemented by:
     - `maphapay-backend/core/app/Http/Controllers/Api/VerificationProcessController.php`
     - `maphapay-backend/core/app/Lib/AuthorizedTransactions/AuthorizedTransactionManager.php`

### 5.2 FinAegis-side implementation approach

For each mobile-facing endpoint, Phase 5 uses one of two patterns:

1. “Compatibility controller” pattern (recommended for this migration):
   - Implement a FinAegis controller that matches the legacy mobile request/response contract.
   - Internally delegate to FinAegis wallet engine building blocks:
     - `app/Domain/Wallet/Services/WalletOperationsService.php`
     - `app/Domain/Wallet/Workflows/WalletTransferWorkflow.php`
     - `app/Domain/Wallet/Workflows/WalletWithdrawWorkflow.php`
     - `app/Domain/Wallet/Workflows/WalletDepositWorkflow.php`
     - `app/Http/Middleware/IdempotencyMiddleware.php` (for header-based idempotency)
   - For verification-gated actions, add a FinAegis port of the legacy authorized transaction dispatching:
     - `AuthorizedTransactionManager` -> remark->handler mapping
2. “Reuse new FinAegis endpoints but wrap output contract” pattern:
   - If the new FinAegis endpoint already exists and is close, wrap/normalize the response JSON keys to match mobile.
   - Example: `app/Http/Controllers/Api/TransferController.php` exists but its raw response does not include the legacy mobile envelope (`status/remark`). For Phase 5, use it only as an internal workflow delegate, not as the direct mobile contract endpoint.

### 5.3 Endpoint mapping table (mobile legacy API -> FinAegis)

Legend:
- “FinAegis target” is either an existing anchor or a new compatibility controller we must add.
- “Wallet engine delegation” names the workflow/service to call once inputs are normalized and converted to smallest units.

#### 5.3.1 Send money (P2P) + scheduled send

1. `POST /api/send-money/store`
   - Mobile request (from `src/features/send-money/api/useSendMoney.ts`):
     - body: `{ user: string, amount: string, remark: 'send_money' (server-side), verification_type?: 'sms'|'email' }` where `amount` is SZL major-units string (e.g. `"25.10"`)
     - header: `Idempotency-Key: <uuid>`
   - Legacy controller/trait:
     - routes: `maphapay-backend/core/routes/api/api.php` (SendMoneyController under `send-money`)
     - controller: `maphapay-backend/core/app/Http/Controllers/Api/SendMoneyController.php`
     - logic: `maphapay-backend/core/app/Traits/SendMoneyOperation.php`
     - OTP/PIN dispatch/authorization:
       - `maphapay-backend/core/app/Http/Helpers/helpers.php` (`storeAuthorizedTransactionData`)
       - `maphapay-backend/core/app/Lib/AuthorizedTransactions/AuthorizedTransactionManager.php`
   - FinAegis target:
     - new compatibility controller (create): `app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php`
   - Wallet engine delegation:
     - on authorization completion (verification endpoints), start:
       - `app/Domain/Wallet/Workflows/WalletTransferWorkflow.php`
       - via `app/Domain/Wallet/Services/WalletOperationsService.php` (or direct workflow stub)
   - Contract requirements:
     - must return legacy mobile `ActionResponse` shape:
       - `status: 'success'|'error'`
       - `remark: 'send_money'`
       - on OTP/PIN required: `data.next_step`, `data.code_sent_message`
       - on immediate execution: include a transaction reference used later by chat UI extractors (`trx` and/or `data.send_money.trx`).

2. `POST /api/send-money/schedule/store`
   - Mobile request: `src/features/send-money/api/useScheduledSend.ts`
     - body: `{ user, amount: string, remark:'scheduled_send', scheduled_at: ISO string, verification_type?: 'email'|'sms' }` where `amount` is SZL major-units string (e.g. `"25.10"`)
   - Legacy mapping:
     - reuse legacy scheduled send authorization dispatch:
       - authorized remark: `scheduled_send`
   - FinAegis target:
     - new compatibility controller: `app/Http/Controllers/Api/Compatibility/SendMoney/ScheduledSendStoreController.php`
   - Wallet engine delegation:
     - do not execute wallet movement during store; instead create an authorized scheduled-send record.
     - when the scheduled job runs or when verification finalizes, execute wallet transfer with SZL smallest units using `WalletTransferWorkflow`.

3. `GET /api/send-money/schedule`
   - Mobile reads list of `scheduled_sends` (`useScheduledSends`).
   - FinAegis target:
     - new compatibility controller: `app/Http/Controllers/Api/Compatibility/SendMoney/ScheduledSendIndexController.php`
   - Data source:
     - must return `data.scheduled_sends[]` including `trx` and `scheduled_at`.

4. `DELETE /api/send-money/schedule/{id}`
   - FinAegis target:
     - new compatibility controller: `app/Http/Controllers/Api/Compatibility/SendMoney/ScheduledSendCancelController.php`

#### 5.3.2 Request money (P2P) + accept/reject

1. `POST /api/request-money/store`
   - Mobile request: `src/features/request-money/api/useRequestMoney.ts`
     - body: `{ user, amount: string, remark (server-side), verification_type?: 'sms'|'email', note?: string }` where `amount` is SZL major-units string (e.g. `"25.10"`) (note is stored as `note` inside the legacy request payload)
   - Legacy:
     - trait: `maphapay-backend/core/app/Traits/RequestMoneyOperation.php` (includes otp/pin dispatch)
   - FinAegis target:
     - new compatibility controller: `app/Http/Controllers/Api/Compatibility/RequestMoney/RequestMoneyStoreController.php`
   - Wallet engine delegation:
     - request creation does NOT immediately move wallet funds.
     - when the recipient accepts (`received-store`), execute wallet movement using `WalletTransferWorkflow`.

2. `POST /api/request-money/received-store/{id}`
   - Mobile request: `useAcceptRequest` sends `{ remark: 'request_money_received', verification_type?: 'sms'|'email' }` + trx/otp/pin verification is done via `/api/verification-process/verify/*`.
   - Legacy:
     - authorized remark mapping is handled in legacy `AuthorizedTransactionManager`:
       - `request_money_received` -> `AuthorizeRequestMoneyReceived` via `getClassName()`
       - `maphapay-backend/core/app/Lib/AuthorizedTransactions/AuthorizedTransactionManager.php`
   - FinAegis target:
     - `app/Http/Controllers/Api/Compatibility/RequestMoney/RequestMoneyReceivedStoreController.php`
   - Contract requirements:
     - `RequestMoneyActionResponse` with:
       - `status/remark/message`
       - `data.next_step` (`otp`|`pin`)
       - `data.code_sent_message` and optional `data.trx`.

3. `POST /api/request-money/reject/{id}`
   - FinAegis target:
     - `app/Http/Controllers/Api/Compatibility/RequestMoney/RequestMoneyRejectController.php`
   - Wallet engine delegation:
     - no wallet movement; just update request status read model/entity.

4. `GET /api/request-money/history?page=...` and `GET /api/request-money/received-history?page=...`
   - Mobile expects paginated structures:
     - `data.request_moneys.data[]` or `data.requested_moneys.data[]`.
   - FinAegis target:
     - `.../RequestMoneyHistoryController.php` and `.../RequestMoneyReceivedHistoryController.php`
   - Data source:
     - event-sourced chat/request model read projections OR port existing legacy request tables into projections.

#### 5.3.3 Social money (threads, chat messages, bill split, payment/request messages)

Social money is a “Build” item from Phase 2; Phase 5 aligns its REST contract to mobile.

All endpoints below must return mobile `ApiResponse<TData>` shape:
- `remark: string`
- `status: 'success'|'error'`
- `message?: string[]`
- `data: ...`

1. `GET /api/social-money/threads`
   - Mobile: `src/features/social/api/useSocial.ts` expects `data.threads[]` with fields:
     - `friendId, friendName, avatarInitials, avatarColor, lastMessage, lastTimestamp, unreadCount, rowSubtitle, pillVariant, pillLabel, hasPendingAction`
   - Legacy controller:
     - `maphapay-backend/core/app/Http/Controllers/Api/SocialMoneyController.php::threads()`
   - FinAegis target:
     - new domain+controllers (create) under:
       - `app/Domain/SocialMoney/...` for aggregates/projectors
       - `app/Http/Controllers/Api/Compatibility/SocialMoney/SocialThreadsController.php`

2. `GET /api/social-money/summary`
   - Mobile expects `data.youOweTotal, owedToYouTotal, activeSplitsCount, topSplits, settleTarget, remindTargets`.
   - FinAegis target:
     - `.../SocialSummaryController.php`
   - Delegation:
     - settle reminder and totals should come from social-money read projection.

3. `GET /api/social-money/messages/{friendId}?cursor=...`
   - Mobile expects cursor pagination:
     - `data.messages[]`, `data.next_cursor`, `data.has_more`
   - FinAegis target:
     - `.../SocialMessagesController.php`
   - Access control:
     - must enforce friendship/peer visibility exactly like legacy (legacy uses `FriendshipService::ensureFriends`).
     - implement policy in new social domain.

4. `POST /api/social-money/send`
   - Mobile request: `{ friendId: number, text: string }`
   - Mobile response: `data.messageId`
   - FinAegis target:
     - `.../SocialSendTextMessageController.php`

5. `POST /api/social-money/send-bill-split`
   - Mobile request:
     - `{ friendId, description, totalAmount, splitMethod, participants }`
   - FinAegis target:
     - `.../SocialSendBillSplitController.php`

6. `POST /api/social-money/send-payment-message`
   - Mobile request:
     - `{ friendId, trx, note, requestMessageId? }`
   - Contract:
     - returns `data.messageId`
   - Delegation:
     - payment message should only be recorded once the wallet transfer is verified/finalized (chat flow passes `trx` from verification process).
     - therefore validate `trx` exists and belongs to the authenticated user before recording.

7. `POST /api/social-money/mark-paid`
   - Mobile request: `{ messageId, participantId }`
   - Contract:
     - marks participant status as paid in the bill split projection.
   - Delegation:
     - may trigger finalization of any outstanding wallet movements if your FinAegis social-money design requires it.
     - otherwise keep it projection-only for Phase 5.

8. `POST /api/social-money/send-request-message`
   - Mobile request: `{ friendId, amount, note }`
   - Contract:
     - creates a request card in chat.

9. `POST /api/social-money/amend-request-message`
   - Mobile request: `{ messageId, amount, note }`

10. `POST /api/social-money/decline-request-message` and `POST /api/social-money/cancel-request-message`
   - Mobile request: `{ messageId }`
   - Contract:
     - updates request message status to `declined` / `cancelled`.

11. `GET /api/social-money/friends`
   - Mobile expects `data.friends[]` with:
     - `id, name, handle, avatarInitials, avatarColor, phoneNumber`.

12. Friend requests (social graph):
   - `GET /api/social-money/friendship-status/{userId}`
   - `GET /api/social-money/friend-requests/incoming`
   - `GET /api/social-money/friend-requests/outgoing`
   - `POST /api/social-money/friend-requests` with `{ userId }`
   - `POST /api/social-money/friend-requests/{requestId}/{accept|reject|cancel}`
   - FinAegis target:
     - new social graph controllers + read projection for:
       - pending incoming/outgoing
       - per-user friendship status object

#### 5.3.4 Wallet linking

1. `GET /api/wallet-linking`
   - Mobile: `src/features/wallet/data/walletDataSource.ts`
   - Expects: `data.wallets[]` where each wallet has:
     - `provider, external_id, status, currency`
   - Legacy controller:
     - `maphapay-backend/core/app/Http/Controllers/Api/WalletLinkingController.php::index()`
   - FinAegis target:
     - `app/Http/Controllers/Api/Compatibility/WalletLinking/WalletLinkingIndexController.php`

2. `POST /api/wallet-linking/mtn-momo/link`
   - Mobile request: `{ msisdn, currency: 'SZL' }`
   - Legacy:
     - `WalletLinkingController::link()` (active check + prevent duplicate active links)
   - FinAegis target:
     - `app/Http/Controllers/Api/Compatibility/WalletLinking/MtnMomoLinkController.php`
   - Required contract:
     - success envelope:
       - `status: 'success'`
       - `data.wallet.status === 'active'` (mobile checks this)

3. `DELETE /api/wallet-linking/{id}`
   - Mobile may call it indirectly (cancel link flows); legacy uses `unlink()` soft status.
   - FinAegis target:
     - `.../WalletLinkingUnlinkController.php`

#### 5.3.5 MTN MoMo wallet transfers (collection + disbursement) + async status

Mobile MTN flow endpoints are explicitly idempotency- and polling-driven.

1. `POST /api/wallet-transfer/mtn-momo/request-to-pay` (Collection into MaphaPay)
   - Mobile request: `src/features/wallet/hooks/useMtnMomoTransfer.ts`
     - body: `{ idempotency_key: uuid, msisdn, amount: string, currency: string, note?: string, pin?: string }` where `amount` is SZL major-units string (e.g. `"25.10"`)
   - Legacy contract:
     - `maphapay-backend/core/app/Http/Controllers/Api/MtnMomoController.php::requestToPay()`
     - response: `apiResponse(..., success|error, ..., ['transaction' => formatTx])`
     - formatTx fields:
       - `idempotency_key, type, amount, currency, status, mtn_reference_id, mtn_financial_transaction_id, note, created_at`
   - FinAegis target:
     - new compatibility controller: `app/Http/Controllers/Api/Compatibility/MtnMomo/MtnMomoRequestToPayController.php`
   - Wallet engine delegation:
     - On initiation:
       - create a FinAegis `MtnMomoTransaction` record in `PENDING` state (keyed by user_id + idempotency_key).
       - do NOT credit wallet immediately (legacy credits only on successful MTN callback/poll).
     - On callback/poll transition to `successful`:
       - credit wallet using FinAegis wallet deposit workflow:
         - `WalletOperationsService::deposit(...)` or `WalletDepositWorkflow`
   - Idempotency requirement:
     - if the exact `(user_id, idempotency_key)` already exists, return the existing transaction without re-calling MTN.

2. `POST /api/wallet-transfer/mtn-momo/disbursement` (Disbursement out of MaphaPay)
   - Mobile request:
     - body: `{ idempotency_key, msisdn, amount: string, currency, note?, pin? }` where `amount` is SZL major-units string (e.g. `"25.10"`)
   - Legacy contract:
     - `MtnMomoController::disbursement()` debits user balance immediately, then calls MTN.
   - FinAegis target:
     - `app/Http/Controllers/Api/Compatibility/MtnMomo/MtnMomoDisbursementController.php`
   - Wallet engine delegation:
     - On initiation:
       - debit user wallet immediately using `WalletOperationsService::withdraw(...)` (or `WalletWithdrawWorkflow`) to preserve “no double spending”.
       - store MTN transaction record as `PENDING`.
     - On callback/poll:
       - do NOT re-credit/debit; only update MTN transaction state and store MTN reference ids.
   - Idempotency requirement:
     - if `(user_id, idempotency_key)` exists:
       - return existing record and do not call MTN again.

3. `GET /api/wallet-transfer/mtn-momo/status/{idempotencyKey}`
   - Mobile request: polls using the idempotencyKey used in initiation.
   - Legacy contract:
     - `MtnMomoController::status()`:
       - if terminal state: return cached transaction.
       - otherwise call MTN `getRequestToPayStatus` or `getTransferStatus`, normalize, update record, and for successful collection credit user.
     - Important legacy risk:
       - legacy credits on success in BOTH polling and callback unless guarded; FinAegis replacement MUST use a single atomic “credited exactly once” guard (see invariants §D).
   - FinAegis target:
     - `app/Http/Controllers/Api/Compatibility/MtnMomo/MtnMomoStatusController.php`
   - Async + audit requirements:
     - transitions `PENDING -> successful/failed/...` must be persisted once and must be safe under repeated polling.
     - credit wallet only on the transition first time (guard with `credited_at` or equivalent field).
     - emit audit events via:
       - `WalletOperationsService` uses `AuditLogger` trait
       - (and for MTN transitions, attach MTN IDs and idempotency key for traceability).

4. Callback endpoint: `POST /ipn/mtn-momo`
   - Legacy:
     - `core/routes/ipn.php` route name `MtnMomo`
     - callback controller: `maphapay-backend/core/app/Http/Controllers/Gateway/MtnMomo/CallbackController.php`
     - expects signature header `X-Callback-Signature` (verification skipped in sandbox)
     - locates transaction by:
       - `X-Reference-Id` (primary) or
       - `idempotency_key` (fallback)
     - updates status and credits wallet on successful collection.
   - FinAegis target:
     - create callback handler controller: `app/Http/Controllers/Api/Compatibility/MtnMomo/MtnMomoIpnCallbackController.php`
   - Security:
     - validate MTN callback signature (match legacy logic) when not in sandbox.
     - do not require Bearer auth.
   - Wallet engine delegation:
     - only on `successful` + `collection`:
       - credit wallet using `WalletDepositWorkflow`.

5. Reconciliation/backfill:
   - Legacy console:
     - `maphapay-backend/core/app/Console/Commands/ReconcileMtnMomoTransactions.php`
   - FinAegis target:
     - create command (name aligned for ops): `app/Console/Commands/ReconcileMtnMomoTransactions.php`
     - behavior:
       - poll pending transactions older than `older-than`
       - update states
       - credit wallet exactly once for successful collection

### 5.4 Auth expectations (what each handler must enforce)

1. Mobile API endpoints:
   - must use Sanctum bearer authentication and require `request()->user()` to exist.
   - must always scope reads/writes by authenticated user id.
2. MTN callback:
   - must NOT require bearer authentication.
   - must validate callback signature when not in sandbox (see legacy callback controller).
   - must update only matching MTN transaction records (by `X-Reference-Id` and/or stored `idempotency_key`).

### 5.5 Idempotency design requirements (so duplicates do not double-credit)

1. Send money (`/api/send-money/store`):
   - rely on FinAegis HTTP idempotency middleware with `Idempotency-Key` header:
     - `app/Http/Middleware/IdempotencyMiddleware.php`
   - AND persist a domain-level operation record for the money-moving finalize step so replays outside the HTTP TTL cannot double-execute (see invariants §B).
2. MTN:
   - initiation endpoints are keyed by body field `idempotency_key`:
     - do not call MTN if a record already exists for that user+key.
   - status polling + callback must be safe for repeated invocations:
     - update transaction status once
     - only credit on the first observed transition to `successful` for `TYPE_COLLECTION`.
   - enforce credit exactly-once with an atomic credited guard field (see invariants §D).

### 5.6 Required frontend edits list (mobile)

Goal: keep the mobile app unchanged where possible; if contract drift exists, fix it with the smallest surface area changes.

1. MTN idempotency alignment (recommended, minimal change):
   - Update `src/features/wallet/hooks/useMtnMomoTransfer.ts` to also send:
     - `Idempotency-Key: <idempotency_key>` header for the two initiation endpoints.
   - Why: it allows FinAegis HTTP idempotency middleware (`IdempotencyMiddleware.php`) to participate in duplicate protection uniformly.
   - If backend implements body-key idempotency already, this step is optional.
2. Currency correctness:
   - Ensure all amount inputs to MTN + send/request endpoints are passed through existing SZL rounding logic before sending:
     - `src/utils/currency.ts::roundToSZL`
   - (This should already be true for most flows; Phase 7 will verify with tests.)
3. Response envelope:
   - no frontend code changes are required if FinAegis compatibility controllers return the legacy `ApiResponse` / `ActionResponse` envelopes that mobile is already typed against (notably `useSendMoney.ts`, `useSocial.ts`, `useMtnMomoTransfer.ts`, `useRequestMoney.ts`).

### 5.7 Phase 5 completion checklist

1. Contract: every mobile-called endpoint returns the expected JSON keys:
   - `status` + `remark` + optional `message` and `data` (no “raw” `{data,message}` without `status`).
2. Amounts: all wallet movement endpoints interpret incoming `amount` as SZL major units and convert via:
   - `Asset.precision` (`app/Domain/Asset/Models/Asset.php`)
   - deterministically from canonical major-unit strings (no float-based conversion)
3. Idempotency:
   - `POST /api/send-money/store` respects repeated calls with same `Idempotency-Key` and identical JSON body.
   - MTN initiation with repeated `idempotency_key` does not call MTN again.
   - MTN callback/poll repeats do not double-credit.
4. Async:
   - `GET /api/wallet-transfer/mtn-momo/status/{idempotencyKey}` reaches terminal states and the UI polling logic stops as expected.
5. Ops:
   - reconciliation command updates states and credits exactly once for successful collections.

## Phase 6: Migration Strategy (legacy -> FinAegis)

This phase defines how to move from the legacy MaphaPay backend to FinAegis with minimal risk of:
- double-credit / double-debit
- contract regressions (mobile expects exact JSON shapes)
- currency/precision mismatches (SZL)

### 6.1 Cutover principles (non-negotiables)

1. The mobile app is the source of UX truth.
   - Phase 5 compatibility controllers define the request/response contract.
2. The wallet ledger is the source of financial truth.
   - Any wallet movement must ultimately go through FinAegis wallet engine:
     - `app/Domain/Wallet/Services/WalletOperationsService.php`
     - `app/Domain/Wallet/Workflows/WalletTransferWorkflow.php`
     - `app/Domain/Wallet/Workflows/WalletDepositWorkflow.php`
     - `app/Domain/Wallet/Workflows/WalletWithdrawWorkflow.php`
3. Idempotency keys are the “lock” for money movement.
   - Send money: `Idempotency-Key` header (FinAegis HTTP idempotency:
     - `app/Http/Middleware/IdempotencyMiddleware.php`)
   - MTN: `idempotency_key` request body + polling key:
     - protect initiation + “credit exactly once” on successful collection.

### 6.2 Migration stages (step-by-step)

#### Stage 0: Pre-flight (SZL + contract parity)

1. Confirm SZL asset and precision are correct.
   - `database/seeders/AssetSeeder.php` must include `SZL` with `precision => 2` and `metadata: ['symbol' => 'E']` (or equivalent).
   - Verify conversion helpers in:
     - `app/Domain/Asset/Models/Asset.php`
2. Confirm mobile SZL rounding is applied before sending amounts.
   - `maphapayrn/src/utils/currency.ts::roundToSZL()`
3. Contract smoke tests (local):
   - Build a small suite of HTTP contract tests against Phase 5 compatibility endpoints:
     - `/api/send-money/store`
     - `/api/request-money/store`
     - `/api/social-money/threads`
     - `/api/wallet-linking`
     - `/api/wallet-transfer/mtn-momo/request-to-pay`
     - `/api/wallet-transfer/mtn-momo/status/{idempotencyKey}`

#### Stage 1: Backfill FinAegis baseline wallet state (from legacy)

Goal: every authenticated mobile user can read and mutate a FinAegis wallet with correct SZL balances.

1. Create/align FinAegis user + wallet identity mapping.
   - Create a persistent mapping table:
     - legacy `user_id` -> FinAegis wallet/account uuid
   - Implementation note:
     - use existing FinAegis multi-tenancy identifiers if present (bounded by how `request()->user()` maps to `Account`).
2. Backfill SZL balances per user account.
   - Recommended approach (financially correct):
      - use wallet deposit workflow for each asset/balance:
        - `WalletOperationsService::deposit($walletId, 'SZL', $amountMinorUnitsString, $reference, $metadata)`
       - internally delegates to `WalletDepositWorkflow` (event-sourced).
   - Source of truth:
     - legacy `users.balance` (or the legacy equivalent wallet record).
  - Amount conversion:
    - convert legacy “major units” -> minor units using a deterministic string-based converter (required).
    - Do not use `Asset::toSmallestUnit(float)` for backfill; floats can drift and break parity checks.
3. Validate read-model parity before enabling writes.
   - For each user cohort:
     - compare legacy balance totals vs FinAegis `AssetBalance` read-model totals for `SZL`.

Rollback-safe checkpoint:
- Once Stage 1 is complete, you can always roll back API routing to legacy reads/writes because FinAegis wallet writes are still disabled.

Backfill safety requirements (must be implemented for Stage 1 to be production-safe):
- Backfill deposits MUST be tagged as migration seeds via reference/metadata so they do not look like user-initiated deposits (see invariants §E).
- Backfill MUST bypass any fees/limits/risk checks that apply to user actions.
- Backfill MUST avoid user-facing notifications (or ensure notification system ignores “migration seed” operations).

#### Stage 2: Progressive endpoint enablement (feature-by-feature)

Instead of switching everything at once, enable Phase 5 compatibility controllers in small increments.

Recommended order (lowest blast radius first):
1. Read-only social graph endpoints:
   - `/api/social-money/threads`
   - `/api/social-money/summary`
   - `/api/social-money/friends`
2. Wallet linking:
   - `/api/wallet-linking`
   - `/api/wallet-linking/mtn-momo/link`
3. Send money (P2P) + verification:
   - `/api/send-money/store`
   - `/api/verification-process/verify/otp`
   - `/api/verification-process/verify/pin`
4. Request money + verification:
   - `/api/request-money/store`
   - `/api/request-money/received-store/{id}`
   - `/api/request-money/reject/{id}`
5. MTN initiation + polling + callback:
   - `/api/wallet-transfer/mtn-momo/request-to-pay`
   - `/api/wallet-transfer/mtn-momo/disbursement`
   - `/api/wallet-transfer/mtn-momo/status/{idempotencyKey}`
   - `POST /ipn/mtn-momo`
6. Scheduled send:
   - `/api/send-money/schedule/store`
   - `/api/send-money/schedule`
   - `/api/send-money/schedule/{id}` (delete)

How to implement “progressive enablement” in code (concrete locations to change):
1. Route registration gating:
   - Put compatibility controllers behind env-driven middleware or route group conditions.
   - Candidate files:
     - `routes/api.php`
     - `routes/api-v2.php`
   - For each endpoint family, define an env flag:
     - `MAPHAPAY_MIGRATION_ENABLE_SEND_MONEY`
     - `MAPHAPAY_MIGRATION_ENABLE_REQUEST_MONEY`
     - `MAPHAPAY_MIGRATION_ENABLE_SOCIAL_MONEY`
     - `MAPHAPAY_MIGRATION_ENABLE_MTN_MOMO`
2. Callback credit guard:
   - Even if HTTP routing is disabled, MTN can still POST to `POST /ipn/mtn-momo`.
   - Therefore, `MtnMomoIpnCallbackController` must check a flag before crediting:
     - if disabled, persist/update MTN transaction status but do NOT credit wallet.

Single-writer policy for in-flight transactions (must be explicitly chosen and enforced):
- **OTP/PIN authorized transactions**:
  - Define whether legacy-authorized pending transactions are:
    - (A) completed in legacy only until cutover boundary, or
    - (B) migrated into FinAegis authorized-transaction store with a deterministic mapping and replay-safe finalize step.
- **In-flight MTN transactions**:
  - Define whether pending MTN operations are:
    - (A) “legacy-owned until terminal” (FinAegis records state but does not settle), or
    - (B) migrated into FinAegis `MtnMomoTransaction` queue and settled by FinAegis only.
  - Whichever you pick, enforce it via side-effect flags and credited/debited guards so two backends cannot settle the same reference.

Idempotency during staged enablement:
- For send money, rely on `Idempotency-Key` + HTTP idempotency middleware caching.
- For MTN, rely on `(user_id, idempotency_key)` uniqueness and “credited exactly once” guard fields.

#### Stage 3: MTN reconciliation + backfill

After enabling MTN initiation endpoints for a cohort, reconcile the “pending” queue continuously.

1. Implement reconciliation job/command:
   - `app/Console/Commands/ReconcileMtnMomoTransactions.php` (Phase 5 target)
2. Schedule it via Laravel scheduler or queue worker:
   - ensure it runs often enough to satisfy mobile polling stop times
3. Backfill credits:
   - only credit when transitioning into `successful` and only once.

#### Stage 4: Full cutover + decommission legacy

Once parity is proven for:
- SZL balances
- send/request correctness including OTP/PIN finalize path
- social money read/write consistency (as defined by Phase 5)
- MTN callback/poll/reconcile correctness

Then:
1. Enable all endpoints for the full tenant/user base.
2. Retire legacy endpoints gradually (feature-flag off, then remove code/routing after a stabilization window).

### 6.3 Rollback plan (safe and explicit)

Rollback is primarily an API routing concern, but MTN requires additional precautions.

#### Rollback A: API routing rollback

Trigger conditions:
- contract mismatch (status/remark/data schema differs)
- balance mismatch beyond tolerance
- idempotency replay errors

Steps:
1. Disable compatibility controllers via env flags:
   - stop registering/allowing Phase 5 routes in `routes/api.php` / `routes/api-v2.php`
2. Keep legacy backend routing active immediately.

Financial safety rationale:
- Only one backend must perform wallet writes during rollback window.
- Compatibility controllers should not write wallet state once flags are disabled.

#### Rollback B: MTN-specific rollback

Trigger conditions:
- MTN callback credit misbehavior
- double-credit symptoms for a set of `idempotency_key`

Steps:
1. Disable “wallet credit on callback” in:
   - `MtnMomoIpnCallbackController` (Phase 5 target)
2. Keep MTN status updates in FinAegis so you can replay reconciliation safely later.
3. Route `GET /api/wallet-transfer/mtn-momo/status/{idempotencyKey}` back to legacy while FinAegis credit is disabled.

Additional safety:
- Ensure the MTN credit guard field (e.g. `credited_at`) is persisted and checked atomically.

### 6.4 Data migration approach details (what must be migrated)

Minimum viable migration data:
1. User identity mapping:
   - legacy user -> FinAegis wallet/account uuid
2. Wallet balances (SZL):
   - backfill via wallet deposit workflows (Stage 1)
3. Wallet linking records:
   - at least MTN MoMo linked wallets that can initiate transfers
4. Pending MTN queue state:
   - seed `MtnMomoTransaction` with `PENDING` statuses for any in-flight operations if you require seamless handoff.
   - otherwise, treat in-flight ops as “legacy-owned” until they terminalize.

Non-blocking (can be done after first cutover if UI tolerates):
1. Social money history backfill (threads/messages)
2. Bill split history backfill
3. Request/message history backfill

### 6.5 Reconciliation/backfill checks (must pass before widening rollout)

Before expanding cohorts for write endpoints, enforce these checks:
1. Per-user SZL balance parity:
   - legacy balance vs FinAegis SZL total for the cohort.
2. Ledger invariants:
   - for a sampled set of transfers, confirm double-entry/aggregate invariants:
     - events persisted + projection updated
3. Idempotency:
   - send-money store repeated requests do not create duplicate wallet movements.
   - MTN initiation repeated requests do not re-call MTN.
   - MTN callback/poll repeats do not double-credit.
4. Verification finalize correctness:
   - OTP/PIN verify endpoints execute exactly once and correctly reference `trx` records.

### 6.6 Phase 6 completion checklist

1. Stage 1 balance backfill completed for the selected cohort.
2. Contract smoke tests for Phase 5 endpoints pass.
3. Feature flags enable write endpoints only in the intended order.
4. MTN callback and reconciliation credit flow passes “exactly once” checks for at least one initiation+callback+poll cycle.
5. Rollback toggles are tested in staging.

## Phase 7: Mobile App Changes (FinAegis-ready contract)

This phase lists the exact mobile files to review/adjust so the app continues to work when the backend is replaced.

Design constraint: if Phase 5 compatibility controllers match the legacy mobile response contract, most of the mobile app should not need structural changes. Changes here are focused on idempotency header alignment and ensuring all “money” inputs are SZL-precise before sending.

### 7.1 Required (or recommended) file-level edits

1. `maphapayrn/src/features/wallet/hooks/useMtnMomoTransfer.ts`
   - Change: when calling:
     - `POST /api/wallet-transfer/mtn-momo/request-to-pay`
     - `POST /api/wallet-transfer/mtn-momo/disbursement`
   - Add header:
     - `Idempotency-Key: <idempotency_key>` (same uuid already used in the request body)
   - Why:
     - it enables FinAegis HTTP idempotency middleware (`app/Http/Middleware/IdempotencyMiddleware.php`) to provide uniform duplicate protection.
   - Where to edit:
     - inside `initiateRequestToPay(...)` and `initiateDisbursement(...)`.

2. `maphapayrn/src/features/send-money/api/useSendMoney.ts`
   - Verify:
     - it already sends `Idempotency-Key` header (`headers: { 'Idempotency-Key': idempotencyKey }`).
     - update request payload to send `amount` as a **string** major-units SZL value (e.g. `"25.10"`) instead of a number.
     - ensure UI calling code still uses `roundToSZL` (or equivalent) before formatting the string, and that the string always has exactly 2 decimals.
   - Where to edit (if needed):
     - update the request type + serializer where `payload.amount` is constructed.

3. `maphapayrn/src/features/request-money/api/useRequestMoney.ts`
   - Verify:
     - update request payload(s) to send `amount` as a **string** major-units SZL value (e.g. `"25.10"`) instead of a number for `/api/request-money/store` and `/api/request-money/received-store/{id}`.
     - ensure the string always has exactly 2 decimals.
   - Where to edit:
     - update the request types + serializers where `payload.amount` is constructed.

4. `maphapayrn/src/features/social/api/useSocial.ts`
   - Verify:
     - `amount` and `totalAmount` values passed into:
       - `/api/social-money/send-bill-split`
       - `/api/social-money/send-request-message`
       - `/api/social-money/amend-request-message`
     - are sent as **strings** in major units with exactly 2 decimals (e.g. `"25.10"`) and are SZL-rounded before formatting.
   - Where to edit:
     - update request types + serializers for these endpoints to use amount strings.

5. `maphapayrn/src/features/send-money/api/useScheduledSend.ts`
   - Verify:
     - update request payload to send `amount` as a **string** major-units SZL value (e.g. `"25.10"`) and ensure it is SZL-rounded before formatting.

6. `maphapayrn/src/utils/currency.ts`
   - Verify/extend tests:
     - `formatCurrency` uses the SZL display prefix `E` and exactly 2 decimals.
     - `roundToSZL` produces stable results for values like `x.005`, `x.0149`, `NaN`, and very small amounts.
   - No structural edit required unless tests reveal rounding drift.

### 7.2 “Contract drift” expectations (what should NOT require mobile changes)

If Phase 5 is implemented as planned, mobile should not require structural changes for:
- JSON envelope keys (`remark/status/message/data`) used by:
  - `useSendMoney.ts`
  - `useSocial.ts`
  - `useRequestMoney.ts`
  - `useMtnMomoTransfer.ts`
  - `walletLinkingService.ts`

### 7.3 Phase 7 completion checklist

1. No “500/422” spikes in staging for mobile flows.
2. SZL amounts sent to all wallet movement endpoints are always:
   - rounded to 2 decimals, and
   - serialized as major-units strings with exactly 2 decimals (e.g. `"25.10"`).
3. Duplicate-send and duplicate-MTN initiation flows behave correctly from the client perspective (no UI double-spend glitches).

## Phase 8: Testing & Financial Integrity

This phase specifies the verification plan needed before widening rollout and before declaring the migration “done”.

### 8.1 Test strategy overview

1. Mobile unit tests (fast):
   - validate SZL rounding/formatting utilities.
2. Backend feature tests (Pest + DB):
   - contract shape tests for Phase 5 endpoints,
   - idempotency tests for money movements,
   - MTN async edge cases (initiation duplicates + callback/poll repeats + exact-once credit).
3. Ledger integrity tests (event sourcing invariants):
   - verify that wallet movements via workflows preserve conservation:
     - for transfers: total SZL across accounts stays constant (credit+debit only reshuffle).
     - for collections: only credit occurs on success (and only once).

### 8.2 Mobile tests (currency correctness)

Add/extend Jest tests for:
1. `maphapayrn/src/utils/currency.ts`
   - `formatCurrency()`:
     - `123` => `E123.00`
     - `1.2` => `E1.20`
   - `roundToSZL()`:
     - handles `NaN`, `Infinity` -> `0`
     - stable rounding for `0.005`, `1.005`, `1.0149`
2. Optional: snapshot tests for UI components displaying balances after rounding (only if existing test infrastructure supports RN).

### 8.3 Backend tests (contract + idempotency + SZL precision)

Pest is already configured; run with:
- `./vendor/bin/pest --parallel`

#### 8.3.1 Contract tests for mobile envelope compatibility

Create feature tests for each Phase 5 “compatibility controller” endpoint and assert JSON keys:
- `tests/Feature/Http/Controllers/Api/*Compatibility*` (new tests to add)

Minimum assertions per endpoint family:
1. `status` is present and matches `'success'|'error'`.
2. On success, the expected `data.*` subtree exists.
3. On validation errors, status is `'error'` and `message` is an array of strings.

#### 8.3.2 Idempotency tests (send money + MTN)

There are already strong idempotency tests:
- `tests/Feature/Middleware/IdempotencyMiddlewareTest.php`
- `tests/Security/API/ApiSecurityTest.php` (api transaction idempotency)

Add additional integration tests specifically for Phase 5 controllers:

1. Send money contract endpoint:
   - call `POST /api/send-money/store` twice with same `Idempotency-Key` and identical JSON body.
   - assert:
     - response body is identical (or at least wallet-impact identical),
     - wallet balances changed exactly once.

2. MTN initiation:
   - mock MTN service client and ensure it is called once per `(user_id, idempotency_key)` even if the initiation endpoint is re-called.

3. MTN credit exactly-once:
   - simulate:
     - callback invoked twice for the same MTN reference id, and
     - `GET /api/wallet-transfer/mtn-momo/status/{idempotencyKey}` invoked multiple times while still pending,
   - assert:
     - wallet credit is applied only once (guard by `credited_at` or equivalent state).

4. Domain-level idempotency beyond HTTP TTL:
   - simulate a replay after the HTTP idempotency middleware cache TTL window and assert:
     - domain-level operation record prevents a second wallet mutation.

#### 8.3.3 SZL precision tests

Leverage existing precision-related tests:
- `tests/Feature/Models/AssetTest.php`
- `tests/Feature/Http/Controllers/Api/AssetControllerTest.php`
- `tests/Feature/Http/Controllers/Api/TransactionControllerTest.php`

Add new assertions for:
1. Any endpoint that formats amounts for mobile must use `Asset.precision` (SZL=2).
2. Any read-model attribute that currently hard-codes divisors must be updated (from Phase 3 findings, candidate locations include `TransactionProjection`-style formatters).

### 8.4 Ledger integrity tests (financial invariants)

Add DB-level assertions around wallet workflows:

1. Transfer conservation:
   - setup two accounts with SZL balances,
   - execute wallet transfer workflow used by send-money,
   - assert:
     - debit from source equals credit to destination in smallest units,
     - sum(balance) across accounts remains constant.
2. Workflow atomicity:
   - force a failure mid-workflow (e.g., simulate deposit failure after withdraw success),
   - assert:
     - compensation logic reverts the ledger state back to the pre-request balances.

Anchor tests/utilities to build on:
- event aggregates already have coverage:
  - `tests/Domain/Account/Aggregates/TransferAggregateTest.php`
  - `tests/Domain/Account/Aggregates/AssetTransactionAggregateTest.php`

### 8.5 Operational edge-case test matrix

Test these cases at least once per endpoint family:
1. Insufficient funds:
   - send money and disbursement must return a deterministic error and must not move wallet state.
2. Same idempotency key but different body:
   - must reject with a 409-style error (FinAegis idempotency middleware behavior).
3. MTN callback arrives before/after polling:
   - both orders must converge to the same terminal status and wallet-credit result.
4. Amount extremes:
   - smallest amounts at SZL precision boundary,
   - large amounts that stress rounding and validation.

### 8.6 Phase 8 completion checklist

1. Contract + envelope compatibility tests pass for every mobile-called endpoint.
2. Idempotency tests pass:
   - send-money duplicate calls,
   - MTN init duplicates,
   - MTN callback/poll repeat credit guard.
3. Ledger invariants pass for:
   - transfer conservation and compensation rollback.
4. SZL precision tests pass end-to-end:
   - request amount -> smallest units -> read-model formatting.

## Phase 9: Observability, Alerts, and Incident Runbooks (required for production cutover)

The migration is not production-ready without operational signals that detect the exact failure modes this plan is designed to prevent.

### 9.1 Required metrics/alerts

1. **Idempotency conflicts**
   - Alert on spikes of 409 idempotency mismatch responses on money-moving endpoints.
2. **Duplicate initiation attempts**
   - Track counts of repeated idempotency keys per user/operation type (send money, MTN request-to-pay, MTN disbursement).
3. **MTN settlement safety**
   - Alert on:
     - callback signature failures (non-sandbox),
     - reconciliation backlog growth,
     - transactions stuck in `PENDING` beyond expected thresholds.
4. **Financial invariants**
   - Detect and alert on:
     - any double-credit attempt for same MTN reference id / idempotency key (should be blocked by credited guard),
     - negative balances in projections (if not allowed),
     - conservation violations for internal transfers (sampled).

### 9.2 “Stop the bleeding” runbook (double-credit/double-debit suspicion)

1. **Immediately disable settlement side effects**
   - Disable flags that allow:
     - MTN callback crediting,
     - reconciliation crediting,
     - send-money finalize wallet mutations,
     - request-money accept finalize wallet mutations,
     while leaving read-only endpoints available for investigation.
2. **Freeze reconciliation**
   - Pause scheduled reconciliation jobs/commands to prevent further state transitions.
3. **Enumerate affected keys**
   - Query by idempotency key, MTN reference id, and credited_at/debited_at anomalies.
4. **Compensate via ledger workflows**
   - Apply reversals/compensations using the same wallet engine primitives (never direct balance edits).
5. **Re-enable progressively**
   - Re-enable settlement flags cohort-by-cohort after root cause and tests confirm safety.

---

## Phase 10: Authentication & User Identity Migration (prerequisite for all write endpoints)

This phase is a hard prerequisite for Phase 5 writes. Nothing in the compatibility layer works until users can authenticate against FinAegis and have a wallet.

### 10.1 The authentication gap

The legacy backend issues Sanctum tokens against its own `users` table. FinAegis has its own `users` table and Sanctum setup. These are **incompatible by default** — a token issued by legacy is invalid against FinAegis, and vice versa.

You have two options:

**Option A — Shared users table (simplest, recommended):**
- Point FinAegis at the same DB as legacy (or run a live replica).
- FinAegis `users` table schema must be compatible with legacy (same columns Sanctum uses: `id`, `email`, `password`, `remember_token`).
- Sanctum token table (`personal_access_tokens`) must be shared or migrated.
- Existing mobile sessions will continue to work without re-login.
- Risk: schema drift between legacy and FinAegis `users` table must be reconciled before the shared connection is active.

**Option B — Token swap via a bridge endpoint:**
- Add a short-lived bridge endpoint (non-money-moving, no idempotency risk) that accepts a legacy bearer token, validates it against legacy, and issues a FinAegis Sanctum token.
- Mobile hits this endpoint once per session cutover.
- Risk: requires coordination between both backends being live simultaneously.

**Recommendation:** Use Option A for the initial migration. Document the shared table contract explicitly and write a migration that reconciles any schema differences.

### 10.2 Login, registration, and profile endpoints

Mobile calls these endpoints on every session start:
- `POST /api/auth/login` (or `/api/login`)
- `POST /api/auth/register`
- `GET /api/user` (profile)
- `POST /api/auth/logout`
- `POST /api/auth/refresh` (if refresh tokens are used)

These must exist in FinAegis compatibility layer if the paths differ from FinAegis defaults. Verify against:
- `maphapayrn/src/features/auth/api/*.ts` for exact paths and payload shapes.
- Legacy: `maphapay-backend/core/routes/api/auth.php`

Required FinAegis targets (if not already present):
- `app/Http/Controllers/Api/Compatibility/Auth/LoginController.php`
- `app/Http/Controllers/Api/Compatibility/Auth/RegisterController.php`
- `app/Http/Controllers/Api/Compatibility/Auth/LogoutController.php`
- `app/Http/Controllers/Api/Compatibility/Auth/ProfileController.php`

### 10.3 Wallet auto-creation on user registration

When a new user registers after cutover, a SZL wallet must be created automatically. Without this, every new user is "stuck" with no account to transfer to or from.

Implementation:
1. Hook into the `Registered` event (or a `RegisterController` post-save step).
2. Call `WalletOperationsService` (or a dedicated `AccountCreationService`) to create an account/wallet for the new user with `asset_code = 'SZL'`.
3. Seed an initial balance of `0` (not via deposit workflow, but via account creation — tag as `system_seed`).

Anchor code:
- `app/Domain/Wallet/Services/WalletOperationsService.php` (check for `createWallet` or equivalent)
- `app/Domain/Account/Aggregates/TransactionAggregate.php` (account initialization event)

### 10.4 Transaction PIN (security-critical)

Legacy uses `requireValidTransactionPin` to gate send-money and disbursement. PIN hashing in legacy must match FinAegis storage (bcrypt vs Argon2 vs custom). If they differ:
- Either backfill PIN hashes using the same algorithm at backfill time, or
- Force PIN reset after cutover (inform users via push/email).

Do not allow a finalize step to execute without PIN/OTP verification matching the same algorithm the mobile sends against.

---

## Phase 11: OTP/PIN Verification Process Port (money-movement gate)

Phase 5 references `AuthorizedTransactionManager` but never specifies the implementation. This phase fills that gap. It is the security backbone of every wallet mutation.

### 11.1 What legacy `AuthorizedTransactionManager` does

The legacy system uses a two-step pattern for money movements:

1. **Step 1 — Initiation** (`POST /api/send-money/store`):
   - Store an "authorized transaction" record keyed by a short `trx` reference.
   - Record contains: `remark` (operation type), `user_id`, `payload` (operation params), `status: pending`.
   - Send OTP (SMS or email) or prompt for PIN depending on `verification_type`.
   - Return `{ status: 'success', data: { next_step: 'otp'|'pin', code_sent_message, trx } }`.

2. **Step 2 — Verification + Finalize** (`POST /api/verification-process/verify/otp` or `.../pin`):
   - Validate OTP or PIN.
   - Look up the `authorized_transaction` by `trx`.
   - Dispatch the correct handler for the `remark` (e.g., `send_money` → execute wallet transfer).
   - Return the finalized response (e.g., transaction ID, updated balance).

### 11.2 FinAegis port: `AuthorizedTransaction` bounded context

Create a new lightweight domain for this:

**Database table:** `authorized_transactions`
```
id (uuid)
user_id (FK users.id)
remark (string: send_money | scheduled_send | request_money_received | etc.)
trx (string, unique, short alphanumeric — mobile polls/passes this)
payload (json — normalized operation params, including amount as string)
status (enum: pending | completed | expired | failed)
verification_type (enum: otp | pin | none)
otp_code (nullable string, hashed)
otp_sent_at (nullable timestamp)
otp_expires_at (nullable timestamp)
created_at / updated_at / expires_at
```

**Remark → handler mapping** (port from legacy `AuthorizedTransactionManager::getClassName()`):

| `remark` | Handler class |
|----------|---------------|
| `send_money` | `App\Domain\AuthorizedTransaction\Handlers\SendMoneyHandler` |
| `scheduled_send` | `App\Domain\AuthorizedTransaction\Handlers\ScheduledSendHandler` |
| `request_money_received` | `App\Domain\AuthorizedTransaction\Handlers\RequestMoneyReceivedHandler` |
| `request_money` | `App\Domain\AuthorizedTransaction\Handlers\RequestMoneyHandler` |

Each handler receives the `AuthorizedTransaction` record and executes the wallet mutation via `WalletOperationsService`.

**FinAegis targets (create):**
- `app/Domain/AuthorizedTransaction/Models/AuthorizedTransaction.php`
- `app/Domain/AuthorizedTransaction/Services/AuthorizedTransactionManager.php`
- `app/Domain/AuthorizedTransaction/Handlers/SendMoneyHandler.php`
- `app/Domain/AuthorizedTransaction/Handlers/RequestMoneyReceivedHandler.php`
- `app/Domain/AuthorizedTransaction/Handlers/ScheduledSendHandler.php`
- `app/Http/Controllers/Api/Compatibility/VerificationProcess/VerifyOtpController.php`
- `app/Http/Controllers/Api/Compatibility/VerificationProcess/VerifyPinController.php`
- Migration: `database/migrations/..._create_authorized_transactions_table.php`

### 11.3 OTP delivery

Legacy sends OTP via SMS or email. FinAegis must have the same notification channels. Confirm:
- `app/Domain/Notification/...` or equivalent exists in FinAegis.
- SMS gateway credentials are configured (`.env`).
- OTP TTL is defined (legacy default: 10 minutes is typical).

### 11.4 Idempotency for the verification step

The finalize step (handler dispatch) MUST be idempotent:
- Before calling `SendMoneyHandler`, check `authorized_transaction.status`.
- If already `completed`, return the same stored response — do NOT re-execute the wallet mutation.
- Update status to `completed` atomically (single `UPDATE ... WHERE status = 'pending'`) before dispatching, to prevent concurrent verify calls from both succeeding.

---

## Phase 12: Canonical `bcmath` Amount Converter (required utility)

The plan repeatedly says "use bcmath, not floats" but never provides the implementation. Every developer writing a compatibility controller needs the same function. This phase specifies it.

### 12.1 The canonical string-to-minor-units converter

```php
<?php
declare(strict_types=1);

namespace App\Domain\Shared\Money;

use App\Domain\Asset\Models\Asset;
use InvalidArgumentException;

final class MoneyConverter
{
    /**
     * Convert a major-unit string (e.g. "25.10") to the integer smallest-unit
     * for the given asset precision (e.g. SZL precision=2 → 2510).
     *
     * Uses bcmath to avoid float rounding. Input MUST be a numeric string.
     */
    public static function toSmallestUnit(string $amount, int $precision): int
    {
        // Validate: only digits, optional leading minus, optional decimal point
        if (! preg_match('/^-?\d+(\.\d+)?$/', $amount)) {
            throw new InvalidArgumentException("Invalid amount string: {$amount}");
        }

        $multiplier = bcpow('10', (string) $precision, 0);
        $result     = bcmul($amount, $multiplier, 0); // truncates; see rounding note below

        // Round half-up: replicate Math.round() used by mobile roundToSZL()
        $floor  = bcmul($amount, $multiplier, 0);
        $scaled = bcmul($amount, $multiplier, 1); // 1 decimal of extra precision
        if (bccomp(bcsub($scaled, $floor, 1), '0.5', 1) >= 0) {
            $result = bcadd($floor, '1', 0);
        }

        return (int) $result;
    }

    /**
     * Convert a smallest-unit integer back to a major-unit string.
     * Always returns exactly $precision decimal places (e.g. 2510 → "25.10").
     */
    public static function toMajorUnitString(int $amount, int $precision): string
    {
        $divisor = bcpow('10', (string) $precision, 0);
        return number_format($amount / (int) $divisor, $precision, '.', '');
    }

    public static function forAsset(string $amount, Asset $asset): int
    {
        return self::toSmallestUnit($amount, $asset->precision);
    }
}
```

File location: `app/Domain/Shared/Money/MoneyConverter.php`

**Usage rule:** All compatibility controllers that accept `amount` from mobile MUST use `MoneyConverter::forAsset($request->amount, $asset)` (not `Asset::toSmallestUnit()` which is float-based).

### 12.2 Input validation rule for amount fields

Add a custom Laravel validation rule:

```php
// app/Rules/MajorUnitAmountString.php
// Validates: string, matches /^\d+\.\d{2}$/ for SZL (2 decimals)
// Rejects: floats, integers, strings with wrong decimal count
```

Apply this rule to every `amount` field in compatibility controllers.

---

## Phase 13: Scheduled Send Execution Engine

Phase 5 defines a controller to *store* scheduled sends but the execution engine is missing. Stored scheduled sends will never fire without this.

### 13.1 The execution gap

`POST /api/send-money/schedule/store` creates an `AuthorizedTransaction` (or similar record) with `remark: scheduled_send` and a `scheduled_at` timestamp. Something must poll/execute these.

### 13.2 Implementation

**Laravel scheduled command:**
- `app/Console/Commands/ExecuteScheduledSends.php`
- Runs every minute via `app/Console/Kernel.php` → `$schedule->command('scheduled-sends:execute')->everyMinute()`.

**Logic:**
1. Query `authorized_transactions` where `remark = 'scheduled_send'` AND `status = 'pending'` AND `scheduled_at <= now()`.
2. For each record, acquire a per-record lock (Redis or DB) to prevent concurrent execution.
3. Dispatch `ScheduledSendHandler` → `WalletTransferWorkflow`.
4. Update status to `completed` or `failed` with error context.
5. Emit notification to sender on execution.

**Idempotency:** The `status` check + lock ensures the send fires exactly once even if the scheduler runs twice.

**Failure handling:**
- On `failed`, mark the record and notify the user.
- Do NOT retry automatically — scheduled sends are user-initiated with a specific time expectation. Failed ones should surface in the mobile "scheduled sends" list with a failed state.

---

## Phase 14: Social Graph & History Migration

The plan marks social money as "Build" but doesn't address the backfill of existing social data from legacy.

### 14.1 What must be migrated before cutover

| Data | Source | Urgency |
|------|--------|---------|
| Friendships (accepted pairs) | Legacy `friendships` table | **Before cutover** — users will see 0 friends otherwise |
| Friend requests (pending) | Legacy `friend_requests` table | Before cutover |
| Message threads (last 30 days) | Legacy `chat_messages` or equivalent | Recommended before cutover (users expect history) |
| Bill splits (open) | Legacy `bill_splits` table | Before cutover — users have pending obligations |
| Bill splits (settled, >30 days old) | Legacy historical | After cutover (non-blocking) |
| Request money (pending) | Legacy `money_requests` table | **Before cutover** — pending requests must remain actionable |

### 14.2 Friendship migration command

Create: `app/Console/Commands/MigrateLegacySocialGraph.php`

Steps:
1. Read accepted friendships from legacy DB.
2. Map legacy `user_id` pairs to FinAegis user IDs (via identity mapping table from Phase 10).
3. Insert into FinAegis social graph model/projection.
4. Tag all migrated records with `migrated_from: legacy` metadata.

### 14.3 Transaction history read-through

For message history that is not backfilled:
- Add a read-through adapter that serves legacy history from the legacy DB for authenticated users until FinAegis has sufficient history.
- Progressive fallback: if FinAegis has messages for a thread, return FinAegis data. Otherwise proxy to legacy read endpoint.
- Remove the proxy after 90 days.

---

## Phase 15: MTN MoMo Configuration & Environment Management

MTN MoMo integration requires specific environment configuration that is absent from the plan.

### 15.1 Required environment variables (FinAegis)

```dotenv
# MTN MoMo Sandbox (development)
MTN_MOMO_BASE_URL=https://sandbox.momodeveloper.mtn.com
MTN_MOMO_COLLECTION_PRIMARY_KEY=<your-collection-subscription-key>
MTN_MOMO_DISBURSEMENT_PRIMARY_KEY=<your-disbursement-subscription-key>
MTN_MOMO_COLLECTION_API_USER=<uuid>
MTN_MOMO_COLLECTION_API_KEY=<generated-key>
MTN_MOMO_DISBURSEMENT_API_USER=<uuid>
MTN_MOMO_DISBURSEMENT_API_KEY=<generated-key>
MTN_MOMO_COLLECTION_ENVIRONMENT=sandbox
MTN_MOMO_DISBURSEMENT_ENVIRONMENT=sandbox
MTN_MOMO_CALLBACK_URL=https://your-domain.com/ipn/mtn-momo
MTN_MOMO_CURRENCY=SZL

# MTN MoMo Production
# (same keys, production values — managed via .env.production.example)
```

### 15.2 FinAegis config file

Create `config/mtn_momo.php` (port from `maphapay-backend/core/config/mtn_momo.php`):

```php
return [
    'base_url'                   => env('MTN_MOMO_BASE_URL'),
    'collection_primary_key'     => env('MTN_MOMO_COLLECTION_PRIMARY_KEY'),
    'disbursement_primary_key'   => env('MTN_MOMO_DISBURSEMENT_PRIMARY_KEY'),
    'collection_api_user'        => env('MTN_MOMO_COLLECTION_API_USER'),
    'collection_api_key'         => env('MTN_MOMO_COLLECTION_API_KEY'),
    'disbursement_api_user'      => env('MTN_MOMO_DISBURSEMENT_API_USER'),
    'disbursement_api_key'       => env('MTN_MOMO_DISBURSEMENT_API_KEY'),
    'collection_environment'     => env('MTN_MOMO_COLLECTION_ENVIRONMENT', 'sandbox'),
    'disbursement_environment'   => env('MTN_MOMO_DISBURSEMENT_ENVIRONMENT', 'sandbox'),
    'callback_url'               => env('MTN_MOMO_CALLBACK_URL'),
    'currency'                   => env('MTN_MOMO_CURRENCY', 'SZL'),
];
```

### 15.3 Sandbox vs production callback URL registration

MTN requires the callback URL to be registered at API user creation time. Steps:
1. Create an API user via `POST /v1_0/apiuser` with `providerCallbackHost`.
2. Store the `X-Reference-Id` UUID as `MTN_MOMO_COLLECTION_API_USER` / `MTN_MOMO_DISBURSEMENT_API_USER`.
3. Generate API keys via `POST /v1_0/apiuser/{uuid}/apikey`.

Document this setup in `README.md` under "MTN MoMo Setup". Without this, every developer wastes hours recreating it.

### 15.4 MTN MoMo service client

Create `app/Domain/MtnMomo/Services/MtnMomoClient.php` (port and adapt from legacy `MtnMomoService`):
- `requestToPay(string $msisdn, string $amountMinorString, string $currency, string $referenceId, string $note): string` — returns `referenceId`.
- `getRequestToPayStatus(string $referenceId): MtnMomoStatus`
- `transfer(string $msisdn, string $amountMinorString, string $currency, string $referenceId, string $note): string`
- `getTransferStatus(string $referenceId): MtnMomoStatus`

All amount parameters MUST be strings, not floats.

---

## Phase 16: Rate Limiting, KYC State, and Device Tokens

### 16.1 Rate limiting

Add per-user rate limits to money-moving endpoints to match legacy behavior and prevent abuse:

```php
// routes/api.php — inside auth:sanctum middleware group
Route::middleware(['throttle:send-money'])->group(function () {
    Route::post('/send-money/store', ...);
});
```

Define in `app/Providers/RouteServiceProvider.php`:
```php
RateLimiter::for('send-money', function (Request $request) {
    return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
});
RateLimiter::for('mtn-initiation', function (Request $request) {
    return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
});
```

Apply `throttle:mtn-initiation` to both `request-to-pay` and `disbursement` endpoints.

### 16.2 KYC state migration

If legacy enforces a KYC tier for wallet operations:
1. Add a `kyc_status` column to the FinAegis `users` table (or a dedicated `kyc_verifications` table).
2. Backfill from legacy KYC state at Stage 1 migration time.
3. Ensure FinAegis compatibility controllers apply the same KYC gate as legacy before executing wallet mutations.
4. If FinAegis already has KYC infrastructure, map legacy tier names to FinAegis tier names.

Skip only if legacy has no KYC enforcement (verify against `maphapay-backend`).

### 16.3 Push notification device tokens

Mobile app registers device tokens for push notifications (transaction receipts, OTP fallback, request-money alerts). If legacy stores device tokens:
1. Identify the legacy token table (`device_tokens`, `push_tokens`, etc.).
2. Add equivalent storage in FinAegis.
3. Backfill at Stage 1.
4. Ensure compatibility layer notification service reads from FinAegis token store.

Without device token migration, users won't receive push notifications post-cutover.

---

## Phase 17: Stage 1 Backfill — Handling the Moving-Target Problem

Stage 1 in Phase 6 says "backfill balances" but doesn't address the fact that users continue transacting in legacy during the backfill. This is the most operationally dangerous part of the migration.

### 17.1 The problem

If you:
1. Read user A's balance from legacy at `T=0` (say `E500.00`)
2. User A sends `E100.00` via legacy at `T=1` (balance → `E400.00`)
3. You backfill user A's FinAegis wallet with `E500.00` at `T=2`

User A's FinAegis balance is now wrong by `E100.00`. If you then enable FinAegis writes, user A gets an extra `E100.00`.

### 17.2 Mitigation strategies (pick one)

**Option A — Rolling snapshot with delta reconciliation (recommended for most cases):**
1. Take a snapshot of all balances at `T=0`. Backfill FinAegis.
2. Record all legacy transactions that occur after `T=0` (delta log).
3. Apply the delta to FinAegis before enabling writes for each user cohort.
4. Verify parity before enabling writes.
- Implementation: add a migration observer to legacy that writes all post-snapshot transactions to a `migration_delta_log` table.

**Option B — Per-user freeze (low volume only):**
1. For each user cohort, pause their legacy transactions briefly (return 503 with retry hint).
2. Backfill their FinAegis balance during the pause.
3. Re-enable, now pointing to FinAegis.
- Only viable for very low-traffic windows. Not suitable for a production fintech at any scale.

**Option C — Dual-write transition period:**
1. Enable both backends to write, but use a reconciliation step to merge state daily.
- Very high complexity, high double-credit risk. Not recommended unless regulatory requirements mandate zero-downtime.

### 17.3 Parity check query

Before enabling any user cohort for FinAegis writes, run:

```sql
-- Legacy balance (major units)
SELECT id, balance FROM users WHERE id IN (:cohort_ids);

-- FinAegis balance (smallest units → convert to major units)
SELECT account_uuid, balance_minor_units / 100.0 as balance
FROM asset_balances WHERE asset_code = 'SZL' AND account_uuid IN (:mapped_uuids);
```

Diff > `E0.01` per user → do not enable writes, investigate.

---

## Phase 18: API Versioning and Route Organization

The plan references both `/api/...` and `/api/v2/...` compatibility routes without settling the structure. This ambiguity will cause route collisions.

### 18.1 Decision

All MaphaPay compatibility endpoints must be registered under the **same prefix the mobile app currently uses**. From the mobile app, this is `{BASE_URL}/api/...` (no version prefix). Do NOT change the route prefix for compatibility controllers — the mobile app does not version-prefix money-movement calls.

FinAegis's own new endpoints (`/api/v2/...`) can continue to exist under v2. They are not the mobile-facing compatibility surface.

### 18.2 Route file organization

```
routes/
  api.php           ← legacy + FinAegis native (unchanged)
  api-v2.php        ← FinAegis v2 native endpoints (unchanged)
  api-compat.php    ← NEW: all Phase 5 compatibility controllers (mobile-facing)
```

`api-compat.php` is loaded in `app/Providers/RouteServiceProvider.php` with:
- prefix: `/api`
- middleware: `api`, `auth:sanctum`
- environment-gated route groups per migration flag (`MAPHAPAY_MIGRATION_ENABLE_*`)

### 18.3 Route collision audit

Before registering any compatibility controller, run:
```bash
php artisan route:list --path=api | grep -E 'send-money|request-money|social-money|wallet-linking|wallet-transfer|verification-process'
```

Resolve any collisions before Stage 2 enablement.

---

## Phase 19: Performance & Load Testing (pre-widening requirement)

Phase 8 has no load testing. A financial migration cannot widen rollout without knowing the system can handle peak load.

### 19.1 Minimum load test scenarios

Before widening beyond 10% of users:

1. **Send money throughput:**
   - Simulate 100 concurrent send-money initiations.
   - Assert: all complete without deadlock on `authorized_transactions` table.
   - Assert: no duplicate wallet mutations.

2. **MTN initiation burst:**
   - Simulate 50 concurrent `request-to-pay` calls from the same user with different idempotency keys.
   - Assert: MTN client is called once per unique key.

3. **MTN callback flood:**
   - Simulate 20 concurrent callbacks for the same `X-Reference-Id`.
   - Assert: wallet credit applied exactly once.

4. **Balance read under write load:**
   - Read balances while concurrent transfers execute.
   - Assert: projections are eventually consistent within 1 second.

### 19.2 Tools

- Laravel `octane` or `siege`/`k6` for HTTP load.
- FinAegis test factories for seeding users and wallets at scale.
- Redis monitoring during load to confirm idempotency cache holds under pressure.

### 19.3 Latency SLAs (must be defined before cutover)

| Endpoint | P95 target |
|----------|-----------|
| `GET /api/wallet-linking` | < 200ms |
| `POST /api/send-money/store` | < 500ms |
| `GET /api/wallet-transfer/mtn-momo/status/{key}` | < 300ms |
| `GET /api/social-money/threads` | < 400ms |

Measure in staging with realistic data volumes. Reject cutover if P95 exceeds targets.

---

## Appendix: Gaps Identified and Addressed

The following gaps were not present in the original plan (Phases 1–9) and were added in review:

| Gap | Added in |
|-----|----------|
| User authentication / token migration | Phase 10 |
| Wallet auto-creation on registration | Phase 10.3 |
| Transaction PIN port | Phase 10.4 |
| `AuthorizedTransactionManager` port + OTP/PIN verification | Phase 11 |
| Canonical `bcmath` amount converter | Phase 12 |
| Scheduled send execution engine | Phase 13 |
| Social graph & history migration | Phase 14 |
| MTN MoMo config & environment management | Phase 15 |
| Rate limiting, KYC state, device token migration | Phase 16 |
| Moving-target problem in Stage 1 backfill | Phase 17 |
| API versioning and route organization | Phase 18 |
| Performance & load testing | Phase 19 |

