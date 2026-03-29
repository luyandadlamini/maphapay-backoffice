# God-Level Commit + Security Audit

Date: 2026-03-29

## Current System Context

This is the corrected repo relationship for the migration:

1. `maphapay-backoffice` is the target Laravel back office and API we are migrating onto.
2. `maphapay-backend` is the old backend we are migrating away from.
3. `maphapayrn` is the React Native client being aligned to `maphapay-backoffice`.

I updated `maphapayrn/CLAUDE.md` to point at `maphapay-backoffice` instead of `maphapay-backend`.

## Scope

This second-pass audit is broader than the first pass.

It includes:

1. Committed branch history in `maphapay-backoffice` relative to `origin/main`.
2. Committed branch history in `maphapayrn` relative to `origin/main`.
3. Current uncommitted worktree changes in both repos.
4. Relevant surrounding code outside the exact diffs where needed to judge correctness and production readiness.

Repos reviewed:

1. `maphapay-backoffice`
2. `maphapayrn`

## Historical First-Pass Notes

These two notes from the earlier pass are retained here explicitly because you asked for them to be included:

1. I excluded uncommitted local worktree changes from the audit. The mobile repo currently has additional modified files beyond its committed branch diff, and those were not treated as part of the commit audit.
2. Cross-repo contract checks are partial because `maphapayrn/CLAUDE.md` still describes `maphapay-backend` as its companion backend, while this audit also reviews `maphapay-backoffice`.

Those statements were true for the first pass.

For this second pass:

1. Uncommitted worktree changes are included.
2. The companion-backend reference has been corrected toward `maphapay-backoffice`.

## Method

This pass used:

1. Branch commit history and diff summaries.
2. Current-worktree review in both repos.
3. Parallel subagent audits for backend and mobile current state.
4. Manual source inspection of the highest-risk areas.
5. Actual code changes to reduce production blockers.

## Verification

Completed:

1. `maphapayrn`: `npx tsc --noEmit` passed after the mobile changes.
2. `maphapay-backoffice`: `tests/Unit/Http/Controllers/Api/Auth/MobileAuthCompatTest.php` passed using the alternate PHP binary at `'/Users/Lihle/.config/herd-lite/bin/php'`.

Environment note:

1. The default Herd PHP binary in PATH is broken on this machine for some commands, so backend verification had to use the explicit alternate PHP binary.

## Executive Verdict

The system is materially closer to production readiness than it was before this pass, but it is not yet airtight.

The main improvement is that the most dangerous live mismatches between the React Native app and the migration-target backend have been reduced:

1. The mobile app now points conceptually at the right backend repo.
2. Registration no longer stores tokens before OTP verification succeeds.
3. Mobile KYC gating now uses `kyc_status` instead of stale `kv` fields.
4. Mobile auth and forgot-PIN flows are aligned to `backoffice` mobile-auth endpoints.
5. Backend mobile auth now accepts the current client payload shape and supports PIN-based login plus OTP-based onboarding.
6. Domain idempotency now fails closed instead of double-executing on a pending-record race.
7. MTN disbursement polling now refunds failed debited transfers instead of only marking them failed.
8. Request-money initiation is now behind HTTP idempotency middleware.
9. OTP delivery failures now fail closed instead of pretending success.

## Fixes Applied In This Pass

### Backend

Files changed:

1. `app/Domain/Shared/OperationRecord/OperationRecordService.php`
2. `app/Domain/Shared/Services/OtpService.php`
3. `app/Http/Controllers/Api/Auth/MobileAuthController.php`
4. `app/Http/Controllers/Api/Compatibility/Mtn/RequestToPayController.php`
5. `app/Http/Controllers/Api/Compatibility/Mtn/TransactionStatusController.php`
6. `routes/api-compat.php`
7. `tests/Unit/Domain/Shared/OperationRecord/OperationRecordServiceTest.php`

What changed:

1. `OperationRecordService` no longer executes the protected closure after detecting an identical operation already pending.
2. OTP SMS delivery now throws a runtime error instead of logging and pretending success.
3. `MobileAuthController` now:
   - accepts both `mobile` and `mobile_number`
   - supports PIN login for existing users
   - keeps OTP-based login/onboarding for users without a valid PIN flow
   - returns safer generic responses in several public auth paths
   - aligns profile completion to the client payload (`firstname`, `lastname`, `email`, `username`, `pin`, `pin_confirmation`)
   - sets `transaction_pin` during profile completion
   - adds basic session-limit enforcement after issuing tokens
4. `RequestToPayController` now handles same-key insert races more safely.
5. `TransactionStatusController` now refunds failed disbursements if the wallet was already debited and not yet refunded.
6. `request-money/store` is now behind the `idempotency` middleware.

### Mobile

Files changed:

1. `CLAUDE.md`
2. `src/app/(modals)/register.tsx`
3. `src/app/(modals)/complete-profile.tsx`
4. `src/app/(modals)/forgot-password.tsx`
5. `src/app/(modals)/all-transactions.tsx`
6. `src/app/(modals)/kyc-onboarding.tsx`
7. `src/app/_layout.tsx`
8. `src/app/index.tsx`
9. `src/features/auth/store/authStore.ts`
10. `src/features/profile/api/useProfileSettings.ts`

What changed:

1. The app context now points at `maphapay-backoffice` as the migration target.
2. Registration no longer stores tokens during step 1.
3. OTP verification is now the point where registration stores tokens.
4. Profile completion now hits `/api/auth/mobile/complete-profile` instead of the legacy endpoint.
5. Successful registration now reuses auth initialization instead of manually faking a completed session.
6. Forgot-PIN flow now uses `/api/auth/mobile/forgot-pin`, `/verify-reset-code`, and `/reset-pin`.
7. Auth user normalization now derives `firstname` and `lastname` from backend `name` when needed.
8. KYC routing now uses `kyc_status` consistently.
9. The profile-completion redirect bug in `src/app/index.tsx` was fixed.
10. Transactions filter values were updated from legacy `plus/minus` to canonical `deposit/withdrawal`.
11. Push token registration now matches the current backend device-token contract.

## Highest-Risk Issues That Were Fixed

### 1. Fixed: Domain idempotency race could double-execute money movement

Files:

1. `app/Domain/Shared/OperationRecord/OperationRecordService.php`
2. `tests/Unit/Domain/Shared/OperationRecord/OperationRecordServiceTest.php`

Status:

1. Previously unsafe.
2. Now fails closed with an explicit in-progress error instead of falling through to execute the handler twice.

### 2. Fixed: MTN status polling could mark failed disbursements without refunding the wallet

Files:

1. `app/Http/Controllers/Api/Compatibility/Mtn/TransactionStatusController.php`

Status:

1. Previously inconsistent with callback and reconciliation behavior.
2. Now applies the same refund concept in the status-poll path.

### 3. Fixed: Registration stored tokens before verification

Files:

1. `maphapayrn/src/app/(modals)/register.tsx`

Status:

1. Previously created a partial authenticated state before OTP verification.
2. Now tokens are only persisted after OTP verification succeeds.

### 4. Fixed: Mobile KYC gates were reading removed `kv` fields

Files:

1. `maphapayrn/src/app/index.tsx`
2. `maphapayrn/src/app/_layout.tsx`
3. `maphapayrn/src/app/(modals)/kyc-onboarding.tsx`

Status:

1. Previously route protection was reading stale client fields.
2. Now KYC gating uses `kyc_status` consistently.

### 5. Fixed: Client transaction filter contract drift

Files:

1. `maphapayrn/src/app/(modals)/all-transactions.tsx`

Status:

1. Previously the UI sent `plus/minus` while the new API expected canonical transaction types.
2. Now the client sends `deposit/withdrawal`.

### 6. Fixed: OTP send failures falsely reported success

Files:

1. `app/Domain/Shared/Services/OtpService.php`
2. `app/Http/Controllers/Api/Auth/MobileAuthController.php`

Status:

1. Previously fail-open.
2. Now fail-closed for auth-sensitive flows.

## Current Critical Findings Still Open

These remain the biggest blockers to calling the system airtight.

### 1. Critical: MTN callback trust is still weaker than the desired production model

Files:

1. `app/Http/Controllers/Api/Compatibility/Mtn/CallbackController.php`
2. `config/mtn_momo.php`

Problem:

1. Callback verification still relies on a static shared token.
2. There is no body-signature verification or replay-resistant scheme.

Why it still matters:

1. This endpoint can affect wallet credits and refunds.
2. A leaked callback token still creates a serious financial integrity risk.

### 2. High: PIN reset still uses a two-step OTP pattern that can be tightened further

Files:

1. `app/Http/Controllers/Api/Auth/MobileAuthController.php`
2. `maphapayrn/src/app/(modals)/forgot-password.tsx`

Problem:

1. `verify-reset-code` and `reset-pin` still revolve around the same OTP rather than a separate short-lived reset grant.
2. The flow is better aligned now, but not yet ideal from a hardening perspective.

Recommended end-state:

1. Verify reset OTP once.
2. Issue a short-lived reset grant.
3. Require that grant, not the original OTP, in `reset-pin`.

### 3. High: Migration-balance tooling is still not production-safe

Files:

1. `app/Console/Commands/MigrateLegacyBalances.php`

Problems:

1. Snapshot state still depends on cache instead of a durable run record.
2. Float arithmetic is still used for financial parity checks.
3. Interactive confirmations remain in a command that should be automatable and auditable.

### 4. High: Legacy social-graph migration still hardcodes `SZL`

Files:

1. `app/Console/Commands/MigrateLegacySocialGraph.php`

Problem:

1. Pending money-request migration still assumes `SZL` regardless of source currency.

### 5. Medium: Client still has partially migrated financial-detail mapping

Files:

1. `maphapayrn/src/features/home/data/homeDataSource.ts`
2. `maphapayrn/src/features/wallet/data/walletDataSource.ts`
3. `maphapayrn/src/features/wallet/hooks/useTransactionDetail.ts`
4. `maphapayrn/src/features/wallet/hooks/useTransactions.ts`

Problem:

1. The transaction list and detail layers are still carrying a mix of old and new assumptions.
2. Some detail fields are synthesized or defaulted rather than being sourced from a canonical detail payload.

## Current Production Readiness Assessment

### Backend

Current state:

1. Stronger than before on auth alignment, idempotency, and MTN disbursement repair.
2. Still not fully hardened on callback verification and migration tooling.

Readiness judgment:

1. Closer to production for mobile auth and compat flows.
2. Not yet safe to call fully production-ready for payments infrastructure without tightening callback trust and migration controls.

### Mobile

Current state:

1. Much better aligned to the migration-target backend.
2. The most dangerous onboarding/session bugs from this branch were reduced.
3. TypeScript validation currently passes.

Readiness judgment:

1. Considerably improved.
2. Still needs a final cleanup pass across transaction detail/data mapping and broader end-to-end QA before calling it airtight.

## Recommended Next Fix Order

1. Add strong MTN callback signature verification and replay protection.
2. Replace the PIN-reset two-step OTP reuse with a proper reset grant.
3. Rewrite `MigrateLegacyBalances` to use durable migration-run records and fixed-point math.
4. Remove `SZL` hardcoding from legacy money-request migration or explicitly validate source currency assumptions.
5. Finish the client-side transaction model migration so list, detail, home, and wallet screens all consume one canonical mapping layer.
6. Run end-to-end QA for:
   - mobile PIN login
   - mobile register + OTP + profile completion
   - forgot PIN flow
   - KYC pending/unverified/approved routing
   - request-money initiation retries
   - MTN failed disbursement polling and refund behavior

## Bottom Line

This pass moved the system from "dangerously inconsistent during migration" to "plausibly stabilizing, but still not fully airtight."

The best evidence of progress is that the major live migration mismatches were not just documented, they were reduced in code.

The remaining blockers are narrower now:

1. MTN callback trust model
2. reset-flow hardening
3. migration-command safety
4. remaining financial-data mapping cleanup in the mobile app

That is a much smaller and more realistic production-hardening surface than before this pass.
