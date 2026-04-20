# Phase 8 Implementation Brief — Minor Accounts: Mobile Rewards & Shop

## Your Task

Implement Phase 8 of MaphaPay Minor Accounts: the **Rewards & Shop** feature. This covers the backend API layer (Laravel) and the mobile UI (React Native).

**Spec files (read both before writing a single line of code):**
- Backend: `docs/superpowers/specs/2026-04-20-minor-accounts-phase8-mobile-rewards-shop.md`
- Mobile: `docs/superpowers/specs/2026-04-20-minor-accounts-phase8-mobile-rewards-shop-ui.md`
- Original vision: `/Users/Lihle/.claude/plans/curious-toasting-kitten.md`

---

## Repositories

| Repo | Path | Work |
|------|------|------|
| Backoffice (Laravel) | `/Users/Lihle/Development/Coding/maphapay-backoffice` | Backend APIs, migrations, services |
| Mobile (React Native) | `/Users/Lihle/Development/Coding/maphapayrn` | Screens, hooks, state management |

---

## What to Build

### Backend (Laravel — implement first)
1. **Migrations** — 6 new tables: `minor_rewards` (extend existing), `minor_reward_redemptions`, `minor_redemption_approvals`, `merchant_partners`, `merchant_redemption_queue`, `merchant_qr_transactions`
2. **Services** — `MinorRewardService`, `MinorRedemptionService`, `MerchantRedemptionService`
3. **Controllers** — `MinorRewardsController` (child), `AdminMinorRewardsController` (parent), `MerchantRedemptionsController`
4. **Routes** — Wire into `app/Domain/Account/Routes/api.php`
5. **Notifications** — Extend `MinorNotificationService` with Phase 8 types
6. **Tests** — Unit + integration tests for redemption lifecycle, approval workflow, merchant fulfillment, QR bonus

### Mobile (React Native — implement after backend)
1. **Domain types** — `rewardTypes.ts`, `redemptionTypes.ts`, `merchantTypes.ts`
2. **Hooks** — `useMinorRewardsCatalog` (useInfiniteQuery), `useMinorRewardDetail`, `useSubmitRedemption`, `useMinorRedemptionOrders`, `useParentRedemptionApprovals`
3. **Screens** — `RewardsDashboardWidget`, `RewardsCatalogScreen`, `RewardDetailModal`, `RedemptionFlowModal` (4-step), `OrderHistoryScreen`, `OrderDetailScreen`
4. **Navigation** — Add Shop + MyRewards tabs to `KidDashboard`
5. **Tests** — 80+ source-reading tests (see existing pattern in `tests/features/minor-accounts/`)

---

## Non-Negotiable Rules

- **TDD first** — write failing test, verify it fails, then implement. No code before tests.
- **`declare(strict_types=1)`** on every PHP file
- **`theme.colors.*` only** — no hardcoded hex/rgba in React Native
- **48dp minimum** tap targets on all buttons
- **`enabled: !!uuid`** guard on all React Query hooks
- **`staleTime: 1000 * 60 * 5`** on all queries
- **Validate `response.data.success`** before using data; throw on false
- **`useInfiniteQuery`** for the catalog hook (needs `hasNextPage` + `fetchNextPage`)
- No nested `Pressable` components (touch conflict bug)
- Sanctum: always `['read', 'write', 'delete']` abilities in tests

---

## Key Decisions (already made — do not re-debate)

- Parent approval triggered when `reward.price_points > redemption_approval_threshold` (default 250). Points deducted AFTER approval, not before.
- `stock = -1` means unlimited; `stock = 0` means sold out; block redemption on 0.
- "Save for Later" is app-state only — no backend persistence in Phase 8.
- ParentApproval is a post-submit waiting state (Step 4 variant), not a numbered checkout step.
- Non-standard MD3 tokens (`warning`, `info`, `success`) must be replaced with `tertiary`, `primary`, `secondary` or added as custom theme tokens before use.

---

## Commit Format

```
feat(minor-accounts): <description>

Co-Authored-By: Claude Haiku 4.5 <noreply@anthropic.com>
```

---

## Definition of Done

- [ ] All 6 migrations run cleanly on a fresh tenant DB
- [ ] All backend services tested (unit + integration)
- [ ] All API endpoints respond correctly (child, parent, merchant scopes)
- [ ] 80+ mobile tests passing
- [ ] Zero hardcoded colours in mobile code
- [ ] All buttons ≥ 48dp
- [ ] KidDashboard has Shop + MyRewards tabs wired
- [ ] PHPStan Level 8 passes
- [ ] `./vendor/bin/pest --parallel` passes
