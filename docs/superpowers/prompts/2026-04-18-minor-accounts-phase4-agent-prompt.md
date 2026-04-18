# Minor Accounts Phase 4 Planning Prompt

**Date:** 2026-04-18  
**Audience:** Subagent (Specialized Planner)  
**Deliverable:** Detailed implementation plan for Phase 4 (similar structure to Phase 3 plan)

---

## Your Task

**Plan Phase 4 of the MaphaPay Minor Accounts feature.** Produce a detailed implementation plan following the structure of the Phase 3 plan, including:
- **Pre-flight decisions** (if any clarifications needed)
- **File map** (all files to create/modify)
- **Task breakdown** (8–12 independent or loosely-coupled tasks)
- **Each task:** steps, code templates, tests, commits
- **Self-review:** spec coverage, gaps, follow-ups
- **Deployment commands**

Your plan should be **implementable by a subagent using TDD** (test-first, then code, then self-review). Aim for ~25–40 tests across all Phase 4 tasks.

---

## Context: What's Complete (Phases 0–3)

### Phase 0: Data Model & Core Endpoints (Foundational)
- Account model with `type='minor'`, `tier='grow'/'rise'`, `permission_level` (1–8), `parent_account_id`
- Minor-specific columns: `kid_mode_active`, `frozen`, `capabilities` (JSON, per-level abilities)
- AccountMembership with `role='guardian'|'child'`, enforcing parent ↔ child link
- Level progression system (auto-level based on age, parent can accelerate)
- `GET /api/accounts/minor/{uuid}` — fetch minor details (tier, level, parent)
- `GET /api/accounts/minor/{uuid}/level` — public level + badge endpoint
- `POST /api/accounts/create-child` — parent creates minor account (with KYC check)

### Phase 1: Permissions & Categories (Rule Engine + Blocking)
- `ValidateMinorAccountPermission` rule — enforces hard daily/monthly spending limits per level + category blocks (alcohol, tobacco, gambling, high-risk)
- Returns 422 with detailed reason if blocked
- Category enforcement in SendMoneyStoreController (before approval threshold check)
- Routes: `/api/accounts/minor/{uuid}/permissions` — view current limits + blocked categories
- Tests: 5+ per feature (permissions applied, blocks enforced, error messages)

### Phase 2: Spending Approvals & Emergency Allowance (Workflow + Enforcement)
- `minor_spend_approvals` table + model (pending/approved/declined/cancelled)
- Approval threshold logic: if amount > level threshold, hold in approval queue (returns 202)
- `POST /api/minor-accounts/approvals/{id}/approve` — guardian approves (completes transfer)
- `POST /api/minor-accounts/approvals/{id}/decline` — guardian declines (cancels, notifies child)
- `GET /api/minor-accounts/approvals` — list pending approvals (guardian view)
- Emergency allowance: pre-funded reserve (`emergency_allowance_amount`, `emergency_allowance_balance`)
  - Bypass approval threshold if balance covers (deduct on use)
  - Reset balance when guardian updates allowance
- Nightly expiry: stale approvals >24h auto-cancelled
- Tests: 6+ (threshold enforcement, approval flow, emergency bypass, expiry)

### Phase 3: Advanced Controls & Analytics (Pause/Freeze + Visibility + Webhooks)
- Account pause (`is_paused` boolean): blocks all spend (enforced in SendMoneyStoreController)
- Account freeze (`frozen` boolean, guardian-only unfreeze): blocks all spend
- Guardian pause/resume endpoints: `PUT /api/accounts/minor/{uuid}/pause|resume`
- Guardian freeze/unfreeze endpoints: `PUT /api/accounts/minor/{uuid}/freeze|unfreeze`
- Spending analytics:
  - `GET /api/accounts/minor/{uuid}/spending-summary` — daily/monthly totals, pending approvals, remaining limits
  - `GET /api/accounts/minor/{uuid}/spending-by-category` — spend breakdown by merchant category, date filter
  - `GET /api/accounts/minor/{uuid}/transaction-history` — paginated completed + pending transactions
- Notifications (`minor_notifications` table):
  - Created on: approval requested, approval approved, approval declined
  - `GET /api/accounts/minor/{uuid}/notifications` — list unread (child view)
  - `POST /api/accounts/minor/{uuid}/notifications/mark-read` — bulk mark as read
- Webhooks (`minor_webhooks` subscription model + `DeliverMinorWebhookJob`):
  - Guardian subscribes to events: `approval.requested`, `approval.approved`, `approval.declined`
  - Queued delivery with retry (3 attempts: 30s/2m/10m backoff)
  - `minor_webhook_logs` table: per-attempt HTTP status, response snippet, attempt number
  - CRUD: `POST /api/accounts/minor/{uuid}/webhooks`, `DELETE /api/accounts/minor/{uuid}/webhooks/{id}`
- Tests: 4 (bypass, pause, freeze, analytics, notifications, webhooks combined ~29 tests)
- **Mobile (optional, Phase 3 scope):** Guardian approval screen, child pending approvals, account settings (pause toggle, emergency allowance UI)

---

## Original Vision: Full Feature Scope (curious-toasting-kitten.md)

The master plan defines **eight progressive permission levels** (ages 6–8 → 17 → 18+), with **15+ major subsystems**:

1. **Points & Rewards** — Earn points (savings milestones, chores, learning, level unlocks), redeem for airtime/data/vouchers
2. **Chore-to-Allowance Automation** — Parent assigns recurring/one-off chores, child marks complete, auto-payout to wallet
3. **Shared Family Goals** — Parent + child set savings goals, track progress, celebrate milestones
4. **Sibling Visibility / Family Tab** — Multiple kids see level badges, shared goal progress, sibling milestones (privacy-safe)
5. **Parent-Child Financial Coaching** — Smart nudges, educational moments, behavioral insights (non-punitive)
6. **African-First Mobile Money Integration:**
   - Family remittances (child contributes to extended family pool)
   - Informal savings groups (teens join peer savings pools, 5–10 members)
   - Local merchant QR (earn 2x points at partnered merchants)
7. **Account Transition (Age 18)** — Auto-convert minor → personal, child completes full KYC, parent loses guardian access
8. **Full Parental Controls** — Limits, category blocks, transaction approval, visibility, lock/unlock, alerts
9. **Compliance & Regulatory** — KYC, transaction monitoring, data privacy (GDPR + POPIA), account closure
10. Additional subsystems: Family onboarding, referral program, app badges/gamification, behavioral analytics

**Phases 0–3 implement:** Account structure, permission levels 1–8, spending limits + category blocks, approval workflow, emergency allowance, pause/freeze, analytics, notifications, webhooks.

**Phases 4–6+ cover:** Points & rewards, chores, family goals, coaching, mobile money, savings groups, merchant QR, account transition, regulatory workflows.

---

## Phase 4 Scope: What Comes Next?

You have flexibility here. **Choose the most natural next layer** that:
- Builds cohesively on Phases 0–3
- Delivers clear user value for either guardian or child (or both)
- Is independent enough to ship without waiting for other features
- Follows the architectural patterns established (service layer, models, routes, TDD)

**Candidate features (in priority order):**

### **Option A: Points & Rewards System (High Priority)**
*Prerequisite: Phases 0–3 complete*

Scope:
- Points table + model (`minor_points`, `minor_points_ledger`)
- Point-earning logic:
  - Saving milestones (100 SZL saved = 50 pts, 500 SZL = 200 pts, 1,000 SZL = 500 pts)
  - Level unlocks (100 pts bonus per level advance)
  - Parent referrals (200 pts per invite)
  - Financial literacy module completions (25–100 pts, age-tiered)
- Reward catalog (MTN airtime, data bundles, merchant vouchers, charity donations)
- Point redemption flow + inventory management
- Point expiry policy (decide: never expire, or 2-year expiry)
- Guardian + child API endpoints:
  - `GET /api/accounts/minor/{uuid}/points` — balance + breakdown by source
  - `GET /api/accounts/minor/{uuid}/points/history` — paginated ledger
  - `GET /api/accounts/minor/{uuid}/rewards` — available reward catalog
  - `POST /api/accounts/minor/{uuid}/rewards/{reward_id}/redeem` — redeem points
  - `GET /api/accounts/minor/{uuid}/rewards/redemptions` — history
- Notifications: point earned, reward redeemed, milestone reached
- Tests: ~12–15 (earning logic, redemption, inventory, notifications)

### **Option B: Chore-to-Allowance System (Moderate Priority)**
*Prerequisite: Phases 0–3 + Option A (shares points ledger)*

Scope:
- Chores table + model (`minor_chores`, `minor_chore_completions`)
- Chore creation (parent):
  - Recurring (weekly, monthly) or one-off
  - Amount in points or SZL (or both)
  - Due date, description, optional photo proof
- Child completion workflow:
  - Mark complete with optional photo/text
  - Parent approves/rejects
  - Auto-payout on approval (to wallet or points, per chore config)
- Guardian + child API:
  - `GET /api/accounts/minor/{uuid}/chores` — active + completed (with filter)
  - `POST /api/accounts/minor/{uuid}/chores` — parent creates
  - `PUT /api/accounts/minor/{uuid}/chores/{id}` — parent edit/delete
  - `POST /api/accounts/minor/{uuid}/chores/{id}/complete` — child submit completion
  - `POST /api/accounts/minor/{uuid}/chores/{id}/approve` — parent approve
- Notifications: chore assigned, completion requested, approved/rejected
- Tests: ~10–12 (creation, completion, approval, payout, recurrence)

### **Option C: Shared Family Goals (Lower Priority, Complex)**
*Prerequisite: Phases 0–3*

Scope:
- Family goals table + model (`minor_family_goals`, `minor_goal_contributions`)
- Goal creation (parent or child proposes, parent approves):
  - Target amount + deadline
  - Description, category (vacation, purchase, charity, etc.)
  - Visibility (family only, or public)
- Contribution tracking:
  - Child can contribute from wallet or points
  - Parent can contribute on behalf of child or from own account
  - Extended family can contribute (grandparents, aunts)
- Progress & milestones:
  - Real-time progress bar
  - Milestone notifications (25%, 50%, 75%, 100%)
  - Celebration badges on completion
- Guardian + child API:
  - `GET /api/accounts/minor/{uuid}/family-goals` — list active + completed
  - `POST /api/accounts/minor/{uuid}/family-goals` — create (parent) or propose (child)
  - `POST /api/accounts/minor/{uuid}/family-goals/{id}/contribute` — add funds
  - `GET /api/accounts/minor/{uuid}/family-goals/{id}/contributors` — contribution list
- Notifications: goal milestone reached, goal completed, new contribution, invite to goal
- Tests: ~10–12 (creation, contribution, milestone notifications, completion)

### **Option D: Parent-Child Financial Coaching Engine (Complex, AI-Adjacent)**
*Prerequisite: Phases 0–3 + Option A (points)*

Scope:
- Coaching nudges (rule-based, not ML):
  - "You've spent 60% of monthly limit with 2 weeks left"
  - "You saved 10% of allowance this month; here's why that matters"
  - "You completed 3 chores in a row; try a savings challenge"
- Behavioral insights (aggregated weekly/monthly):
  - Spending velocity, saving rate, top categories
  - Consistency in chore completion
  - Goal progress vs. predicted completion date
- Learning modules (age-tiered, self-serve):
  - Module library (budgeting, compound interest, peer pressure, emergency funds, etc.)
  - Module completion = points earned
  - Completion badges
- API:
  - `GET /api/accounts/minor/{uuid}/coaching/insights` — this week/month behavioral summary
  - `GET /api/accounts/minor/{uuid}/coaching/nudges` — pending nudges
  - `GET /api/accounts/minor/{uuid}/learning/modules` — available modules (age-filtered)
  - `POST /api/accounts/minor/{uuid}/learning/modules/{id}/start` — begin module
  - `POST /api/accounts/minor/{uuid}/learning/modules/{id}/complete` — mark complete
- Notifications: nudge delivered, module available, insight shared
- Tests: ~8–10 (nudge rules, insights calculation, module tracking)

### **Option E: Account Transition to Age 18 (Regulatory, Medium Priority)**
*Prerequisite: Phases 0–3*

Scope:
- Account transition workflow:
  - Auto-trigger at age 18 (date-based check)
  - Child completes adult KYC (full identity verification, not just school ID)
  - Guardian receives 30-day notice before transition
  - On completion, account type changes: `'minor'` → `'personal'`, child becomes owner, parent loses guardian role
- Data handling:
  - Balance carries over
  - Transaction history archived (accessible, but in read-only view)
  - Older data (>2 years) anonymized per GDPR/POPIA
  - Child account relationships dissolved
- API:
  - `GET /api/accounts/minor/{uuid}/transition-status` — days to transition, KYC status
  - `POST /api/accounts/minor/{uuid}/transition/start-kyc` — child initiates KYC
  - `POST /api/accounts/minor/{uuid}/transition/complete` — complete transition (after KYC + notice period)
- Notifications: 90-day warning, 30-day reminder, 7-day final notice, transition complete
- Tests: ~8–10 (age-based trigger, KYC flow, data archival, role transition, notifications)

### **Option F: Mobile Integration — React Native Screens (High Priority)**
*Prerequisite: Phases 0–3 (core backend)*

Scope:
- **Guardian Screens:**
  - Guardian Dashboard: overview (all child accounts, spending summary, pending approvals, notifications)
  - Child Account Settings: pause/freeze toggles, emergency allowance adjustment, level acceleration request
  - Approvals List: view pending, approve/decline inline (already started in Phase 3)
  - Spending Analytics: widgets for summary + category breakdown
  - Notifications: list unread, tap to view, mark as read
- **Child Screens:**
  - Child Dashboard: balance, level progress, current limits, recent transactions
  - Transaction History: paginated list (pending + completed)
  - Pending Approvals: show transactions awaiting parent approval
  - Points & Rewards (once Phase 4 Option A ships): balance, earning breakdown, reward catalog, redemption flow
  - Notifications: approval decisions, milestones, coaching nudges
- **Shared:**
  - Account switcher: toggle between personal + minor account(s)
  - Styled consistently with existing MaphaPay UI (HeroUI Native for auth/pay, RN primitives for screens)
- Integration with TanStack Query for backend API consumption, Zustand for local UI state
- Tests: E2E tests on mobile against staging API (not unit-level; focus on flow integration)
- Target: ~8–12 mobile components + 3–5 E2E scenarios

---

## Your Decision Process

1. **Read the context above** carefully, including the original vision and Phases 0–3 summaries.
2. **Evaluate the six candidate options** (A–F) and **choose the top 2–3 that make sense as a logical next layer.**
   - Consider: user value, implementation complexity, architectural fit, parallelizability with other work
3. **Propose your Phase 4 scope** in a "Proposed Scope" section at the top of your plan.
   - Explain why these features make sense together and in this order.
   - If you want to combine parts of multiple options (e.g., Option A + partial Option B), that's fine; just explain the rationale.
4. **Ask clarification questions if needed** (pre-flight section) — e.g., "Should points ever expire?" or "Do we want ML-based coaching in Phase 4 or Phase 5+?"
5. **Create the full implementation plan** (Tasks 1–N, each with steps, code, tests, commits).

---

## Technical Context (Unchanged from Phases 0–3)

**Backend Stack:**
- **PHP 8.4** with strict types
- **Laravel 12** with multi-tenancy (Spatie tenancy package)
- **MySQL** tenant DB (`accounts`, `minor_spend_approvals`, new Phase 4 tables)
- **Pest** for testing (`#[Test]` attributes)
- **PHPStan** Level 8 (strict mode)
- **DDD + Event Sourcing** (Spatie Events v7.7+)
- **Sanctum** auth with ability-based gates (`['read', 'write', 'delete']`)

**Models & Traits:**
- All models: `HasUuids`, `$guarded = []`, `UsesTenantConnection`
- `Account` model stores type (`'minor'`, `'personal'`, etc.), tier (`'grow'`, `'rise'`), permission_level (1–8)
- Tests use `connectionsToTransact(['mysql','central'])` + `shouldCreateDefaultAccountsInSetup: false`

**File Organization:**
- `app/Domain/Account/Models/` — all account-related models
- `app/Domain/Account/Services/` — business logic (validators, services)
- `app/Domain/Account/Rules/` — validation rules (e.g., `ValidateMinorAccountPermission`)
- `app/Http/Controllers/Api/` — endpoints
- `app/Domain/Account/Routes/api.php` — route registration
- `database/migrations/tenant/` — tenant migrations
- `tests/Feature/Http/Controllers/Api/` — controller tests
- `tests/Feature/` — model + integration tests

**Patterns to Follow:**
- **TDD:** Write failing tests first, then implement
- **Self-review:** Implementer checks spec compliance + code quality before handoff
- **Major-unit strings:** Amounts stored as strings like `'150.00'`, not integers (unless explicitly cents/minor-unit)
- **Migrations:** Use `php artisan migrate --path=... --force` for deployment
- **Commits:** Include `Co-Authored-By: Claude <noreply@anthropic.com>` footer
- **Routes:** Guardian-only actions use `$this->authorize('updateMinor', $minorAccount)` (or similar policy method)

**Deployment Notes:**
- Tenant migrations run on all customer databases (via Spatie tenancy migration runner)
- API responses use **compatibility layer** (centralized error envelopes)
- Webhooks are queued (via Laravel queue, configured for Redis or database backend)

---

## Plan Structure Expected

Your deliverable should follow this outline (see Phase 3 plan as template):

```
# Minor Accounts Phase 4: [Feature Name] Implementation Plan

## Proposed Scope
[Explain your choice of features for Phase 4]

## Pre-Flight Decisions / Clarifications
[List any decisions you made, or questions that need user input]

## File Map
[Table of all files to create/modify]

## Task 1: [Feature A - Part 1]
- [ ] Step 1: ...
- [ ] Step 2: ...
[code blocks with full implementations]
- [ ] Step N: Commit

## Task 2: [Feature A - Part 2]
...

## Task N: [Final Task]

## Self-Review
[Checklist of spec coverage, test count, gaps, follow-ups]

## Migration Commands for Deployment
[Exact `php artisan migrate --path=... --force` commands]

## Optional Tasks (Phase 5 or Follow-Up)
[Nice-to-haves that don't ship in Phase 4]
```

---

## Success Criteria

Your plan is **complete** when:
1. ✅ **Proposed scope** is clear (top 2–3 candidate features chosen with rationale)
2. ✅ **All tasks are atomic** (can be implemented by subagent independently, ~1–2 files per task max)
3. ✅ **Test-first approach** (failing tests written before each task's implementation)
4. ✅ **Code templates complete** (every file creation includes full template, not pseudocode)
5. ✅ **Migration paths explicit** (`database/migrations/tenant/YYYY_MM_DD_HHMMSS_*.php` with exact filenames)
6. ✅ **25–40 tests** across all Phase 4 tasks (tracked in Self-Review)
7. ✅ **Spec coverage map** (table matching each requirement to a task)
8. ✅ **Deployment commands** at the bottom (exact artisan commands with `--force`)
9. ✅ **Follow-ups/gaps documented** (what Phase 5 should cover, known limitations)
10. ✅ **No ambiguity** (subagent should not need to ask questions during implementation)

---

## References

- **Master Plan:** `/Users/Lihle/.claude/plans/curious-toasting-kitten.md` (full vision, all 15+ subsystems)
- **Phase 2 Plan:** `/Users/Lihle/Development/Coding/maphapay-backoffice/docs/superpowers/plans/2026-04-17-minor-accounts-phase2-backend-controls.md` (spending approvals, emergency allowance)
- **Phase 3 Plan:** `/Users/Lihle/Development/Coding/maphapay-backoffice/docs/superpowers/plans/2026-04-17-minor-accounts-phase3-implementation.md` (pause/freeze, analytics, notifications, webhooks, mobile)
- **CLAUDE.md:** `/Users/Lihle/Development/Coding/maphapay-backoffice/CLAUDE.md` (tech stack, patterns, rules)
- **Phase 2 Tests (examples):** `/Users/Lihle/Development/Coding/maphapay-backoffice/tests/Feature/Http/Controllers/Api/MinorSpendEnforcementTest.php`
- **Current codebase:** `/Users/Lihle/Development/Coding/maphapay-backoffice/` (Laravel 12 repo)

---

## Questions?

This prompt is self-contained. If you need clarification on the original vision, refer to `curious-toasting-kitten.md`. If you need architecture context, refer to Phases 0–3 plans and `CLAUDE.md`.

**Proceed with planning Phase 4. Start with the "Proposed Scope" section and declare your chosen features and rationale.**
