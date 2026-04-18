# Phase 3: Minor Accounts Advanced Controls — Agent Planning Prompt

**Date:** 2026-04-17  
**Project:** MaphaPay Minor Accounts (React Native + Laravel)  
**Skill:** `superpowers:writing-plans` (planning), `superpowers:subagent-driven-development` (implementation)

---

## Context

**Phase 1 ✅ Complete:** Core model, guardian invites, multi-guardian support, permission levels  
**Phase 2 ✅ Complete:** Spending limits, guardian approval workflow, emergency allowance, expiry management  
**Phase 3 🚀 Ready for Planning:** Advanced controls, mobile integration, analytics, notifications

### Key Constraint from Phase 2
The Phase 2 plan noted: *"Emergency bypass in the spend flow: the allowance endpoint exists and balance is tracked. The actual bypass in `SendMoneyStoreController` (checking `emergency_allowance_balance` before applying limits) is noted as a Phase 3 extension to avoid overcomplicating the controller now."*

This is your **first task in Phase 3** — implement the emergency allowance bypass.

---

## Your Mission

**Plan, spec, and implement Phase 3 of Minor Accounts by:**

1. **Planning phase** (`superpowers:writing-plans`) — Analyze Phase 2, identify Phase 3 scope, propose architecture, write detailed implementation plan
2. **Spec phase** — Document data models, API contracts, state flows, test cases
3. **Implementation phase** (`superpowers:subagent-driven-development`) — Execute plan task-by-task with spec/quality reviews

---

## Proposed Phase 3 Scope

### Must-Have (High Priority)

**1. Emergency Allowance Bypass**
- Implement bypass logic in `SendMoneyStoreController` that checks `emergency_allowance_balance` before enforcing limits
- Deduct from balance on successful transfer
- Reset balance to amount when guardian updates the allowance
- Tests: verify bypass works, balance decrements, insufficient balance rejected

**2. Account Pause/Lock**
- Add `is_paused` boolean to `accounts` table
- Guardian endpoint: `PUT /api/accounts/minor/{uuid}/pause` and `PUT /api/accounts/minor/{uuid}/resume`
- Reject all outgoing transactions (send-money, withdrawals) when paused
- Return 422 with "Account paused by guardian" message
- Tests: pause/resume cycle, transactions blocked when paused

**3. Spending Analytics (Dashboard Data)**
- New endpoints for guardian to view child's spending:
  - `GET /api/accounts/minor/{uuid}/spending-summary` — daily/monthly totals, remaining limits, pending approvals count
  - `GET /api/accounts/minor/{uuid}/spending-by-category` — breakdown by merchant category (food, entertainment, etc.)
  - `GET /api/accounts/minor/{uuid}/transaction-history` — paginated list of completed + pending transactions
- Tests: verify aggregations, date filtering, authorization

**4. Notifications for Guardians (Webhooks + In-App)**
- When minor initiates transaction requiring approval: webhook + in-app notification to guardian
- When approval approved/declined: notification to minor
- Webhook payload: approval ID, amount, merchant category, expiry, action taken
- Tests: webhook dispatch, notification storage, event tracking

### Nice-to-Have (Medium Priority)

**5. Mobile App Integration Points**
- React Native screens in `/src/app` for:
  - Child: "Pending Approvals" screen (show pending, awaiting guardian)
  - Guardian: "Pending Approvals" screen (list pending, approve/decline inline)
  - Guardian: "Account Settings" screen (pause, emergency allowance, view analytics)
- Use existing TanStack Query hooks, Zustand for state
- Tests: navigation, state updates, error handling

**6. Recurring Transactions (Subscriptions)**
- Model: `RecurringTransaction` (frequency, amount, category, next_run, is_active)
- Flow: first recurring spend requires approval, subsequent auto-approved (if within limits) or held for approval (if above threshold)
- Guardian can approve/decline recurring patterns
- Tests: frequency validation, limit checks, auto-renewal

**7. Account Freeze (Emergency)**
- Guardian can immediately freeze account (harder than pause) — all outgoing blocked, requires explicit unfreeze
- Child cannot request unfreeze (only guardian can)
- Notification: child sees "Account frozen" message
- Tests: freeze prevents spending, unfreeze restores, notifications sent

### Out of Scope (Phase 4+)

- Account delegation (temporary transfer of guardian role)
- Allowance scheduling (automatic weekly/monthly disbursement)
- Cashback/rewards on compliant spending
- Multi-currency spending limits
- Biometric approval by guardian (mobile)

---

## Technical Context

### Database Architecture (Tenant DB)

**Existing Phase 2 tables:**
- `accounts` — add `is_paused` (bool), `emergency_allowance_amount`, `emergency_allowance_balance`
- `minor_spend_approvals` — tracks pending/approved/declined
- `account_memberships` (central DB) — guardian role

**New Phase 3 tables:**
- `spending_summaries` — cache of daily/monthly totals (refreshed nightly)
- `notifications` — in-app notifications for guardians + minors
- `webhooks` — event subscriptions (POST endpoint, headers, retry policy)
- `webhook_logs` — delivery attempts (for debugging)
- `recurring_transactions` — subscription patterns

### API Patterns

**Guardian endpoints (all require guardian role):**
- `GET /api/accounts/minor/{uuid}` — summary + pause status
- `PUT /api/accounts/minor/{uuid}/pause` — pause account
- `PUT /api/accounts/minor/{uuid}/resume` — resume account
- `PUT /api/accounts/minor/{uuid}/freeze` — emergency freeze
- `PUT /api/accounts/minor/{uuid}/unfreeze` — unfreeze (owner only)
- `GET /api/accounts/minor/{uuid}/spending-summary` — analytics
- `GET /api/accounts/minor/{uuid}/spending-by-category` — category breakdown
- `GET /api/accounts/minor/{uuid}/transaction-history` — transaction list
- `GET /api/accounts/minor/{uuid}/notifications` — unread notifications
- `POST /api/accounts/minor/{uuid}/webhooks` — subscribe to events
- `PUT /api/accounts/minor/{uuid}/webhooks/{id}` — update webhook
- `DELETE /api/accounts/minor/{uuid}/webhooks/{id}` — unsubscribe

**Child endpoints:**
- `GET /api/accounts/minor/{uuid}/pending-approvals` — view pending spends waiting on guardian
- `GET /api/accounts/minor/{uuid}/notifications` — view notifications (approvals decided, etc.)

### State Flows

**Emergency Allowance Bypass:**
```
Child initiates send-money (150 SZL, Level 3, threshold 100 SZL)
  → Check absolute limits (daily/monthly) → PASS
  → Check approval threshold (150 > 100) → REQUIRES APPROVAL
  → Check emergency allowance balance (200 SZL available) → BYPASS APPROVAL
  → Deduct 150 SZL from emergency_allowance_balance (200 → 50)
  → Execute transfer
  → Return 200 (success) with reference
```

**Account Pause:**
```
Guardian pauses account
  → Set is_paused = true
  → Notify child: "Your account has been paused"
Child tries send-money
  → Check is_paused → true
  → Return 422: "Your account has been paused by your guardian"
```

**Approval Notification:**
```
Minor initiates spend requiring approval (threshold)
  → Create MinorSpendApproval record
  → Create Notification for guardian: "Approval needed: 150 SZL to John"
  → Send webhook POST to guardian's registered endpoint (if subscribed)
  → Return 202 to minor
Guardian approves
  → Update MinorSpendApproval.status = approved
  → Create Notification for minor: "Your transaction of 150 SZL was approved"
  → Send webhook POST confirmation (if subscribed)
```

---

## Definition of Done

**Planning Complete:**
- `/docs/superpowers/plans/2026-04-17-minor-accounts-phase3-implementation.md` (8-12 tasks)
- `/docs/superpowers/specs/2026-04-17-minor-accounts-phase3-design.md` (API contracts, state flows, DB schema)
- Review with user for alignment on scope

**Implementation Complete:**
- All tasks executed with spec/quality reviews
- Full test coverage (unit + integration)
- React Native screens added (if mobile in scope)
- Code merged to `main`
- Deployment commands documented

---

## Your Starting Point

You are an experienced backend architect with deep knowledge of:
- Laravel 11, PHP 8.3, MySQL
- State machine design (approval workflows, pause/resume)
- Webhook/event-driven patterns
- React Native + TanStack Query
- TDD and test-driven development

**Start by:**

1. **Read the Phase 2 plan and implementation:**
   - `/Users/Lihle/Development/Coding/maphapay-backoffice/docs/superpowers/plans/2026-04-17-minor-accounts-phase2-backend-controls.md`
   - Review recent commits on `main` to understand code patterns

2. **Read the design spec:**
   - `/Users/Lihle/Development/Coding/maphapay-backoffice/docs/superpowers/specs/2026-04-17-minor-accounts-design.md`

3. **Understand the codebase:**
   - Project architecture: `/Users/Lihle/Development/Coding/maphapay-backoffice/.claude/worktrees/inspiring-banzai/CLAUDE.md`
   - Mobile app: `/Users/Lihle/Development/Coding/maphapayrn/.claude/worktrees/inspiring-banzai/CLAUDE.md`

4. **Use `superpowers:writing-plans`** to draft Phase 3 plan:
   - Analyze Phase 2 patterns
   - Propose 8-12 tasks for Phase 3
   - Include emergency allowance bypass (high priority)
   - Structure with TDD (tests first)

5. **Once plan is approved**, use `superpowers:subagent-driven-development` to execute

---

## Success Criteria

✅ Emergency allowance bypass working end-to-end  
✅ Account pause/freeze prevents transactions  
✅ Guardian can view spending analytics  
✅ Webhooks deliver approval events  
✅ Mobile screens for approval management  
✅ 25+ new tests, all passing  
✅ Code merged to main, ready for deployment  
✅ Deployment plan documented (migrations, commands)

---

## Questions for the User (After Planning)

Before implementation, clarify:

1. **Mobile priority:** Is the React Native integration essential, or can it be Phase 4?
2. **Webhook reliability:** Should we implement retry logic + dead-letter queue, or simple fire-and-forget?
3. **Analytics scope:** Real-time updates or nightly batch refresh for summaries?
4. **Notifications:** Use existing Zustand store, or introduce a proper notification system (e.g., Expo push)?
5. **Recurring transactions:** Basic (weekly/monthly repeats) or advanced (rules-based, AI budgeting)?

---

**Ready to plan Phase 3. Let's build the advanced controls.**
