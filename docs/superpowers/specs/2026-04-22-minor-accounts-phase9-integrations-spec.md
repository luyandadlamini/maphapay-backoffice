# Minor Accounts Phase 9 Integrations Spec

Date: 2026-04-22

Scope reviewed:
- `/Users/Lihle/.claude/plans/curious-toasting-kitten.md`
- `docs/review/2026-04-21-minor-accounts-phases-1-8-audit.md`
- `docs/superpowers/plans/2026-04-21-minor-accounts-stabilization-before-phase8.md`
- `docs/MINOR_ACCOUNTS_PHASE1.md`
- `AGENTS.md`
- `routes/api-compat.php`
- `app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php`
- `app/Http/Controllers/Api/Compatibility/RequestMoney/*`
- `app/Http/Controllers/Api/Compatibility/Mtn/*`
- `app/Domain/Account/Services/MinorAccountAccessService.php`
- `app/Domain/Account/Services/MinorNotificationService.php`
- `app/Domain/Account/Models/*`
- `app/Domain/Payment/Services/PaymentLinkService.php`
- `app/Domain/Monitoring/Services/MoneyMovementTransactionInspector.php`
- `app/Filament/Admin/Resources/MtnMomoTransactionResource.php`

## 1. Executive Summary

### What Phase 9 should mean now

Phase 9 should no longer mean "ship broad minor-account backend integrations." In the live codebase, the safe interpretation is:

- a **narrow backend integrations slice** for minor accounts
- focused on **guardian-controlled family support rails**
- implemented as:
  - **guardian-funded outbound family support transfers over MTN MoMo**
  - **guardian-created external family funding links**
  - **provider callback reconciliation and operator review**

The first shippable phase should be **Phase 9A: guardian-orchestrated integrations**, not child-autonomous remittances.

### What changed from the original plan

The original initiative in `curious-toasting-kitten.md` assumed MTN MoMo remittances and external family funding links could be layered on top of an already coherent minor-account model. The later audit and stabilization docs explicitly reject that assumption:

- `docs/review/2026-04-21-minor-accounts-phases-1-8-audit.md` says phases 1-8 are "not complete, not coherent, and not safe as a foundation".
- `docs/superpowers/plans/2026-04-21-minor-accounts-stabilization-before-phase8.md` sets a stop rule: do not continue feature work until ownership semantics, access control, payload contracts, and transactional/idempotent mutation boundaries are stabilized.

That changes Phase 9 in three ways:

1. Direct child-initiated remittance sending is too broad and unsafe for this repo state.
2. MTN MoMo must be treated as a **provider adapter** behind minor/family domain objects, not as the domain itself.
3. External funding links must preserve backend ownership of trust and verification decisions; they cannot be a thin public wrapper around the existing compat MTN controllers.

### Whether it is safe to start now

- **Safe to start now:** planning, API contracts, schema design, and implementation prep for a narrowed Phase 9A.
- **Not safe to start now:** implementation of the full original Phase 9 scope.

Recommended go/no-go:

- **No-go for implementation** until the Phase 8 stabilization stop/go rules are satisfied.
- **Conditional go** for a narrowed Phase 9A only after those preconditions are verifiably complete.

Notable contradictions between docs and code:

- `docs/14-TECHNICAL/WEBHOOK_SECURITY.md` describes centralized webhook signature middleware, but MTN currently uses route-specific callback token checking inside `app/Http/Controllers/Api/Compatibility/Mtn/CallbackController.php` and `routes/api-compat.php`.
- AGENTS and the event-sourcing docs require persisted events for state changes, but the current MTN compat and minor approval flows mostly persist row mutations and workflow side effects rather than explicit business events.
- `app/Filament/Admin/Resources/MtnMomoTransactionResource.php` exposes retry/refund actions that only mutate row status, which is not acceptable as a Phase 9 financial control pattern.

## 2. Scope Definition

### In scope

- Guardian-controlled **outbound family support transfer** orchestration for a minor account context using MTN MoMo disbursement.
- Guardian-created **external family funding links** for inbound support to a minor account.
- A new minor/family integration domain layer that wraps existing MTN MoMo primitives instead of calling compat controllers directly.
- Durable lifecycle tracking for:
  - link creation
  - funding attempt initiation
  - provider pending/success/failure
  - remittance initiation
  - remittance success/failure/refund
  - expiry/cancellation
- Provider callback reconciliation and replay protection for all Phase 9-owned records.
- Filament operator/admin tooling for review, exceptions, and audit visibility.
- Contract work for mobile/backoffice consumers where needed, but backend remains the primary deliverable.

### Out of scope

- Direct child-initiated remittances from a minor wallet.
- Cross-border or multi-provider remittance routing.
- Merchant QR integration.
- Shared goals, sibling visibility, learning modules, or other unsupported phase 6-7 minor endpoints.
- A generic provider orchestration platform across all rails.
- Full public unauthenticated "checkout" beyond MTN collection for family funding links.
- Guardian-to-minor recurring mandates or subscription-style family sponsor plans.

### Deferred follow-ons

- Minor contribution to outbound family support from the child wallet.
- Multi-provider funding links beyond MTN MoMo.
- International remittance adapters.
- Sponsor identity/KYC enrichment beyond MSISDN.
- Automated reversal/refund workflows for inbound collection disputes.
- Mobile UX for sponsor-facing status and guardian-facing remittance dashboards.

## 3. Preconditions

Phase 9 implementation should not begin until all of the following are true.

### Minor-account foundation

- The canonical ownership model is live and tested:
  - child identity on `accounts.user_uuid`
  - guardian/co-guardian access from central `account_memberships`
  - no controller-local access forks
- `MinorAccountAccessService` remains the single source of truth for backend access checks.

Reference:
- `docs/review/2026-04-21-minor-accounts-phases-1-8-audit.md`
- `docs/superpowers/plans/2026-04-21-minor-accounts-stabilization-before-phase8.md`
- `app/Domain/Account/Services/MinorAccountAccessService.php`

### Money-movement safety

- Send-money approval initiation is fixed so:
  - replay/idempotency checks happen before approval creation
  - trust policy evaluation happens before any stateful side effect
  - approval creation is transactional and idempotent

Reference:
- audit finding against `app/Http/Controllers/Api/Compatibility/SendMoney/SendMoneyStoreController.php`

### API contract stability

- Minor account payload contracts are canonicalized across auth/account endpoints.
- Unsupported minor mobile calls remain gated off rather than pointing at nonexistent backend routes.

Reference:
- `docs/superpowers/plans/2026-04-21-minor-accounts-stabilization-before-phase8.md`

### Provider-rail readiness

- MTN MoMo compat flows remain green:
  - `request-to-pay`
  - `disbursement`
  - `status`
  - `callback`
  - `reconciliation`
- Callback replay protection via `mtn_callback_log` is retained.
- Ops agrees whether MTN callback security will remain token-based for now or be moved into shared middleware before Phase 9 starts.

Reference:
- `routes/api-compat.php`
- `app/Http/Controllers/Api/Compatibility/Mtn/*`
- `app/Console/Commands/ReconcileMtnMomoTransactions.php`

### Audit and ledger diagnostics

- New Phase 9 financial movements must be inspectable through ledger/posting diagnostics, not just provider rows.
- `MoneyMovementTransactionInspector` must remain usable for provider-linked references.

Reference:
- `app/Domain/Monitoring/Services/MoneyMovementTransactionInspector.php`

## 4. User Flows

### A. Guardian-funded remittance / family support transfer

1. Guardian opens the minor account context and selects "Send family support".
2. Backend authorizes:
   - acting user is an active `guardian` or `co_guardian` on the minor account
   - source account belongs to the acting user
   - source account is **not** the minor account in Phase 9A
3. Guardian submits:
   - `source_account_uuid`
   - `provider = mtn_momo`
   - `recipient_msisdn`
   - `recipient_name`
   - `amount`
   - `asset_code`
   - `purpose` / `note`
   - idempotency key
4. Backend resolves verification/trust policy using the existing money-movement path.
5. Backend creates a Phase 9 remittance record in `pending_provider` state and records a persisted business event.
6. Backend debits the guardian funding account through the existing wallet path and initiates an MTN disbursement through the provider adapter.
7. Backend stores the MTN reference against the remittance record and linked provider transaction row.
8. Callback or status poll transitions the remittance to:
   - `successful` when MTN confirms completion
   - `failed_refunded` when provider fails after wallet debit and refund succeeds
   - `failed_unreconciled` when provider fails and refund fails
9. Backend writes durable audit entries on the minor account and makes the movement visible to operators.

Phase 9A rule:
- The minor account is the **context and audit beneficiary**, not the source of funds.

### B. External family funding link flow

1. Guardian creates a family funding link for a minor account.
2. Backend authorizes guardian access and persists a link with:
   - target minor account UUID
   - tenant ID
   - fixed or capped amount
   - expiry
   - allowed provider rails
   - public token
3. Backend returns a shareable URL.
4. External family member opens the public link and sees read-only funding metadata.
5. Sponsor submits an MTN collection initiation using:
   - link token
   - sponsor MSISDN
   - amount constrained by link rules
6. Backend validates:
   - link is active and not expired
   - amount fits remaining limit
   - provider rail is allowed
   - link has not exceeded attempt policy
7. Backend creates a funding attempt record, then initiates MTN request-to-pay through the provider adapter.
8. On successful callback/status:
   - backend marks the attempt `successful`
   - credits the target minor account
   - increments link collected totals
   - emits persisted events
   - writes account audit logs
9. If the collection fails or expires:
   - attempt moves to `failed` or `expired`
   - no wallet credit is made
   - link remains usable if still active and under cap

### C. Approval / review flow where applicable

Required approval points in Phase 9A:

- Link creation: guardian/co-guardian authenticated action, no extra ops approval.
- Outbound remittance:
  - normal backend verification and trust policy always apply
  - no extra minor-spend approval object is reused in Phase 9A because the source is guardian-owned, not minor-owned
- Public link funding:
  - no real-time guardian approval at attempt time
  - guardian approval is expressed by pre-creating the link with bounded rules
- Ops/manual review:
  - required for `failed_unreconciled`, callback mismatch, duplicate terminal-state conflict, or suspicious retry volume

### D. Webhook / provider callback reconciliation flow

1. Provider callback arrives with reference ID and provider status.
2. Backend verifies callback authenticity according to the configured MTN callback policy.
3. Backend deduplicates terminal-state processing.
4. Backend resolves the owning Phase 9 record:
   - outbound remittance
   - funding attempt
5. Backend persists normalized provider status and updates the provider transaction row.
6. Backend under lock performs the financial side effect if still needed:
   - credit minor account once for inbound funding
   - refund guardian source account once for failed outbound disbursement
7. Backend emits a persisted business event and writes audit logs.
8. Background reconciliation command picks up stuck provider rows and forces final state convergence.

### E. Failure / reversal / expiry / retry paths

- **Outbound remittance provider initiation fails before provider acceptance**:
  - wallet refund attempted immediately
  - remittance becomes `failed_refunded` or `failed_unreconciled`
- **Outbound remittance later fails via callback/status poll**:
  - refund logic runs once under lock
- **Funding link expires before sponsor initiates collection**:
  - public creation call returns `410`
- **Funding collection callback repeats**:
  - dedupe table and attempt terminal-state guard absorb replay
- **Funding collection succeeds but wallet credit fails**:
  - attempt becomes `successful_uncredited`
  - operator queue entry created
- **Idempotent retry of create/initiate endpoint**:
  - same response replayed
  - payload mismatch returns `409`
- **Provider stuck pending past SLA**:
  - reconciliation job marks `expired_provider_pending` or re-polls status

## 5. Domain Design

### Aggregates / entities / services

Recommended bounded implementation sits in `app/Domain/Account` with a provider adapter in `app/Domain/MtnMomo`:

- `MinorFamilyFundingLink`
  - public funding contract for a minor account
  - owns token, cap/fixed amount, expiry, and current totals
- `MinorFamilyFundingAttempt`
  - one inbound collection attempt against a funding link
  - linked to provider reference and credit lifecycle
- `MinorFamilySupportTransfer`
  - one outbound guardian-funded family support remittance linked to a minor account context
- `MinorFamilyIntegrationService`
  - application service coordinating auth, policies, link rules, and provider adapters
- `MinorFamilyFundingPolicy`
  - validates caps, expiries, one-time-use, and actor/provider rules
- `MtnMomoFamilyFundingAdapter`
  - wraps `MtnMomoClient`
  - does not call compat controllers
- `MinorFamilyReconciliationService`
  - translates MTN callback/status/reconciliation outcomes into Phase 9 state transitions

### Events

Phase 9-owned state transitions must emit persisted business events, even if the wallet movement still uses existing workflow infrastructure.

Recommended event set:

- `MinorFamilyFundingLinkCreated`
- `MinorFamilyFundingLinkExpired`
- `MinorFamilyFundingAttemptInitiated`
- `MinorFamilyFundingAttemptSucceeded`
- `MinorFamilyFundingAttemptFailed`
- `MinorFamilyFundingCredited`
- `MinorFamilySupportTransferInitiated`
- `MinorFamilySupportTransferSucceeded`
- `MinorFamilySupportTransferFailed`
- `MinorFamilySupportTransferRefunded`

These should extend `ShouldBeStored` and project into read models, while also writing account audit rows for operator visibility.

### Policies / authorization boundaries

- Minor account visibility/management stays with `MinorAccountAccessService`.
- Funding link creation:
  - guardian or co-guardian may create
  - if business wants stricter control, guard to `guardian` only
- Outbound remittance:
  - acting user must be guardian/co-guardian on the minor account
  - acting user must own the source account
  - source account cannot be the minor account in Phase 9A
- Public funding attempt:
  - authorized by possession of an unexpired link token plus bounded link rules
  - no public endpoint may directly credit a wallet without provider-confirmed success

### Idempotency strategy

- Authenticated money-moving endpoints require `Idempotency-Key`.
- Public funding-attempt initiation uses a server-generated attempt key derived from:
  - funding link UUID
  - sponsor MSISDN
  - normalized amount
  - short replay window
- Use DB-backed idempotency, not cache-only replay:
  - `operation_records` pattern for authenticated operations
  - unique `(provider, provider_reference_id)` and `(funding_link_uuid, dedupe_hash)` for public attempts
- Callback processing remains terminal-state idempotent.

### Ledger / transaction integrity model

- Phase 9 does not invent a second ledger.
- All wallet credits/debits still flow through the existing wallet/authorized-transaction path.
- Phase 9 domain records hold business context and provider linkage.
- Provider status never becomes the source of financial truth by itself.
- Final financial truth remains:
  - posted wallet/ledger movement
  - plus explicit Phase 9 lifecycle state

### Provider abstraction strategy

- Reuse `MtnMomoClient` and the collection/disbursement patterns.
- Do not reuse `RequestToPayController` or `DisbursementController` as internal domain services.
- Introduce a small interface for Phase 9:
  - `initiateInboundCollection(...)`
  - `initiateOutboundDisbursement(...)`
  - `fetchStatus(...)`
  - `normalizeCallback(...)`
- MTN MoMo is the first adapter; Phase 9 must not hard-code MTN semantics into the new domain objects.

## 6. API Contract Proposal

Canonical naming:

- Use `minor_account_uuid`, `funding_link_uuid`, `funding_attempt_uuid`, `family_support_transfer_uuid`.
- Do not introduce alternate DTO names for the same record.

### A. Create family support transfer

`POST /api/accounts/minor/{minorAccountUuid}/family-support-transfers`

Auth:
- `auth:sanctum`
- guardian/co-guardian on target minor account
- `scope:write`

Headers:
- `Idempotency-Key: <required>`

Request:

```json
{
  "source_account_uuid": "guardian-account-uuid",
  "provider": "mtn_momo",
  "recipient_name": "Gogo Dlamini",
  "recipient_msisdn": "26876123456",
  "amount": "250.00",
  "asset_code": "SZL",
  "note": "School support"
}
```

Response `202`:

```json
{
  "success": true,
  "data": {
    "family_support_transfer_uuid": "uuid",
    "minor_account_uuid": "uuid",
    "status": "pending_provider",
    "provider": "mtn_momo",
    "provider_reference_id": "uuid",
    "amount": "250.00",
    "asset_code": "SZL",
    "created_at": "2026-04-22T10:00:00Z"
  }
}
```

### B. List family support transfers

`GET /api/accounts/minor/{minorAccountUuid}/family-support-transfers`

Auth:
- guardian/co-guardian or child view access

Response:

```json
{
  "success": true,
  "data": [
    {
      "family_support_transfer_uuid": "uuid",
      "status": "successful",
      "provider": "mtn_momo",
      "recipient_name": "Gogo Dlamini",
      "recipient_msisdn_masked": "26876****56",
      "amount": "250.00",
      "asset_code": "SZL",
      "provider_reference_id": "uuid",
      "created_at": "2026-04-22T10:00:00Z",
      "settled_at": "2026-04-22T10:05:00Z"
    }
  ]
}
```

### C. Create external family funding link

`POST /api/accounts/minor/{minorAccountUuid}/funding-links`

Auth:
- `auth:sanctum`
- guardian/co-guardian on target minor account
- `scope:write`

Request:

```json
{
  "title": "Support Sipho's school trip",
  "note": "One-time support collection",
  "provider_options": ["mtn_momo"],
  "amount_mode": "capped",
  "fixed_amount": null,
  "target_amount": "1000.00",
  "expires_at": "2026-05-01T23:59:59Z"
}
```

Response `201`:

```json
{
  "success": true,
  "data": {
    "funding_link_uuid": "uuid",
    "minor_account_uuid": "uuid",
    "status": "active",
    "token": "opaque-token",
    "public_url": "https://pay.maphapay.com/minor-support/opaque-token",
    "provider_options": ["mtn_momo"],
    "target_amount": "1000.00",
    "collected_amount": "0.00",
    "expires_at": "2026-05-01T23:59:59Z"
  }
}
```

### D. Public funding-link lookup

`GET /api/minor-support-links/{token}`

Auth:
- none

Response `200`:

```json
{
  "success": true,
  "data": {
    "funding_link_uuid": "uuid",
    "display_name": "Sipho M.",
    "title": "Support Sipho's school trip",
    "note": "One-time support collection",
    "provider_options": ["mtn_momo"],
    "amount_mode": "capped",
    "remaining_amount": "1000.00",
    "asset_code": "SZL",
    "expires_at": "2026-05-01T23:59:59Z"
  }
}
```

### E. Public MTN funding-attempt initiation

`POST /api/minor-support-links/{token}/mtn/request-to-pay`

Auth:
- none

Request:

```json
{
  "sponsor_name": "Auntie Thandi",
  "sponsor_msisdn": "26876123456",
  "amount": "150.00",
  "asset_code": "SZL"
}
```

Response `202`:

```json
{
  "success": true,
  "data": {
    "funding_attempt_uuid": "uuid",
    "funding_link_uuid": "uuid",
    "status": "pending_provider",
    "provider": "mtn_momo",
    "provider_reference_id": "uuid",
    "amount": "150.00",
    "asset_code": "SZL",
    "expires_at": "2026-04-22T10:20:00Z"
  }
}
```

### F. Public funding-attempt status

`GET /api/minor-support-links/{token}/attempts/{fundingAttemptUuid}`

Auth:
- none

Response:

```json
{
  "success": true,
  "data": {
    "funding_attempt_uuid": "uuid",
    "status": "pending_provider",
    "provider": "mtn_momo",
    "amount": "150.00",
    "asset_code": "SZL",
    "credited_at": null
  }
}
```

Idempotency rules:

- `POST /family-support-transfers`: required header; replay returns same transfer envelope.
- `POST /funding-links`: recommended header; duplicate create should replay.
- `POST /minor-support-links/{token}/mtn/request-to-pay`: server dedupe on attempt hash plus provider reference uniqueness.

## 7. Filament Blueprint Plan

### Describe the User Flows

- Operations staff need to inspect outbound family support transfers and funding-link attempts by minor account, provider status, and reconciliation state.
- Guardians need backoffice surfaces to create, pause, expire, and inspect funding links tied to a minor account.
- Risk/ops staff need exception workflows for:
  - callback mismatch
  - uncredited successful collection
  - failed disbursement refund gap
  - duplicate/replayed provider events

### Map Primitives

#### Resources

- `app/Filament/Admin/Resources/MinorFamilyFundingLinkResource.php`
- `app/Filament/Admin/Resources/MinorFamilyFundingAttemptResource.php`
- `app/Filament/Admin/Resources/MinorFamilySupportTransferResource.php`

Existing resource to reuse as linked evidence only:

- `app/Filament/Admin/Resources/MtnMomoTransactionResource.php`

#### Pages

- `ListMinorFamilyFundingLinks`
- `ViewMinorFamilyFundingLink`
- `ListMinorFamilySupportTransfers`
- `ViewMinorFamilySupportTransfer`
- `ListMinorFamilyFundingAttempts`
- `ViewMinorFamilyFundingAttempt`

#### Relation Managers

- `FundingAttemptsRelationManager` on funding link resource
- `ProviderTransactionsRelationManager` on both funding attempts and family support transfers
- `AuditLogsRelationManager` on all Phase 9 resources
- `MinorFamilyIntegrationsRelationManager` on `AccountResource` for minor accounts

#### Actions

- `Create Funding Link`
- `Expire Link`
- `Pause Link`
- `Resume Link`
- `Retry Status Sync`
- `Mark For Manual Review`
- `Acknowledge Reconciliation Exception`
- `Re-run Credit Settlement`
- `Open Money Movement Inspector`

Important rule:
- Do not add Phase 9 actions that merely flip provider row status like the current `MtnMomoTransactionResource` retry/refund actions.

### State Transitions

Funding link:

- `draft` -> `active` via create action
- `active` -> `paused` via ops/guardian action
- `active` -> `expired` via scheduler or manual expire
- `active` -> `completed` when cap reached or one-time link settled

Funding attempt:

- `pending_provider` -> `successful`
- `pending_provider` -> `failed`
- `successful` -> `successful_uncredited` if wallet credit fails
- `successful_uncredited` -> `credited` after replayed settlement

Family support transfer:

- `pending_provider` -> `successful`
- `pending_provider` -> `failed_refunded`
- `pending_provider` -> `failed_unreconciled`

## 8. Data Model / Schema Changes

### Migrations

Recommended new tables:

- `minor_family_funding_links`
- `minor_family_funding_attempts`
- `minor_family_support_transfers`

Recommended MTN extension:

- add context columns to `mtn_momo_transactions`

### New tables / columns

`minor_family_funding_links`

- `id` UUID
- `tenant_id`
- `minor_account_uuid`
- `created_by_user_uuid`
- `created_by_account_uuid`
- `title`
- `note`
- `token`
- `status`
- `amount_mode` enum `fixed|capped|open`
- `fixed_amount`
- `target_amount`
- `collected_amount`
- `asset_code`
- `provider_options` JSON
- `expires_at`
- `last_funded_at`
- timestamps

`minor_family_funding_attempts`

- `id` UUID
- `tenant_id`
- `funding_link_uuid`
- `minor_account_uuid`
- `status`
- `sponsor_name`
- `sponsor_msisdn`
- `amount`
- `asset_code`
- `provider_name`
- `provider_reference_id`
- `mtn_momo_transaction_id` nullable
- `wallet_credited_at` nullable
- `failed_reason` nullable
- `dedupe_hash`
- timestamps

`minor_family_support_transfers`

- `id` UUID
- `tenant_id`
- `minor_account_uuid`
- `actor_user_uuid`
- `source_account_uuid`
- `status`
- `provider_name`
- `recipient_name`
- `recipient_msisdn`
- `amount`
- `asset_code`
- `note`
- `provider_reference_id`
- `mtn_momo_transaction_id` nullable
- `wallet_refunded_at` nullable
- `failed_reason` nullable
- `idempotency_key`
- timestamps

`mtn_momo_transactions` additions

- `tenant_id` nullable initially, then required for new Phase 9 writes
- `beneficiary_account_uuid` nullable
- `context_type` nullable
- `context_uuid` nullable
- `metadata` JSON nullable

### Tenancy considerations

- Public-link-backed records should not rely on authenticated tenant context.
- Use `tenant_id` on new Phase 9 records so callback and reconciliation jobs can resolve tenant context explicitly.
- Do not infer tenant only from the current request session for public endpoints.
- `minor_account_uuid` alone is not enough for safe public callback resolution.

### Audit / event implications

- Every Phase 9 state transition must:
  - emit a persisted business event
  - write an `account_audit_logs` row against the minor account
- Provider callbacks and reconciliation retries must include enough metadata for investigation:
  - provider reference
  - link/attempt/transfer UUID
  - source/beneficiary account UUID
  - actor or sponsor identifiers as available

## 9. Risk Register

### Financial integrity risks

- Double credit on repeated provider success callback.
- Double refund on callback vs reconciliation race.
- Provider success recorded without wallet credit completion.
- Manual Filament status edits bypassing financial logic.

### Trust boundary risks

- Public link token abuse if links are open-ended or long-lived.
- Using minor-owned funds for remittance without guardian-owned source restriction.
- Reusing compat MTN controllers directly and inheriting wrong trust assumptions.

### Provider / webhook risks

- MTN callback security is not yet unified with the shared webhook middleware model.
- Provider status vocabulary drift can cause bad terminal-state mapping.
- Missing tenant resolution in callback path can mis-credit funds.

### Abuse / fraud vectors

- Funding link spam with repeated request-to-pay attempts to third-party MSISDNs.
- Social engineering around child identity in public links.
- Sponsor enumeration through public status endpoints.

### Operational risks

- Unreconciled provider failures create funds-at-risk cases.
- Support burden if sponsor flows are public but not self-service traceable.
- DTO drift between backend, mobile, and ops tools.

## 10. Testing Strategy

### Pest feature / unit coverage

- Guardian authorization tests for funding-link and support-transfer create/list flows.
- Public link lookup and funding-attempt validation tests.
- Provider adapter tests for MTN payload mapping and status normalization.

### Transaction / idempotency tests

- Same `Idempotency-Key` replays on outbound transfer create.
- Mismatch payload returns `409`.
- Duplicate public funding-attempt initiation dedupes instead of creating second attempt.
- Callback replay stays single-credit / single-refund.

### Webhook replay tests

- Duplicate terminal callback for same reference is absorbed.
- Callback before status poll and status poll before callback both converge to same terminal state.
- Reconciliation command does not double-refund or double-credit.

### Policy / authorization tests

- Guardian/co-guardian allowed according to chosen rule.
- Child denied create on outbound remittance and funding-link create.
- Public endpoints cannot mutate expired/cancelled links.

### Failure mode coverage

- MTN initiation failure before provider acceptance.
- MTN late failure after wallet debit.
- Successful provider callback with downstream wallet credit exception.
- Link expiry after creation but before sponsor attempt.
- Missing tenant context on callback rejected to exception queue.

Use the existing harness guidance from AGENTS:

- Pest
- `MoneyMovementTransactionInspector` for lifecycle verification
- `Sanctum::actingAs(..., ['read', 'write', 'delete'])`

## 11. Implementation Plan

Ordered implementation targets for the future build:

1. **Stabilization gate verification**
   - verify stop/go items from `docs/superpowers/plans/2026-04-21-minor-accounts-stabilization-before-phase8.md`
   - confirm no unresolved send-money approval ordering bug remains

2. **New Phase 9 data model**
   - `database/migrations/*create_minor_family_funding_links_table.php`
   - `database/migrations/*create_minor_family_funding_attempts_table.php`
   - `database/migrations/*create_minor_family_support_transfers_table.php`
   - `database/migrations/*alter_mtn_momo_transactions_for_minor_context.php`

3. **Domain models and events**
   - `app/Domain/Account/Models/MinorFamilyFundingLink.php`
   - `app/Domain/Account/Models/MinorFamilyFundingAttempt.php`
   - `app/Domain/Account/Models/MinorFamilySupportTransfer.php`
   - `app/Domain/Account/Events/*MinorFamily*.php`

4. **Application services**
   - `app/Domain/Account/Services/MinorFamilyIntegrationService.php`
   - `app/Domain/Account/Services/MinorFamilyFundingPolicy.php`
   - `app/Domain/Account/Services/MinorFamilyReconciliationService.php`

5. **Provider adapter**
   - `app/Domain/MtnMomo/Services/MtnMomoFamilyFundingAdapter.php`
   - keep `MtnMomoClient` as the low-level API client

6. **API controllers and routes**
   - `app/Http/Controllers/Api/MinorFamilyFundingLinkController.php`
   - `app/Http/Controllers/Api/PublicMinorFundingLinkController.php`
   - `app/Http/Controllers/Api/MinorFamilySupportTransferController.php`
   - `app/Domain/Account/Routes/api.php`
   - `routes/api-compat.php` only if MTN callback routing needs Phase 9 linkage

7. **Callback/reconciliation integration**
   - update `app/Http/Controllers/Api/Compatibility/Mtn/CallbackController.php`
   - update `app/Http/Controllers/Api/Compatibility/Mtn/TransactionStatusController.php`
   - update `app/Console/Commands/ReconcileMtnMomoTransactions.php`

8. **Audit and diagnostics**
   - extend `app/Domain/Account/Services/MinorNotificationService.php`
   - ensure `app/Domain/Monitoring/Services/MoneyMovementTransactionInspector.php` can resolve new Phase 9 references

9. **Filament ops surfaces**
   - new resources under `app/Filament/Admin/Resources/`
   - avoid copying status-only mutation patterns from `MtnMomoTransactionResource`

10. **Tests**
   - feature tests under `tests/Feature/Http/Controllers/Api/`
   - MTN callback/reconciliation tests under `tests/Feature/Http/Controllers/Api/Compatibility/Mtn/`
   - unit tests for policy/service/event projection logic

## 12. Open Questions

Only decisions that materially block safe implementation remain here.

1. Should Phase 9A allow `co_guardian` to create outbound family support transfers, or only `guardian`?
2. Is public sponsor funding over MTN MoMo acceptable from a compliance perspective with only MSISDN-level sponsor identity, or must public links be restricted to authenticated MaphaPay users first?
3. Should MTN callback verification remain route/controller specific for Phase 9, or is moving MTN onto the shared webhook validation mechanism a hard prerequisite?
4. What is the canonical tenant resolution key for new central/provider-linked Phase 9 records: `tenant_id`, `team_uuid`, or both?
5. Does product require one-time funding links only for Phase 9A, or are capped multi-use links acceptable at launch?
