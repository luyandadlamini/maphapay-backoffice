# Plan: MaphaPay Minor Accounts Feature (Ages 6–17)

**Date:** 2026-04-16  
**Status:** Design Complete — Audited & Hardened  
**Last Audit:** 2026-04-16 (comprehensive gap analysis, competitor review, codebase cross-reference)

---

## Context

MaphaPay is building a **minor account feature** (ages 6–17) to support financial literacy, independence, and long-term loyalty. The feature is positioned as "MaphaPay Grow" (6–12) and "MaphaPay Rise" (13–17), with a transition period culminating in automatic upgrade to a personal account at age 18.

### Why This Matters

- **Market opportunity:** Match Revolut's 6–17 age range to capture full segment
- **Loyalty building:** Support gradual independence → retain users into adulthood
- **Differentiation:** African-first features (mobile money remittances, savings groups, local merchant integration) competitors can't easily copy
- **Social impact:** Teach financial literacy and responsibility in a mobile-first, family-centric way

---

## Design Overview

### **1. Account Structure & Data Model**

**New account_type:** `'minor'` (alongside 'personal', 'merchant', 'company')

**Key Entities:**
- Minor account linked to parent account via `parent_account_id`
- Parent role on minor account: `'guardian'` (can control, monitor, approve)
- Child role on minor account: `'child'` (permissions escalate via level system)
- Each child has own wallet, transactions, and progression level
- Siblings in same family can have separate accounts with family visibility features

**Multi-Child Support:**
- One parent can manage multiple minor accounts (multiple children)
- Siblings see each other's levels and shared family goal progress
- Each child has isolated spending and transactions (privacy enforced)

**Account Transition (Age 18):**
- Auto-converts from `'minor'` → `'personal'` account type
- Child completes full adult KYC before taking ownership
- Parent loses guardian access; child becomes owner
- Balance and transaction history carry over; older data anonymized per GDPR

---

### **2. Permission Unlock Levels**

Eight progressive levels tied to age + demonstrated behavior. Parent can accelerate child to next level if maturity shown.

| Level | Age Range | Tier | Key Permissions | Daily Limit | Monthly Limit |
|-------|-----------|------|---|---|---|
| **1** | 6–7 | Grow | View balance, see transactions | View-only | View-only |
| **2** | 8–9 | Grow | Complete chores, earn points, basic learning | View-only | View-only |
| **3** | 10–11 | Grow | Spend within parent limits, create personal goals | 500 SZL | 5,000 SZL |
| **4** | 12–13 | Grow→Rise | Join peer savings groups, earn rewards | 500 SZL | 5,000 SZL |
| **5** | 14–15 | Rise | Request higher limits, initiate transfers | 1,000 SZL | 10,000 SZL |
| **6** | 16–17 | Rise | International transfers (with parent OK), larger purchases | 2,000 SZL | 15,000 SZL |
| **7** | 17 | Rise | Voluntary early takeover (child requests, parent approves) | Negotiated | Negotiated |
| **8** | 18+ | Personal | Full autonomy, account ownership | No limits | No limits |

**Acceleration:** Parent can advance child to next level early (e.g., "You've shown responsibility; unlock Level 5 at age 13").

---

### **3. Core Features**

#### **A. Points & Rewards System**

**Earn Points:**
- Saving milestones: 100 SZL saved = 50 pts, 500 SZL = 200 pts, 1,000 SZL = 500 pts
- Completing chores: 10–50 pts per chore (parent-configurable)
- Financial literacy modules: 25–100 pts per module (age-tiered)
- Level unlocks: 100 pts bonus when advancing
- Parent referrals: 200 pts per successful invite

**Redeem Points (Real Eswatini Value):**
- MTN airtime: 100 pts = 50 SZL airtime
- MTN data bundles: 150 pts = 1GB bundle
- Local merchant vouchers: 200 pts = 100 SZL (groceries, clothing, entertainment)
- Social good: Donate to UNICEF/local charities
- Points never expire (long-term engagement)

**Parent Rewards:**
- 200 pts per child referral
- 50 pts per month for consistent engagement
- Redeemable for family experiences or donated to child's account

#### **B. Chore-to-Allowance Automation**

- Parent creates recurring or one-off chores (e.g., "Clean room by Sunday, 50 pts")
- Child marks complete with photo/text confirmation
- Parent approves → auto-payment to child's wallet
- Teaches accountability and real-world task-reward connection

#### **C. Shared Family Goals**

- Parent + child set joint savings goals (e.g., "Family vacation: 10,000 SZL by June")
- Progress bar shows both contributions
- Real-time notifications on milestones
- Celebrate achievements (in-app badges, confetti)
- Can be funded by multiple family members (grandparents, aunts, etc.)

#### **D. Sibling Visibility (Family Tab)**

- Multiple kids in same family can see:
  - Each other's current level and badges
  - Shared family goal progress
  - Recent milestone celebrations
- Friendly competition ("Your brother reached Level 5; you're close!")
- Privacy maintained: Transaction details hidden

#### **E. Parent-Child Financial Coaching**

- Smart nudges: "You've spent 60% of monthly limit with 2 weeks left"
- Educational moments: "Saving 10% of allowance = 1,200 SZL/year. Here's why that matters"
- Behavioral insights: "You're great at saving for goals; try a 3-month savings challenge"
- Non-punitive, coaching-focused tone

#### **F. African-First Mobile Money Integration**

**Family Remittances:**
- Parent transfers to extended family via MTN MoMo
- Child can contribute to family pool (e.g., "Grandma's medical fund")
- Auto-deduction of child's contribution each payday
- Child sees their impact ("You've contributed 500 SZL this year")

**Informal Savings Groups (Rise tier only):**
- Teen joins 5–10-person savings pools (school friends, neighbors)
- Pool toward shared goal (school trip, joint purchase)
- Each member sees stake + group progress
- Parent approves group membership
- Teaches cooperative saving culture

**Local Merchant QR Integration:**
- Earn 2x points on QR payments at partnered Eswatini merchants
- Merchant loyalty badges (support local)
- Spending data visible to parent (for coaching)

---

### **4. Parental Controls (Comprehensive)**

| Control | Grow (6–12) | Rise (13–17) | Mechanism |
|---------|---|---|---|
| **Spending Limits** | Daily: 500 SZL, Monthly: 5,000 SZL | Daily: 2,000 SZL, Monthly: 15,000 SZL | Parent adjusts per level/behavior |
| **Merchant Category Blocks** | Block: alcohol, tobacco, gambling, high-risk | Block: alcohol, tobacco, gambling | Parent toggles categories |
| **Transaction Approval** | All spend >100 SZL requires parent OK | Spend >1,000 SZL requires parent OK | Parent approves in-app (24h) |
| **Visibility** | See all transactions, balance, goals | See all transactions, balance, goals | Real-time push notifications |
| **Lock/Unlock** | Freeze account instantly (discipline/emergency) | Freeze account instantly | Notification sent to child |
| **Recurring Allowance** | Set weekly/monthly recurring transfers | Set weekly/monthly recurring transfers | Auto-transfer on schedule |
| **Behavioral Alerts** | Flag unusual spending patterns | Flag unusual spending patterns | AI fraud monitoring |
| **Level Approval** | Parent approves level advancement | Parent approves level advancement | Child can request, parent decides |

**Enforcement:**
- Parent can revoke permissions instantly
- Child notified of all changes
- Decline reasons shown to child (educational)

---

### **5. Compliance & Regulatory**

**KYC Requirements:**
- Parent's existing MaphaPay verification = anchor KYC
- Child provides: full name, DOB, photo ID (optional; school ID acceptable)
- No additional biometric collection

**Transaction Monitoring:**
- Eswatini-aligned limits: Grow (5,000 SZL/month), Rise (15,000 SZL/month)
- Auto-flagging for fraud (rapid transactions, unusual patterns)
- Manual review for high-risk transactions (international transfers, large sums)

**Data Privacy:**
- Parent can see all child transactions (no child privacy from parent)
- No location tracking, biometric profiling, or behavioral tracking
- Data purged at age 18 (GDPR + POPIA alignment)
- Child data anonymized in analytics

**Regulatory Alignment:**
- Eswatini: FATF standards, simplified due diligence for minors
- Regional: South Africa POPIA (if expanding)
- International: GDPR-aligned data deletion

**Account Closure & Termination:**
- Parent can close account before age 18 (with notice period)
- Account auto-converts to personal at 18 (no closure option)
- Funds remain accessible; transaction history archived

---

### **6. User Flows**

#### **Parent Setup Flow**
1. Parent opens MaphaPay (existing authenticated user)
2. Taps "Add child account" in account switcher (alongside existing "Add Another Account")
3. App auto-detects tier from DOB: 6–12 → Grow, 13–17 → Rise
4. Enters child's name, DOB, optional ID photo (school ID acceptable)
5. Selects initial level (default = age-matched level, parent can accelerate)
6. Sets spending limits and merchant blocks (pre-populated from level defaults)
7. Confirms child account created
8. **If child is 12+:** App generates invite code → parent shares via SMS/WhatsApp → child downloads MaphaPay, enters code, creates PIN
9. **If child is 6–11:** Setup complete. Child accesses via Kid Mode on parent's device
10. **Optional:** Invite co-guardian (sends invite link, co-guardian creates their own login)

#### **Child Onboarding Flow — Kid Mode (Ages 6–11)**
1. Parent taps child's name in account switcher → app enters Kid Mode
2. Simplified "Grow" dashboard: level badge, chore list, points balance, savings goal progress
3. Child can mark chores complete (photo/text confirmation)
4. Child can view their points and badges
5. All spending locked (Level 1–2 permissions)
6. Parent taps "Exit Kid Mode" → returns to parent dashboard

#### **Child Onboarding Flow — Independent Login (Ages 12–15)**
1. Child downloads MaphaPay from App Store / Play Store
2. Taps "I have an invite code" on login screen
3. Enters 8-character invite code from parent
4. Creates 6-digit PIN (no phone number required)
5. First login: app detects `account_type === 'minor'` → renders Rise dashboard
6. Dashboard shows: level progress bar, current permissions, chores, points balance, savings goals
7. Child can spend within limits, complete chores, redeem points, join savings groups (with parent approval)

#### **Child Onboarding Flow — Enhanced Login (Ages 16–17)**
1. Same as 12–15, but child can optionally add own phone number
2. Phone number enables: SMS verification for high-value transactions, password recovery
3. Prepares for age-18 KYC transition (phone number will be required)

#### **Co-Guardian Setup Flow**
1. Primary guardian taps "Invite co-parent" in child's settings
2. App generates invite link (valid 72 hours)
3. Co-guardian opens link → logs in with their MaphaPay account (or creates one)
4. Co-guardian sees child in their account switcher with `co_guardian` role
5. Co-guardian can: view transactions, approve spending, top up balance, manage chores
6. Co-guardian cannot: change limits, close account, change level, remove primary guardian

#### **Progression & Level Unlock**
- Parent monitors behavior in-app (spending patterns, chore completion rate, savings progress)
- Parent taps "Unlock Level 5" in child's settings → confirmation screen explains new permissions
- Child notified via push notification + in-app banner: "You've unlocked international transfers!"
- Level 7 (early takeover) is special: child must REQUEST it → parent reviews → approves/denies

#### **Account Transition at 18**
- 90 days before 18th birthday: In-app prompt to child + parent: "Prepare for account transition"
- 60 days before: Child prompted to add phone number (if not already) and begin KYC
- Child completes verification (government ID, selfie, address proof)
- At 18: Auto-conversion from `minor` → `personal` account type
- Primary + co-guardian access revoked; child becomes sole owner
- Balance, transaction history, points, and savings goals carry over
- Child PII anonymized from guardian's view; transaction records retained 7 years for compliance
- If KYC not completed by 18: Account frozen (no spending), 30-day grace period to complete

#### **Virtual Card Flow (Rise Tier, Ages 13+)**
1. Parent or child (Level 5+) requests virtual card from child's wallet screen
2. Parent approves card issuance (notification + confirmation)
3. Virtual card generated → visible in child's wallet (card number, expiry, CVV)
4. Card spending limits mirror account-level limits (daily/monthly)
5. Parent can freeze card independently of account
6. Card works for online purchases and contactless payments (Apple Pay / Google Pay)
7. Merchant category blocks enforced at card level (alcohol, tobacco, gambling blocked)

---

### **7. Technical Implementation Scope**

**Backend (Laravel):**
- New account_type `'minor'` in accounts table
- `parent_account_id` foreign key relationship
- `permission_level` column (1–8) on minor accounts
- `minor_guardians` table: `minor_account_id`, `account_id`, `role: 'primary' | 'co_guardian'`, `permissions: json`
- Invite code system: 8-char unique codes with 72-hour expiry for child onboarding + co-guardian invites
- Child auth: PIN-based login without phone number (ages 12–15), optional phone (16–17)
- Points system: extend existing rewards (`/api/rewards`) with minor-specific earning triggers
- Chore table: parent-created tasks, child completion/photo, auto-payment logic
- Family goals: extend existing SavingsPocket with multi-contributor support
- Teen savings groups: extend existing GroupPocket with `requires_guardian_approval` flag
- Merchant category blocklist enforcement (reuse existing budget category slugs)
- Transaction limits & approval workflow (with parent-customizable overrides per child)
- Virtual card issuance for Rise tier (ages 13+)
- Compliance monitoring (fraud flags, daily/monthly caps)
- Level progression rules engine (age-based + manual + Level 7 takeover request)
- Nightly batch job: DOB-based tier transitions (Grow → Rise) + age-18 auto-conversion
- Parent account cascade: parent deletion/freeze → child account freeze + notification

**Mobile (React Native):**
- Update `AccountSummary.account_type` to include `'minor'`
- Update `AccountRole` to include `'guardian'`, `'child'`, `'co_guardian'`
- Update `useAccountPermissions` with minor-specific permissions
- Update `AccountSwitcherSheet` with minor account display, Kid Mode toggle, "Create Child Account" entry
- Dual-mode UI: detect `account_type === 'minor'` on login → render child dashboard
- Kid Mode (ages 6–11): simplified overlay on parent's device
- Independent child login (ages 12+): invite code + PIN auth flow on login screen
- Parent dashboard: child list, quick actions, insights, co-guardian management
- Child dashboard: level progression bar, goals, chores, points shop, virtual card
- Shared family views: goal progress, sibling achievements
- Financial learning module integration (3–5 min age-appropriate lessons)
- Points redemption flow: extend existing `useRewards` with child-scoped queries
- Real-time notifications (chore completion, spending alerts, milestone celebrations)
- Virtual card display + Apple Pay / Google Pay provisioning (Rise tier)

**Merchant Integration:**
- Partner with Eswatini merchants (grocery, retail, airtime)
- QR-pay bonus tracking (2x points)
- Merchant discovery in-app

**Third-Party Integrations:**
- MTN MoMo API (family remittances, airtime redemption)
- KYC provider (adult verification at age 18)
- Fraud detection service (transaction monitoring)
- Card issuer (virtual card provisioning for Rise tier)

---

### **8. Success Metrics**

**Engagement:**
- Parent onboarding completion: 80%+ finish setup
- Child weekly active users: 60%+
- Feature adoption: % using chores, goals, savings groups
- Points redemption rate: % of earned points redeemed

**Financial Literacy:**
- Modules completed per child
- Average savings rate (% of allowance saved vs. spent)
- Family goal achievement rate

**Retention & Loyalty:**
- Month-over-month retention: 85%+ by month 6
- Upgrade to personal account at 18: 70%+ of Rise users
- Parent referrals (viral coefficient)

**Business:**
- Merchant engagement (foot traffic from QR bonuses)
- Revenue per family (referrals, future premium tier)

---

### **9. Known Constraints & Open Questions**

**Constraints:**
- Eswatini e-KYC system is new (launched 2025); integration may require coordination with Central Bank
- MTN MoMo partnerships require vendor agreements
- Merchant QR-pay ecosystem in Eswatini is still developing

**Resolved Decisions (2026-04-16):**

**Decision 1: Child Authentication Model → Single App, Dual Mode (Option C)**
- One MaphaPay app for everyone. No separate "kids app."
- Ages 6–11 (Kid Mode): Parent taps child's name in account switcher → simplified "Grow" dashboard on parent's device. No separate credentials.
- Ages 12–15 (Independent Login): Parent sends invite → child downloads MaphaPay → enters invite code → creates 6-digit PIN. Auth: invite code + PIN (no phone number required).
- Ages 16–17 (Enhanced Login): Child can optionally add own phone number for SMS verification and password recovery. Prepares for age-18 KYC.
- Age 18 (Transition): Phone number required for full KYC. Same app, same login — account type changes from `minor` to `personal`.
- **Rationale:** Child 12+ gets ownership ("my app"), parent manages from same app, child already has MaphaPay installed at 18 (zero-friction transition). Leverages existing multi-account architecture.

**Decision 2: Multi-Guardian → Yes, from launch**
- Support primary guardian + co-guardian(s) per minor account.
- Backend: `minor_guardians` table with `minor_account_id`, `account_id`, `role: 'primary' | 'co_guardian'`, `permissions: json`.
- Primary guardian: full control (limits, blocks, level approval, account closure).
- Co-guardian: view transactions, approve spending, top up balance, manage chores. Cannot change limits or close account.
- Co-guardian added via invite link from primary guardian.

**Decision 3: Virtual Card → Yes, in scope for Rise tier (13+)**
- Virtual card issuance for Rise tier (ages 13+) included in implementation.
- Physical card ordering deferred to future phase (requires card vendor partnership).
- Card-level spending controls mirror account-level controls (daily/monthly limits, merchant blocks).
- Card can be frozen independently of account (parent control).

**Remaining Open Questions:**
- Should informal savings groups have interest/rewards mechanics, or just pooling?
- What's the threshold for parent approval on Rise-tier transactions (1,000 SZL)?
- Interest rate on child savings pockets (2% or 3%)?

---

### **10. Audit Findings & Gap Analysis (2026-04-16)**

#### **10a. Existing Infrastructure to Extend (NOT Rebuild)**

The codebase already has six systems that the minor accounts feature should extend:

| Feature Need | Existing System | File Path | Action |
|---|---|---|---|
| Points & Rewards | `src/features/rewards/` (PointsData, Reward, useRewards) | `src/features/rewards/api/useRewards.ts` | Extend with minor-specific earning triggers + child-scoped queries |
| Teen Savings Groups | `src/features/group-savings/` (GroupPocket, contributions, withdrawal approval) | `src/features/group-savings/api/useGroupPockets.ts` | Add `requires_guardian_approval` flag + age restrictions |
| Recurring Allowances | Scheduled Sends (useScheduledSend, status tracking) | `src/features/send-money/api/useScheduledSend.ts` | Reuse with `subtype: 'allowance'` |
| Family Goals | SavingsPocket with SmartRules (targets, progress, auto-save, lock) | `src/features/savings/api/usePockets.ts` | Extend with multi-contributor support |
| Merchant Category Blocking | Budget Categories (BudgetCategoryLine, slug-based matching) | `src/features/wallet/hooks/useWalletBudget.ts` | Use existing category slugs for block rules |
| Notifications & Real-time | Echo/WebSocket + push notifications + useNotifications | `src/core/realtime/echoClient.ts` | Add minor event channels: `minor.spending_alert`, `minor.chore_completed` |

#### **10b. Critical Gaps Found**

**GAP 1: Child Authentication & Login Model (CRITICAL)**
- Ages 6–11: How does the child see their dashboard? Profile switch within parent's app? "Kid mode" toggle? Separate app?
- Ages 12+: Child needs credentials but may not have a phone number. Invite link + PIN-only auth? Username-based login?
- **Decision required before any child-facing UI work.**

**GAP 2: Physical/Virtual Card for Children**
- Revolut and GoHenry both give children prepaid debit cards. Without a card, minor accounts are limited to in-app QR payments and peer transfers.
- **Recommendation:** Launch without card (Phase 1), add virtual card for Rise tier (Phase 2), physical card as Phase 3.

**GAP 3: Parent Account Closure/Freeze — Orphaned Minors**
- If parent deletes their account or gets frozen for fraud, what happens to child accounts?
- **Policy needed:** Freeze child accounts + notify, with 30-day grace period to transfer guardianship or close with balance refund.

**GAP 4: Multi-Guardian (Co-Parents)**
- Critical for divorced families, grandparent caregivers, etc.
- Revolut supports admin parent + co-parent with limited controls.
- **Recommendation:** Support from launch. Guardian table: `minor_account_id`, `account_id`, `role: 'primary' | 'co_guardian'`, `permissions: json`.

**GAP 5: Emergency Access**
- Child needs money urgently, parent unavailable to approve.
- **Recommendation:** "Emergency allowance" — parent pre-sets a reserve (e.g., 200 SZL) the child can access without approval. Configurable, default off.

**GAP 6: Age Tier Auto-Transition (Grow → Rise)**
- A 12-year-old turns 13: does Grow become Rise automatically? Do limits auto-adjust?
- **Recommendation:** Nightly batch job checks DOB, auto-updates tier + suggests level advancement to parent. Parent gets notification: "Emma turned 13 — review Rise tier permissions."

**GAP 7: External Family Funding**
- Grandparents, aunts want to send money to child's wallet. How do they find it?
- **Recommendation:** "Child funding link" — parent shares a unique URL/QR. External family can send via MTN MoMo or MaphaPay transfer. No login required for sender.

**GAP 8: Interest on Child Savings**
- Greenlight offers 5% interest on child savings. Revolut offers zero.
- **Recommendation:** Offer 2–3% simulated interest on savings pockets (funded by MaphaPay as a growth incentive). Differentiator.

**GAP 9: Chore Dispute Resolution**
- Child marks chore complete, parent disagrees. What happens?
- **Recommendation:** "Reject with reason" → chore returns to "pending" with parent's feedback note. No points deducted. Re-completion allowed.

**GAP 10: Spending Insights for Children**
- App already has budget categories and spending breakdowns (BudgetCategoryLine).
- **Recommendation:** Adapt existing budget view for children — age-appropriate labels, simplified categories, visual spending charts.

#### **10c. Files That Must Be Updated (Not in Original Plan)**

| File | Required Change |
|---|---|
| `src/features/account/domain/types.ts` | Add `'minor'` to `account_type` union |
| `src/features/account/hooks/useAccountPermissions.ts` | Add `'guardian'` and `'child'` to `AccountRole`, add minor-specific permissions (`canManageChores`, `canApproveSpending`, `canViewChildAccounts`) |
| `src/features/account/presentation/AccountSwitcherSheet.tsx` | Add `'minor'` to AccountType, add ACCOUNT_TYPE_INFO entry for minor accounts, show level badge for children, add "Create Child Account" button |
| `src/features/account/store/accountStore.ts` | Handle minor account context (child vs parent view), parent-scoped children list |
| `src/core/api/apiClient.ts` | May need `X-Minor-Account-Id` header for child-scoped API calls when parent is viewing child's data |

#### **10d. Edge Cases to Handle**

1. **Child turns 18 mid-transaction** — Pending approvals should complete under existing rules; auto-conversion queues behind pending transactions
2. **Parent has multiple accounts (personal + merchant)** — Only `personal` accounts can be guardians. Enforce in policy.
3. **Duplicate child detection** — Same DOB + name under different parents. Allow (different families can have children with same name), but warn within same parent.
4. **Points at age 18** — Points earned on minor account transfer to personal account. No reset.
5. **Mixed savings groups (minors + adults)** — Block. Rise-tier teens can only join groups with other minors. Parent approval required.
6. **Data retention vs. purge at 18** — Anonymize PII (name, DOB) but retain transaction records for 7 years per financial regulations. Distinguish between "purge PII" and "purge all data."

#### **10e. Level 7 Design Fix**

Level 6 (16–17) and Level 7 (17) both cover age 17 — the `forAge()` query would never return Level 7.

**Fix:** Level 7 is NOT age-based. It's a parent-granted "early takeover" level. Child at Level 6 can *request* Level 7. Parent approves. `forAge()` returns max Level 6; Level 7 is only set via `POST /api/minor-accounts/{id}/grant-takeover`.

#### **10f. Competitive Advantages to Exploit**

| Competitor Gap | MaphaPay Opportunity |
|---|---|
| Revolut offers **zero interest** on Junior savings | Offer 2–3% on child savings pockets |
| GoHenry charges a **monthly fee** ($9.98/mo) | Keep MaphaPay Grow/Rise **free** |
| Neither offers **family remittances** | African-first: MTN MoMo family funding |
| Neither offers **financial coaching AI** | Smart nudges based on spending patterns |
| Neither supports **cooperative savings groups** | GroupPocket already built — extend for teens |
| Revolut limits withdrawals to **€60/month** | Parent-configurable limits (more flexible) |
| No competitor offers **interest on child savings** in Africa | First-mover in Eswatini market |

---

### **11. Verification & Testing**

**Mobile Testing:**
- Parent setup flow end-to-end
- Child progression through levels 1–8
- Chore creation, completion, dispute (reject + re-do), auto-payment
- Family goal tracking with multiple contributors
- Points earning and redemption (airtime, vouchers)
- Sibling visibility and friendly competition
- Parental controls (limit changes, freezing, merchant blocks)
- Account transition at simulated age 18
- Child authentication (ages 6–11 parent mode, ages 12+ independent login)
- Multi-guardian scenarios (co-parent access)
- Emergency allowance access
- External family funding via shared link

**Backend Testing:**
- Transaction limit enforcement per level
- Merchant category blocking logic (using existing budget category slugs)
- Level advancement rules (age-based + manual + Level 7 takeover)
- Points ledger accuracy (extending existing rewards system)
- Chore auto-payment timing
- Fraud detection flags (unusual hours, rapid transactions, blocked categories)
- Tier auto-transition (Grow → Rise on birthday)
- Parent account deletion → child account freeze cascade
- Data anonymization at age 18 (PII only; retain transaction records)

**Compliance Testing:**
- KYC verification workflow
- Data retention: 7-year transaction records + PII anonymization at 18
- GDPR/POPIA alignment (right to erasure vs. financial record retention)
- Eswatini transaction limit compliance
- Simplified due diligence documentation

**User Acceptance:**
- Parent focus group (setup ease, control intuitiveness)
- Child/teen focus group (engagement, motivation, learning)
- Family test (multi-child, goal collaboration, sibling competition)
- Divorced family test (co-guardian, split controls)

---

## Revised Implementation Phases

### Pre-Phase: Update Existing Infrastructure
- Update `AccountSummary`, `AccountRole`, `useAccountPermissions`, `AccountSwitcherSheet`
- Design child authentication model (parent-mode vs. independent login)

### Phase 1: Backend Core (Account model, permissions, guardian relationships)
### Phase 2: Backend Controls (Limits, blocks, approval workflow, emergency allowance)
### Phase 3: Backend Rewards & Chores (Extend existing rewards + new chore system)
### Phase 4: Backend Family Features (Extend SavingsPocket + GroupPocket for family/teen goals)
### Phase 5: Mobile Parent Dashboard (Create child, manage children, controls)
### Phase 6: Mobile Child Dashboard (Level bar, spending insights, chores, points)
### Phase 7: Mobile Family Features (Goals, siblings, learning modules)
### Phase 8: Mobile Rewards & Shop (Extend existing redemption flow for children)
### Phase 9: Backend Integrations (MTN MoMo remittances, external family funding links)
### Phase 10: Backend Tier Automation (Birthday transitions, interest on savings, fraud rules)
### Phase 11: Merchant & QR Integration (Partnership infrastructure, QR bonuses)
### Phase 12: Card Support (Virtual card for Rise tier 13+)
### Phase 13: Testing, Compliance, Beta Launch

**Total estimated scope: 14–19 weeks** (expanded from 12–17 to account for gaps)

---

## Next Steps

1. ✓ Design complete and approved
2. ✓ Comprehensive audit completed (10 gaps identified, 6 existing systems to reuse, 6 edge cases documented)
3. → Resolve critical decisions (child auth model, multi-guardian, card scope)
4. → Create detailed implementation plan per phase
5. → Implement Pre-Phase (update existing types and hooks)
6. → Implement Phases 1–13
7. → Soft launch with beta group
8. → Full rollout

