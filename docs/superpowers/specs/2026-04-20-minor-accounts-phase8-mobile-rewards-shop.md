# Minor Accounts Phase 8: Mobile Rewards & Shop Specification

**Date:** 2026-04-20  
**Phase:** 8 of 13  
**Status:** Design — Ready for Implementation  
**Scope:** Mobile Rewards & Shop (child-facing redemption UI, parent controls, merchant integration)

---

## Executive Summary

Phase 8 delivers the **child-facing rewards shop experience** — the visual expression of the points-earning system built in Phase 4. Children see earned points, browse redeemable rewards (airtime, data, merchant vouchers, social good), complete redemptions, and track order status. Parents monitor redemptions, set approval requirements, and track spending patterns. Merchants contribute to a shared reward pool via QR-pay bonuses and loyalty badges.

**Key Deliverables:**
- Child rewards dashboard (points balance, earning progress, achievement badges)
- Reward catalog browser (filters, search, details, redemption flow)
- Redemption order history and tracking (pending, completed, failed)
- Parent rewards management (approval workflow, limits, analytics)
- Merchant partner dashboard (QR-pay bonuses, fulfillment, performance)
- Backend APIs (catalog queries, redemption submissions, order management)
- Real-time notifications (redemption confirmed, reward shipped, bonus awarded)

**Preconditions:** Phases 1–7 complete. Phase 4 points ledger and rewards catalog seeded. Phases 5–7 mobile infrastructure (auth, account switcher, real-time updates) in place.

---

## Architecture Overview

### System Components

```
┌─────────────────────────────────────────────────────────────┐
│                     Mobile App (React Native)               │
├─────────────────────────────────────────────────────────────┤
│  Child Dashboard                                             │
│  ├─ Points Balance Display                                  │
│  ├─ Achievement Badges (milestones, level-unlock, streaks)  │
│  ├─ Earning Progress (toward next reward)                   │
│  └─ Quick Redeem CTAs                                       │
│                                                              │
│  Rewards Shop                                               │
│  ├─ Catalog (paginated, filtered)                           │
│  ├─ Reward Detail (price, description, stock, proof)        │
│  ├─ Redemption Flow (confirm points, shipping/delivery)     │
│  └─ Share Reward (social, WhatsApp, SMS)                    │
│                                                              │
│  Order History                                              │
│  ├─ Pending (awaiting approval, fulfillment)                │
│  ├─ Active (processing, in-transit)                         │
│  ├─ Completed (delivered, redeemed)                         │
│  └─ Failed (cancelled, expired, out-of-stock)               │
│                                                              │
│  Earning Progress Tracker                                   │
│  ├─ Points breakdown by source (chores, milestones, ref)    │
│  ├─ Leaderboard (siblings, friends, school)                │
│  └─ Challenges & Streaks (weekly save target, no splurge)   │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                  Backend API (Laravel)                      │
├─────────────────────────────────────────────────────────────┤
│  Rewards Service                                             │
│  ├─ Catalog queries (list, search, filter by category)      │
│  ├─ Redemption submission & validation                      │
│  ├─ Stock management (real-time availability)               │
│  └─ Points ledger mutations (record redemption cost)         │
│                                                              │
│  Order Management Service                                   │
│  ├─ Order creation & persistence                            │
│  ├─ Fulfillment tracking (pending → shipped → complete)     │
│  ├─ Failure handling (out-of-stock, expiry, reversal)       │
│  └─ Order history queries (scoped to minor account)         │
│                                                              │
│  Parent Controls Service                                    │
│  ├─ Redemption limits (daily, weekly, monthly)              │
│  ├─ Approval workflow (requires parent OK for > threshold)  │
│  ├─ Reward category blocks (block junk food, etc.)          │
│  └─ Analytics (spending by category, redemption rate)       │
│                                                              │
│  Merchant Integration Service                               │
│  ├─ QR-pay bonus tracking (2x multiplier on child spend)    │
│  ├─ Merchant partner queries (featured, nearby)             │
│  ├─ Performance analytics (redemptions, conversion)         │
│  └─ Payout calculations (merchant share of redemptions)     │
│                                                              │
│  Notification Service                                       │
│  ├─ Child: redemption confirmed, reward shipped, bonus      │
│  ├─ Parent: high-value redemption, approval required        │
│  └─ Merchant: fulfillment requests                          │
└─────────────────────────────────────────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────┐
│                  Database (MySQL)                           │
├─────────────────────────────────────────────────────────────┤
│  minor_rewards (catalog)                                    │
│  ├─ id, category, name, description, price_points, stock   │
│  ├─ image_url, partner_id, expiry_date, is_featured        │
│  └─ created_at, updated_at                                 │
│                                                              │
│  minor_reward_redemptions (orders)                         │
│  ├─ id, minor_account_id, reward_id, status                │
│  ├─ points_redeemed, quantity, shipping_address            │
│  ├─ created_at, completed_at, expires_at                   │
│  └─ merchant_reference, tracking_number                    │
│                                                              │
│  minor_redemption_approvals (parent workflow)              │
│  ├─ id, redemption_id, parent_account_id                   │
│  ├─ status (pending, approved, declined), reason           │
│  └─ approved_at, expires_at                                │
│                                                              │
│  merchant_partners (merchant registry)                      │
│  ├─ id, name, category, qr_endpoint, logo_url             │
│  ├─ commission_rate, payout_schedule, is_active            │
│  └─ created_at, updated_at                                 │
│                                                              │
│  merchant_redemption_queue (fulfillment)                    │
│  ├─ id, redemption_id, merchant_id, status                 │
│  ├─ submitted_at, completed_at, tracking_number            │
│  └─ failure_reason                                         │
└─────────────────────────────────────────────────────────────┘
```

### Data Flow: Redemption Lifecycle

```
Child views reward
       ↓
Child taps "Redeem" → Check parental approval setting
       ↓
points >= price AND stock > 0 AND approval_not_required?
       ├─ YES → Instant redemption
       │   ├─ Deduct points from ledger
       │   ├─ Create redemption order
       │   ├─ Decrement reward stock
       │   ├─ Queue for merchant fulfillment
       │   └─ Notify child + parent + merchant
       │
       └─ NO → Approval workflow
           ├─ Create approval request
           ├─ Notify parent
           ├─ Wait for parent decision (24h timeout)
           ├─ Parent approves?
           │   ├─ YES → Proceed with redemption
           │   └─ NO → Notify child, reject reason
           └─ Expired → Auto-cancel, restore points

Merchant fulfills order
       ├─ Receive via SMS/email/API
       ├─ Mark as "in_transit" 
       ├─ Send tracking number to child
       └─ Update order status → "completed"

Child receives reward
       └─ Celebrate in-app (confetti, badge unlock)
```

---

## Feature Specifications

### 1. Child Rewards Dashboard

**Location:** Primary tab after chore/points (Phases 5–6)

**Components:**

#### 1.1 Points Balance Widget
```
┌──────────────────────────┐
│  💰 1,250 Points        │
│  ↑ +50 from chores      │
│  🎁 4 rewards available │
└──────────────────────────┘
```
- Display: `minorPointsService.balance(childId)` formatted as integer
- Status: "pending" (awaiting approval) vs. "available" (ready to redeem)
- CTAs: "Shop Now", "Earn More"
- Tappable: opens "Earning Progress" detail modal

#### 1.2 Achievement Badges Strip
```
┌─────────┬─────────┬─────────┬─────────┐
│ 🌟 Save │ 💪 Chore│ 👑 Level│ 🚀 Bonus│
│ 500 SZL │ 20 Done │ 5      │ Parent  │
└─────────┴─────────┴─────────┴─────────┘
```
- Scrollable list of unlocked badges
- Each badge has icon, title, description
- Locked badges shown greyed out ("Earn 200 more points to unlock")
- Tap to see achievement details (when earned, next milestone)

#### 1.3 Earning Progress Bar
```
┌────────────────────────────────────┐
│ Next Reward: 50 SZL Airtime       │
│ ████████░░ 8 of 10 points         │
│ Estimated in 3 days               │
└────────────────────────────────────┘
```
- Shows next redeemable reward by price (lowest cost first)
- Progress bar: `(current_points / reward_price) * 100%`
- CTA: "View Similar Rewards" (filters catalog by price range)

#### 1.4 Quick Redemption Carousel
```
3 featured rewards below dashboard:
┌─────────────┬─────────────┬─────────────┐
│ 50 SZL MTN  │ 1GB Bundle  │ Drink       │
│ Airtime     │ Data        │ Voucher     │
│ 100 pts ✓   │ 150 pts ✗   │ 200 pts ✓   │
│ [Redeem]    │ [Soon]      │ [Redeem]    │
└─────────────┴─────────────┴─────────────┘
```
- Rotates daily (freshness)
- ✓ = affordable (points >= price), ✗ = out of reach
- Tapping card opens reward detail → redemption flow

**API Requirements:**
- `GET /api/minor-accounts/{childId}/points/balance` → `{ available_points: int, pending_points: int, total_earned: int }`
- `GET /api/minor-accounts/{childId}/achievements` → `[ { id, icon, title, unlocked_at?, target_value, current_value } ]`
- `GET /api/minor-rewards?featured=true&limit=3` → featured catalog entries

---

### 2. Reward Catalog Browser

**Location:** Shop tab

**Components:**

#### 2.1 Catalog Grid
- **Layout:** 2-column grid (mobile) with reward cards
- **Each Card:**
  ```
  ┌──────────────────┐
  │ [Product Image]  │
  │ MTN 50 SZL       │
  │ Airtime          │
  │ 100 pts          │
  │ ⭐ 4.5 (120)     │
  │ ✓ In Stock       │
  │ [Redeem]         │
  └──────────────────┘
  ```
  - Image: 200×200 px (from `reward.image_url`)
  - Title, category tag (color-coded)
  - Points cost (bold, large)
  - Star rating + review count (if Phase 8 includes reviews; optional)
  - Stock indicator ("In Stock" / "Low Stock" / "Out of Stock")
  - "Redeem" button (active if `points >= price` and `stock > 0`, otherwise disabled with reason)

- **Pagination:** Load 12 items initially, infinite scroll or "Load More"
- **Lazy Loading:** Images load on scroll

#### 2.2 Filter & Sort Panel
```
Filters:
├─ Category (Radio)
│  ○ All
│  ○ Airtime & Data
│  ○ Vouchers
│  ○ Experiences
│  └─ Social Good
├─ Price (Slider)
│  Min: 0 pts ─────○───── Max: 1,000 pts
├─ Stock (Checkbox)
│  ☑ In Stock Only
└─ Age-Appropriate (Auto-filtered per child's level)

Sort:
├─ Most Popular
├─ Newest
├─ Price: Low to High
├─ Price: High to Low
└─ Best Rating
```

- **Persistence:** Selected filters/sort saved to app state (SessionStorage)
- **Auto-filtering:** Age-inappropriate rewards hidden (e.g., tobacco, alcohol blocked for all minors)
- **Merchant Filter (optional):** "Shop by Store" (MTN, local grocer, etc.)

#### 2.3 Search Bar
- **Input:** Free-text search
- **Scope:** Reward name + description + category
- **Results:** Real-time typeahead or "Search" button
- **Empty State:** "No rewards match. Try a different search or browse by category."

#### 2.4 Reward Detail Modal
```
┌─────────────────────────────────┐
│ ← Back to Shop                 │
├─────────────────────────────────┤
│ [Large Product Image]           │
│                                 │
│ MTN 50 SZL Airtime              │
│ ⭐ 4.5 (120 reviews)            │
│                                 │
│ Price: 100 Points              │
│ ✓ In Stock (24 available)       │
│                                 │
│ Description:                    │
│ "Instant airtime credit. Works  │
│ on all MTN networks in Eswatini │
│ and SADC."                      │
│                                 │
│ Redemption Time: 5 mins         │
│ Validity: Immediate             │
│                                 │
│ How It Works:                   │
│ 1. Tap Redeem                   │
│ 2. Confirm your phone number    │
│ 3. Get airtime instantly        │
│                                 │
│ Parent Approval: ✓ Not Required │
│                                 │
│ 📢 Share                        │
│ [Facebook] [WhatsApp] [SMS]     │
│                                 │
│ [Redeem 100 Points] [Save]      │
└─────────────────────────────────┘
```

- **Product Info:** Name, category, star rating, stock level, price
- **Rich Description:** Markdown-formatted benefit statement
- **Redemption Timeline:** Estimated delivery/activation time
- **Validity:** When the reward expires or becomes unusable
- **Parent Approval Notice:** "This reward requires parent approval (set in your Rewards Settings)"
- **Share Widget:** Child can share reward with friends ("Check out this airtime deal!")
- **Save for Later:** Add to wishlist (stored in app state only — no backend persistence in Phase 8; resets on app restart)
- **[Redeem] CTA:** Opens redemption flow

**API Requirements:**
- `GET /api/minor-rewards?category=airtime&sort=popular&limit=12&offset=0` → paginated catalog
- `GET /api/minor-rewards/{rewardId}` → full detail + reviews + recommendations
- `GET /api/minor-rewards/search?q=airtime&limit=10`

---

### 3. Redemption Flow (Checkout)

**Triggered By:** Child taps "Redeem" on any reward

**Flow Steps:**

#### Step 1: Confirmation Modal
```
┌─────────────────────────────────┐
│ Redeem Reward?                  │
├─────────────────────────────────┤
│ [Reward Image]                  │
│ MTN 50 SZL Airtime              │
│                                 │
│ You'll spend: 100 Points        │
│ You'll have: 1,150 Points left  │
│                                 │
│ Delivery: 5 minutes             │
│ To: Your primary phone number   │
│                                 │
│ [Cancel] [Confirm]              │
└─────────────────────────────────┘
```

- Summarize reward, points cost, remaining balance
- Confirm delivery method (SMS, in-app notification, etc.)
- Show estimated redemption time
- Warn if this is a large redemption ("This is 10% of your monthly allowance — is that OK?")

**Validation:**
- `points_available >= reward.price`
- `reward.stock > 0`
- Child's permission level allows redemption of this category
- No redemption in last 24 hours (if rate-limiting is set by parent)

#### Step 2: Delivery Address (Conditional)
If reward is physical (voucher, merchandise):
```
┌─────────────────────────────────┐
│ Where should we send this?      │
├─────────────────────────────────┤
│ ☑ Home (on file)                │
│ "123 Main Street, Mbabane"      │
│                                 │
│ ○ School Address                │
│ ○ Alternative Address           │
│                                 │
│ [Edit] [Use This]               │
│                                 │
│ Note: Parent receives tracking  │
│       when order ships.         │
└─────────────────────────────────┘
```

- Pre-fill from `minor_account.default_shipping_address` (set by parent in Phase 5)
- Allow select of 2–3 saved addresses
- Require parent approval if address differs from profile

#### Step 3: Parent Approval (Conditional)
Triggered if:
- Reward cost > parent's `redemption_approval_threshold` (default: 250 pts)
- Reward is in blocked category for child's age/level
- Reward requires special approval (e.g., international experience)

```
┌─────────────────────────────────┐
│ Waiting for Parent Approval...  │
├─────────────────────────────────┤
│ [Reward Image]                  │
│ Museum Experience Voucher       │
│ 300 Points                      │
│                                 │
│ This reward costs 300 points,   │
│ which is over your approval     │
│ threshold of 250 points.        │
│                                 │
│ Parent notification sent.       │
│ They have 24 hours to respond.  │
│                                 │
│ [View Order Status] [Continue]  │
└─────────────────────────────────┘
```

- Show timeout (24h)
- Child can close modal and check status later
- If parent declines: Notify child with reason, restore points
- If timeout: Auto-cancel, restore points

#### Step 4: Success Confirmation
```
┌─────────────────────────────────┐
│ ✅ Reward Redeemed!             │
├─────────────────────────────────┤
│ 🎉 Confetti animation           │
│                                 │
│ MTN 50 SZL Airtime              │
│ Order #RW-1234567890           │
│                                 │
│ You spent: 100 Points           │
│ New balance: 1,150 Points       │
│                                 │
│ What happens next:              │
│ ✓ We'll send you 50 SZL airtime │
│   to +268 76 123 456 within     │
│   5 minutes.                    │
│                                 │
│ ✓ Your parent can track the     │
│   order from their dashboard.   │
│                                 │
│ [View Order] [Shop More] [Done] │
└─────────────────────────────────┘
```

- Celebratory animation (confetti, sound toggle)
- Order number + order status page link
- Points balance updated
- Share-this-moment button ("I just earned MTN airtime!")

**Error Handling:**
- **Out of Stock:** "This reward ran out! Check back tomorrow or explore similar rewards."
- **Stock Conflict (rare):** Child and another child both redeem simultaneously. Second redemption fails gracefully. Notify second child, offer alternatives.
- **Parent Declined:** Show reason ("You didn't complete your chores this week. Try again after Saturday.") + motivational message.
- **Network Error:** "Couldn't process. Try again or contact support." Save redemption draft locally.
- **Points Insufficient (shouldn't happen if validation worked):** "Not enough points. You need 15 more!" + "Earn points" CTA.

**API Requirements:**
- `POST /api/minor-accounts/{childId}/redemptions` → Create redemption order
  - Payload: `{ reward_id, quantity, shipping_address_id?, child_phone_number }`
  - Response: `{ order_id, status: 'pending|awaiting_approval|confirmed|failed', expires_at }`
- `GET /api/minor-accounts/{childId}/redemptions/{orderId}` → Order detail + tracking
- `POST /api/minor-accounts/{childId}/redemptions/{orderId}/cancel` → Child-initiated cancellation (restores points if within grace period)

---

### 4. Order History & Tracking

**Location:** "My Rewards" tab (alongside dashboard + shop)

**Components:**

#### 4.1 Order Status Tabs
```
┌───────────────────────────────────────────────┐
│ All  Pending  Active  Complete  Failed         │
└───────────────────────────────────────────────┘
```

- **All:** All redemptions, newest first
- **Pending:** Awaiting parent approval or merchant fulfillment
- **Active:** Processing, in-transit, ready for pickup
- **Complete:** Delivered, redeemed (with delivery proof if applicable)
- **Failed:** Cancelled, out-of-stock during checkout, parent declined, expired

#### 4.2 Order Card (List)
```
┌──────────────────────────────────┐
│ MTN 50 SZL Airtime               │
│ Order #RW-1234567890             │
│ 100 pts · Redeemed 3 days ago    │
│                                  │
│ Status: ✓ Delivered              │
│ Applied to: +268 76 123 456      │
│                                  │
│ [View Details] [Share]           │
└──────────────────────────────────┘

┌──────────────────────────────────┐
│ 1GB MTN Data Bundle              │
│ Order #RW-1234567891             │
│ 150 pts · 2 days ago             │
│                                  │
│ Status: ⏳ Awaiting Parent OK    │
│ Expires in 18 hours              │
│                                  │
│ [View Details]                   │
└──────────────────────────────────┘
```

- Reward name, order ID, points spent, time since redemption
- Current status badge (color-coded: pending=orange, active=blue, complete=green, failed=red)
- Key detail (phone, address, or next step)
- CTAs: View details, share (for completed), cancel (for pending)

#### 4.3 Order Detail Page
```
┌──────────────────────────────────┐
│ ← Back to My Rewards             │
├──────────────────────────────────┤
│ Order #RW-1234567890             │
│ Placed: Apr 20, 2026, 2:15 PM   │
│                                  │
│ ┌─ Reward ────────────────────┐  │
│ │ MTN 50 SZL Airtime          │  │
│ │ [Image]                     │  │
│ │ 100 Points                  │  │
│ └─────────────────────────────┘  │
│                                  │
│ ┌─ Status ────────────────────┐  │
│ │ ✓ Delivered                 │  │
│ │ Applied to: +268 76 123 456 │  │
│ │ Delivered: Apr 20, 2:21 PM │  │
│ │ (5 minutes after order)     │  │
│ └─────────────────────────────┘  │
│                                  │
│ ┌─ Timeline ──────────────────┐  │
│ │ ✓ 2:15 PM — Order placed    │  │
│ │ ✓ 2:16 PM — Parent confirmed│  │
│ │ ✓ 2:18 PM — Processed       │  │
│ │ ✓ 2:21 PM — Delivered       │  │
│ └─────────────────────────────┘  │
│                                  │
│ ┌─ Actions ──────────────────┐   │
│ │ 📢 Share Achievement        │   │
│ │ 💬 Leave Feedback           │   │
│ │ 📞 Contact Support          │   │
│ └─────────────────────────────┘   │
└──────────────────────────────────┘
```

- Order ID, timestamp, total cost
- Reward summary (image, name, category)
- Status timeline (visual, all steps)
- Delivery proof (screenshot, tracking number, confirmation SMS)
- Actions: Share, feedback (1–5 stars + comment), support contact
- Parent can see this page (scoped to their child account)

**Order Statuses & Messaging:**

| Status | Icon | Meaning | Next Step |
|--------|------|---------|-----------|
| `awaiting_approval` | ⏳ | Parent reviewing | Parent decides (24h timeout) |
| `approved` | ✓ | Parent confirmed | Merchant processes (24h) |
| `processing` | 🔄 | Merchant preparing | Delivery/activation (1–3h) |
| `in_transit` | 📦 | On the way | Delivery (1–5 days) |
| `delivered` | ✅ | Completed | Done, can leave feedback |
| `redeemed` | 🎉 | Used/activated | Done, can share |
| `cancelled` | ❌ | User/parent cancel | Points refunded |
| `failed` | ⚠️ | Out of stock / network error | Try again or contact support |
| `expired` | ⏰ | Approval timeout or reward expired | Points refunded |

**API Requirements:**
- `GET /api/minor-accounts/{childId}/redemptions?status=pending,active&limit=20&offset=0` → Orders by status
- `GET /api/minor-accounts/{childId}/redemptions/{orderId}` → Full detail + timeline
- `POST /api/minor-accounts/{childId}/redemptions/{orderId}/feedback` → Reward rating + comment
- `POST /api/minor-accounts/{childId}/redemptions/{orderId}/cancel` → Request cancellation

---

### 5. Earning Progress Tracker

**Location:** Insights/Profile tab (or expandable from dashboard)

**Components:**

#### 5.1 Points Breakdown
```
┌──────────────────────────────────┐
│ Total Earned (This Month)        │
│ 450 Points                       │
│                                  │
│ By Source:                       │
│ ┌────────────────────────────┐   │
│ │ 🧹 Chores         200 pts  │   │
│ │ 💰 Saving         150 pts  │   │
│ │ 🎁 Bonuses         50 pts  │   │
│ │ 🤝 Referrals        0 pts  │   │
│ │ 📚 Learning         0 pts  │   │
│ └────────────────────────────┘   │
│                                  │
│ Redeemed This Month: 100 pts    │
│ Available Balance: 1,150 pts    │
└──────────────────────────────────┘
```

- Visual breakdown by source (pie/bar chart)
- Filterable by month/week
- Tappable source → shows detailed history

#### 5.2 Earning Trend (Chart)
```
Points Earned (Last 8 Weeks)
┌──────────────────────────────┐
│      📈                      │
│    📈 📈   📈 📈              │
│  📈   📈 📈   📈 📈          │
├──────────────────────────────┤
│ Week 1 2 3 4 5 6 7 8        │
│ Goal: 100 pts/week ← ✓ Met   │
└──────────────────────────────┘
```

- Line/bar chart of points earned over time
- Weekly/monthly toggle
- Overlay parent's goal (if set)
- Show streaks ("7-week streak!")

#### 5.3 Leaderboard (Optional)
```
Sibling Challenge (This Month)
┌──────────────────────────────┐
│ 1. 🥇 Alex    450 pts        │
│ 2. 🥈 You     350 pts        │
│    → +100 pts to catch up    │
│ 3. 🥉 Sam     200 pts        │
│                              │
│ You're in 2nd place!         │
│ Earn 100 more pts to tie.    │
└──────────────────────────────┘

Friend Challenge (School)
┌──────────────────────────────┐
│ You ranked #7 in your        │
│ school's points club.        │
│                              │
│ Top 5 get extra rewards!     │
│ → 50 more pts to #5          │
└──────────────────────────────┘
```

- Sibling ranking (if multiple children under same parent)
- School/friend group ranking (if social feature integrated)
- Gamification: "You're close to #1 — keep it up!"
- Show tied positions ("You're tied with 5 others")

#### 5.4 Challenges & Streaks
```
Active Challenges (This Month)
┌─────────────────────────────┐
│ Weekly Saver                │
│ Save 50 SZL this week       │
│ ████████░░ 40 SZL          │
│ Reward: 25 bonus pts        │
│ Expires: Friday, 11:59 PM   │
│ [Accept] [Skip]             │
│                             │
│ Chore Streak                │
│ Complete 5 chores by Fri    │
│ ✓✓✓○○ (3 of 5)             │
│ Reward: 50 bonus pts        │
│ [View Chores]               │
└─────────────────────────────┘
```

- Parent-set or system-generated challenges
- Progress bars, visual streaks
- Parent nudges ("Keep your 7-week streak alive!")
- Achievement unlocks ("Streak Breaker" — 10-week unbroken chore completion)

**API Requirements:**
- `GET /api/minor-accounts/{childId}/points/earning-breakdown?period=month` → Points by source
- `GET /api/minor-accounts/{childId}/points/history?limit=100` → Detailed earning history (timestamped)
- `GET /api/minor-accounts/{childId}/achievements/streaks` → Current streaks
- `GET /api/minor-accounts/{childId}/leaderboards?scope=siblings|school` → Ranking data

---

### 6. Parent Rewards Management

**Location:** Filament Admin panel (separate from child app)

**Note:** Detailed parent UI design deferred to Phase 5. Phase 8 adds rewards-specific parent controls.

#### 6.1 Parent Dashboard Widget
```
┌─────────────────────────────────┐
│ Child Rewards Activity          │
├─────────────────────────────────┤
│ Alex (Level 5)                  │
│ ✓ 1,250 pts · 3 redemptions    │
│   this month                    │
│                                 │
│ Sam (Level 2)                   │
│ ✓ 450 pts · 0 redemptions      │
│   (prefers to save)             │
│                                 │
│ [Manage Rewards] [Set Limits]   │
└─────────────────────────────────┘
```

- At-a-glance summary per child (points balance, redemption count)
- Quick action to adjust settings

#### 6.2 Redemption Approval Queue
```
Pending Approvals
┌─────────────────────────────────┐
│ NEW — 2 hours ago               │
│ Alex: 300-pt experience (museum)│
│ > threshold (250 pts)           │
│                                 │
│ [Details] [Approve] [Decline]   │
│                                 │
│ PENDING — 18 hours ago          │
│ Sam: 150-pt junk food voucher   │
│ Auto-blocked by parent rules    │
│                                 │
│ [Details] [Allow Once] [Decline]│
│                                 │
│ Expires in 6 hours.             │
└─────────────────────────────────┘
```

- Chronological list of pending approvals
- Reason for approval requirement
- Approve/Decline/Allow-Once buttons
- Inline context (child's balance, frequency of similar redemptions)

#### 6.3 Redemption Limits Settings
```
Rewards Settings
┌─────────────────────────────────┐
│ Approval Threshold              │
│ Require parent approval for     │
│ rewards costing more than:      │
│ [_250_] points                  │
│                                 │
│ Daily Redemption Limit          │
│ Max points redeemable per day:  │
│ [_500_] points / day            │
│ (blank = unlimited)             │
│                                 │
│ Weekly Redemption Limit         │
│ [_1500_] points / week          │
│                                 │
│ Blocked Categories              │
│ ☑ Junk Food & Sweets           │
│ ☐ Alcohol                      │
│ ☐ Gambling                     │
│ ☑ International Experiences    │
│ ☐ Custom...                    │
│                                 │
│ Require Physical Address?       │
│ ○ Yes  ◉ No                   │
│                                 │
│ [Save]                          │
└─────────────────────────────────┘
```

- Approval threshold slider
- Daily/weekly redemption caps
- Category blocklist (checkboxes)
- Address requirement toggle
- Per-child or global settings (toggle)

#### 6.4 Redemption History & Analytics
```
Rewards Usage Report (Alex, Last 30 Days)
┌────────────────────────────────┐
│ Total Redeemed: 500 pts        │
│ Avg per redemption: 83 pts     │
│ Most-redeemed category: Airtime│
│                                │
│ Redemption Trend (30 days)     │
│ 📊 Chart: 30 pts/day avg       │
│                                │
│ Recent Redemptions:            │
│ ✓ MTN Airtime (100 pts)        │
│ ✓ Merchant Voucher (150 pts)   │
│ ✓ 1GB Data (150 pts)           │
│                                │
│ Sibling Compare:               │
│ Alex: 500 pts (active)         │
│ Sam:  100 pts (saver)          │
│                                │
│ [Download Report]              │
└────────────────────────────────┘
```

- Points redeemed this period
- Average redemption value
- Category breakdown (pie chart)
- Time-series trend
- Compare across children
- Export to CSV/PDF

**API Requirements (Parent Scope):**
- `GET /api/admin/minor-accounts/{childId}/redemptions?status=awaiting_approval` → Approval queue
- `POST /api/admin/minor-accounts/{childId}/redemptions/{orderId}/approve` → Parent approves
- `POST /api/admin/minor-accounts/{childId}/redemptions/{orderId}/decline` → Parent declines (with reason)
- `GET /api/admin/minor-accounts/{childId}/rewards-settings` → Get limits + blocks (includes `redemption_interval_hours`: int|null — minimum hours between redemptions; null = no limit)
- `PUT /api/admin/minor-accounts/{childId}/rewards-settings` → Update limits (accepts `approval_threshold`, `daily_limit`, `weekly_limit`, `blocked_categories`, `redemption_interval_hours`)
- `GET /api/admin/minor-accounts/{childId}/redemption-analytics?period=month` → Analytics data

---

### 7. Merchant Partner Integration

**Location:** Merchant admin dashboard (separate backend)

#### 7.1 Merchant Partner Registry
```
Reward Partners (Admin View)
┌────────────────────────────────┐
│ Status: Active (8 partners)    │
├────────────────────────────────┤
│ Name         Category    Status│
│ MTN Eswatini Telecom    ✓     │
│ Mugoyi Supermarket Grocery    ✓     │
│ Shoprite     Retail      ✓     │
│ Pick n Pay   Retail      ✓     │
│ Trusted Hands Electronics  ✓   │
│ [+ Add Partner]              │
└────────────────────────────────┘
```

- Partner name, category, status (active/inactive/pending)
- Commission rate (% of redemption points)
- Payout schedule (weekly/monthly)
- Contact info, API endpoint, webhook URL
- Logo upload

#### 7.2 QR-Pay Bonus Tracking
```
Merchant Dashboard (MTN Partner)
┌────────────────────────────────┐
│ Child Payments (This Month)    │
├────────────────────────────────┤
│ QR-Pay Transactions: 45       │
│ Total Value: 2,450 SZL        │
│ Bonus Points Distributed: 450  │
│ (2x multiplier on child spend) │
│                                │
│ Top Performing Days:           │
│ Fri 22 Apr: 15 txns, 120 pts  │
│ Sat 23 Apr: 12 txns, 110 pts  │
│                                │
│ Commission Earned:             │
│ 450 pts × 30% commission       │
│ = 135 pts paid out             │
│ [Payout scheduled for Wed]     │
│                                │
│ [View Transactions]            │
└────────────────────────────────┘
```

- Child QR-pay transaction count
- Total value of transactions
- Bonus points distributed (2x multiplier)
- Commission calculation
- Payout tracking (pending/completed)

#### 7.3 Redemption Fulfillment Queue
```
Merchant Fulfillment (MTN Partner)
┌────────────────────────────────┐
│ New Redemptions (5)            │
├────────────────────────────────┤
│ [NEW] Order #RW-12345          │
│ 50 SZL Airtime                 │
│ To: +268 76 123 456            │
│ Submitted: 5 mins ago          │
│ [Mark Sent] [Resend] [Fail]    │
│                                │
│ [SENT] Order #RW-12344         │
│ 1GB Data Bundle                │
│ Sent: 2 hours ago              │
│ Tracking: SMS delivered        │
│                                │
│ [FAILED] Order #RW-12343       │
│ 50 SZL Airtime                 │
│ Failed: Invalid phone #        │
│ [Retry] [Refund]               │
│                                │
│ [Completed] 142 orders         │
│ [View History]                 │
└────────────────────────────────┘
```

- Incoming fulfillment requests (new → sent → complete)
- Order details (ID, reward, recipient, timestamp)
- Actions: Mark sent (with tracking), resend, mark failed
- Failed orders with reason + refund option
- Completed orders archive

#### 7.4 Performance Analytics
```
Partner Analytics (This Month)
┌────────────────────────────────┐
│ Redemption Rate: 45%           │
│ (45 redemptions / 100 children)│
│                                │
│ Fulfillment Success: 97%       │
│ (138 completed / 142 total)    │
│                                │
│ Avg Fulfillment Time: 12 mins  │
│                                │
│ Customer Rating: 4.7 ⭐        │
│ (based on child feedback)      │
│                                │
│ Revenue Impact:                │
│ Points redeemed: 450 pts       │
│ Commission earned: 135 pts     │
│ [Download Report]              │
└────────────────────────────────┘
```

- Redemption rate % (incentivizes attractive rewards)
- Fulfillment success rate + SLA tracking
- Average fulfillment time
- Customer ratings (aggregated child feedback)
- Revenue impact

**API Requirements (Merchant Scope):**
- `GET /api/merchants/{partnerId}/redemptions?status=new,sent,failed` → Fulfillment queue
- `POST /api/merchants/{partnerId}/redemptions/{orderId}/mark-sent` → Submit fulfillment (with tracking)
- `POST /api/merchants/{partnerId}/redemptions/{orderId}/mark-failed` → Report failure (with reason)
- `GET /api/merchants/{partnerId}/analytics?period=month` → Performance data
- `POST /api/merchants/{partnerId}/qr-pay-bonus` → Record child QR transaction (triggers bonus award)

---

### 8. Notifications & Real-Time Updates

**System:** WebSocket (Soketi) + Push Notifications (Firebase Cloud Messaging)

**Events:**

#### 8.1 Child Notifications
| Event | Trigger | Message | Channel |
|-------|---------|---------|---------|
| `reward_redeemed` | Redemption confirmed | "🎉 MTN Airtime applied! Check your balance." | In-app + Push |
| `redemption_approved` | Parent approves | "✓ Your 1GB Data was approved! Getting it now..." | In-app + Push |
| `redemption_declined` | Parent declines | "😞 Your airtime request was declined. Keep saving!" | In-app + Push |
| `reward_shipped` | Merchant marks sent | "📦 Your voucher is on the way! Tracking: [#]" | In-app + Push |
| `reward_delivered` | Order completed | "✅ You got it! Rate this reward." | In-app + Push |
| `bonus_awarded` | Chore/saving/QR bonus | "🌟 +50 bonus points for QR paying at Shoprite!" | In-app |
| `stock_alert` | Low stock on saved reward | "⚠️ MTN Airtime is low on stock. Redeem soon?" | In-app (on-demand) |

#### 8.2 Parent Notifications
| Event | Trigger | Message | Channel |
|-------|---------|---------|---------|
| `redemption_submitted` | Child redeems | "Alex just redeemed 150 pts for 1GB Data. Approve?" | In-app + Push |
| `approval_expired` | Timeout on approval | "Approval for Sam's MTN Bundle expired (24h)." | In-app |
| `redemption_fulfilled` | Merchant sends | "Alex's voucher was sent to [address]. Track it here." | In-app |
| `high_redemption_month` | Spending pattern | "Alex has redeemed 1,000 pts this month (double normal)." | In-app (insight) |

#### 8.3 WebSocket Channels
```
WebSocket URL: wss://api.maphapay.local/api/realtime/{auth_token}

Child Channels:
- minor.{childId}.redemptions (own redemptions + status updates)
- minor.{childId}.points (earning updates, bonuses)
- minor.{childId}.notifications (general child notifications)

Parent Channels:
- guardian.{parentId}.approvals (pending approvals)
- guardian.{parentId}.children.{childId}.redemptions (child's redemptions)
- guardian.{parentId}.analytics (insights, summary updates)

Merchant Channels:
- merchant.{partnerId}.fulfillment (incoming orders)
- merchant.{partnerId}.qr-transactions (bonus tracking)
```

**Real-Time Updates:**
- Order status changes broadcast to child + parent instantly
- Point balance updates broadcast to child dashboard
- Parent approval queue refreshes without page reload
- Merchant fulfillment queue auto-updates on new submissions

**API Requirements:**
- Broadcast events via Spatie event sourcing + WebSocket
- Push notifications via Firebase Cloud Messaging (fcm_token stored on app install)
- Notification preference toggles (per child & parent, per event type)

---

## Technical Implementation Details

### Backend Architecture

#### 8.1 Service Layer

**MinorRewardService**
```php
namespace App\Domain\Account\Services;

class MinorRewardService {
    public function getCatalog(
        array $filters = [],
        int $limit = 12,
        int $offset = 0
    ): array { /* ... */ }
    
    public function getRewardDetail(int $rewardId): ?Reward { /* ... */ }
    
    public function searchRewards(string $query, int $limit = 10): array { /* ... */ }
    
    public function validateRedemptionEligibility(
        MinorAccount $child,
        Reward $reward
    ): RedemptionValidation { /* ... */ }
}
```

**MinorRedemptionService**
```php
class MinorRedemptionService {
    public function submitRedemption(
        MinorAccount $child,
        Reward $reward,
        ?ShippingAddress $address = null
    ): RedemptionOrder { /* ... */ }
    
    public function approveRedemption(
        RedemptionOrder $order,
        MinorAccount $parent
    ): void { /* ... */ }
    
    public function declineRedemption(
        RedemptionOrder $order,
        string $reason
    ): void { /* ... */ }
    
    public function cancelRedemption(
        RedemptionOrder $order,
        string $initiator // 'child' | 'parent' | 'system'
    ): void { /* ... */ }
}
```

**MerchantRedemptionService**
```php
class MerchantRedemptionService {
    public function getQueueForMerchant(
        MerchantPartner $merchant
    ): Collection { /* ... */ }
    
    public function markSent(
        RedemptionOrder $order,
        string $trackingNumber
    ): void { /* ... */ }
    
    public function markFailed(
        RedemptionOrder $order,
        string $reason
    ): void { /* ... */ }
    
    public function recordQrBonus(
        MinorAccount $child,
        MerchantPartner $merchant,
        int $amountMinor
    ): void { /* ... */ }
}
```

#### 8.2 Models & Tables

**New Tables:**

```sql
-- Reward catalog (extends Phase 4's minor_rewards)
CREATE TABLE minor_rewards (
    id BIGINT PRIMARY KEY,
    category VARCHAR(50), -- 'airtime', 'data', 'voucher', 'experience', 'social_good'
    name VARCHAR(255) NOT NULL,
    description LONGTEXT,
    image_url VARCHAR(2048),
    price_points INT NOT NULL, -- Points cost
    stock INT DEFAULT -1, -- -1 = unlimited
    is_featured BOOLEAN DEFAULT FALSE,
    partner_id BIGINT FOREIGN KEY -> merchant_partners,
    expiry_date TIMESTAMP NULL, -- Reward expires (stop offering)
    age_restriction VARCHAR(50) NULL, -- 'grow_only', 'rise_only', 'none'
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- Redemption orders
CREATE TABLE minor_reward_redemptions (
    id BIGINT PRIMARY KEY,
    minor_account_id BIGINT FOREIGN KEY -> accounts,
    reward_id BIGINT FOREIGN KEY -> minor_rewards,
    status VARCHAR(50), -- 'awaiting_approval', 'approved', 'processing', 'in_transit', 'delivered', 'failed', 'cancelled', 'expired'
    points_redeemed INT NOT NULL,
    quantity INT DEFAULT 1,
    shipping_address_id BIGINT FOREIGN KEY -> shipping_addresses,
    delivery_method VARCHAR(50), -- 'sms', 'in_app', 'physical', 'qr_code'
    merchant_reference VARCHAR(255) NULL,
    tracking_number VARCHAR(255) NULL,
    created_at TIMESTAMP,
    completed_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL, -- Approval timeout
    child_phone_number VARCHAR(20) NULL,
    updated_at TIMESTAMP
);

-- Parent approval workflow
CREATE TABLE minor_redemption_approvals (
    id BIGINT PRIMARY KEY,
    redemption_id BIGINT FOREIGN KEY -> minor_reward_redemptions,
    parent_account_id BIGINT FOREIGN KEY -> accounts,
    status VARCHAR(50), -- 'pending', 'approved', 'declined'
    reason VARCHAR(255) NULL,
    approved_at TIMESTAMP NULL,
    expires_at TIMESTAMP, -- 24h timeout
    created_at TIMESTAMP
);

-- Merchant partners
CREATE TABLE merchant_partners (
    id BIGINT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(50),
    logo_url VARCHAR(2048) NULL,
    qr_endpoint VARCHAR(2048) NULL,
    api_key VARCHAR(255) NULL,
    commission_rate DECIMAL(5,2), -- e.g., 30.00 for 30%
    payout_schedule VARCHAR(50), -- 'weekly', 'monthly'
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- Merchant fulfillment queue
CREATE TABLE merchant_redemption_queue (
    id BIGINT PRIMARY KEY,
    redemption_id BIGINT FOREIGN KEY -> minor_reward_redemptions,
    merchant_id BIGINT FOREIGN KEY -> merchant_partners,
    status VARCHAR(50), -- 'new', 'sent', 'failed'
    submitted_at TIMESTAMP,
    completed_at TIMESTAMP NULL,
    failure_reason VARCHAR(255) NULL,
    tracking_number VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

-- Merchant QR-pay bonus tracking
CREATE TABLE merchant_qr_transactions (
    id BIGINT PRIMARY KEY,
    minor_account_id BIGINT FOREIGN KEY -> accounts,
    merchant_id BIGINT FOREIGN KEY -> merchant_partners,
    amount_minor INT, -- SZL in base units
    bonus_points_awarded INT, -- 2x multiplier
    transaction_reference VARCHAR(255),
    created_at TIMESTAMP
);
```

#### 8.3 Controllers

**MinorRewardsController** (Child-facing)
```php
GET    /api/minor-accounts/{childId}/rewards                 -> catalog (filtered, paginated)
GET    /api/minor-accounts/{childId}/rewards/{rewardId}      -> GET detail
GET    /api/minor-accounts/{childId}/rewards/search          -> Search
POST   /api/minor-accounts/{childId}/redemptions             -> Submit redemption
GET    /api/minor-accounts/{childId}/redemptions             -> List orders
GET    /api/minor-accounts/{childId}/redemptions/{orderId}   -> Order detail
POST   /api/minor-accounts/{childId}/redemptions/{orderId}/cancel -> Cancel
POST   /api/minor-accounts/{childId}/redemptions/{orderId}/feedback -> Rate
GET    /api/minor-accounts/{childId}/rewards-dashboard       -> Dashboard summary
GET    /api/minor-accounts/{childId}/earning-analytics       -> Earning breakdown
```

**AdminMinorRewardsController** (Parent-facing, Filament)
```php
GET    /api/admin/minor-accounts/{childId}/redemptions/pending  -> Approval queue
POST   /api/admin/minor-accounts/{childId}/redemptions/{orderId}/approve
POST   /api/admin/minor-accounts/{childId}/redemptions/{orderId}/decline
GET    /api/admin/minor-accounts/{childId}/rewards-settings     -> Limits & blocks
PUT    /api/admin/minor-accounts/{childId}/rewards-settings     -> Update settings
GET    /api/admin/minor-accounts/{childId}/redemption-analytics -> Analytics
```

**MerchantRedemptionsController** (Merchant-facing)
```php
GET    /api/merchants/{partnerId}/redemptions              -> Fulfillment queue
POST   /api/merchants/{partnerId}/redemptions/{orderId}/mark-sent
POST   /api/merchants/{partnerId}/redemptions/{orderId}/mark-failed
GET    /api/merchants/{partnerId}/analytics                -> Performance
POST   /api/merchants/{partnerId}/qr-pay-bonus             -> Record QR bonus
```

#### 8.4 Policies & Authorization

**MinorRedemptionPolicy**
```php
class MinorRedemptionPolicy {
    public function submitRedemption(User $user, MinorAccount $account): bool {
        // User is the child's owner
        return $user->owns($account);
    }
    
    public function viewOrder(User $user, RedemptionOrder $order): bool {
        // Child can view own order, parent can view child's order
        return $user->owns($order->minorAccount) || $user->guardsAccount($order->minorAccount);
    }
    
    public function approveRedemption(User $user, RedemptionOrder $order): bool {
        // Only parent/guardian
        return $user->guardsAccount($order->minorAccount);
    }
}
```

#### 8.5 Validation Rules

**RedemptionSubmissionRequest**
```php
public function rules(): array {
    return [
        'reward_id' => 'required|exists:minor_rewards,id',
        'quantity' => 'required|integer|min:1',
        'shipping_address_id' => 'nullable|exists:shipping_addresses,id',
        'child_phone_number' => 'required_if:reward.delivery_method,sms|phone',
    ];
}

public function messages(): array {
    return [
        'reward_id.exists' => 'Reward not found.',
        'child_phone_number.required_if' => 'Phone number required for SMS delivery.',
    ];
}
```

**RedemptionValidation (Service)**
```php
class RedemptionValidation {
    public bool $isEligible;
    public ?string $blockReason; // 'out_of_stock', 'insufficient_points', 'blocked_category', 'age_restricted'
    public ?int $pointsNeeded;
    public ?int $stockAvailable;
}
```

---

## Mobile Implementation Details

### 1. State Management (Redux/Context)

**RewardsSlice** (State shape)
```ts
{
  rewards: {
    catalog: { list: Reward[], total: number, isLoading: boolean },
    detail: { reward: Reward | null, relatedRewards: Reward[] },
    filters: { category: string, priceRange: [min, max], inStock: boolean },
    search: { query: string, results: Reward[] },
  },
  redemptions: {
    orders: { pending: RedemptionOrder[], active: RedemptionOrder[], completed: RedemptionOrder[] },
    selectedOrder: RedemptionOrder | null,
    isLoading: boolean,
  },
  parentControls: {
    settings: { approvalThreshold: number, dailyLimit: number, blockedCategories: string[] },
    approvalQueue: RedemptionApproval[],
  }
}
```

### 2. API Hooks (React Query)

```ts
// Fetch catalog with filters
const { data: rewards } = useQuery(
  ['rewards', filters],
  () => minorRewardsApi.getCatalog(filters),
  { staleTime: 5 * 60 * 1000 } // 5 min cache
);

// Fetch reward detail
const { data: reward } = useQuery(
  ['reward', rewardId],
  () => minorRewardsApi.getDetail(rewardId)
);

// Submit redemption
const { mutate: redeemReward } = useMutation(
  (payload) => minorRewardsApi.submitRedemption(payload),
  {
    onSuccess: (order) => {
      showSuccessAnimation();
      navigate(`/rewards/${order.id}`);
    },
    onError: (error) => {
      showErrorToast(error.message);
    }
  }
);

// WebSocket subscription to order status
useEffect(() => {
  const unsubscribe = echoClient.channel(`minor.${childId}.redemptions`)
    .listen('RedemptionStatusUpdated', (event) => {
      updateOrderStatus(event.orderId, event.status);
      showNotification(event.message);
    });
  
  return unsubscribe;
}, [childId]);
```

### 3. Component Structure

```
/features/rewards/
├─ components/
│  ├─ RewardsDashboard.tsx (main tab)
│  ├─ RewardCatalog.tsx (shop grid)
│  ├─ RewardDetail.tsx (modal)
│  ├─ RedemptionFlow.tsx (checkout steps)
│  ├─ OrderHistory.tsx (my rewards tab)
│  ├─ EarningProgress.tsx (insights)
│  └─ ParentRewardsManager.tsx (Filament integration)
├─ hooks/
│  ├─ useRewardsCatalog.ts
│  ├─ useRedemptionFlow.ts
│  ├─ useOrderTracking.ts
│  └─ useParentControls.ts
├─ api/
│  └─ minorRewardsApi.ts
└─ types/
   └─ rewards.ts
```

---

## Testing Strategy

### Unit Tests

**MinorRewardService Tests**
- Catalog filtering (category, price, stock)
- Search query matching
- Eligibility validation (points, age, blocks)
- Reward detail fetching

**MinorRedemptionService Tests**
- Redemption submission validation
- Points deduction from ledger
- Stock management (concurrent redemptions)
- Parent approval workflow
- Timeout & auto-cancellation
- Refund logic on failure

**MerchantRedemptionService Tests**
- QR-pay bonus award (2x multiplier)
- Fulfillment queue management
- Commission calculation
- Payout scheduling

### Integration Tests

**Redemption Lifecycle**
```php
it('child can redeem reward with parent approval', function () {
    $parent = User::factory()->create();
    $child = createMinorAccount($parent);
    $reward = MinorReward::factory()->create(['price_points' => 100]);
    
    // Award child points
    $service = new MinorPointsService();
    $service->award($child, 150, 'test', 'test_event');
    
    // Submit redemption
    $redemption = $this->actingAs($child->owner)->postJson(
        "/api/minor-accounts/{$child->id}/redemptions",
        ['reward_id' => $reward->id]
    )->json('data');
    
    // Should require parent approval (if > threshold)
    expect($redemption['status'])->toBe('awaiting_approval');
    
    // Parent approves
    $this->actingAs($parent)->postJson(
        "/api/admin/minor-accounts/{$child->id}/redemptions/{$redemption['id']}/approve"
    );
    
    // Order should now be processing
    $order = RedemptionOrder::find($redemption['id']);
    expect($order->status)->toBe('approved');
    expect($child->fresh()->points_balance)->toBe(50); // 150 - 100
});
```

**Merchant Fulfillment**
```php
it('merchant can mark redemption sent with tracking', function () {
    // ... setup redemption ...
    
    $this->actingAs($merchant->owner)->postJson(
        "/api/merchants/{$merchant->id}/redemptions/{$redemption->id}/mark-sent",
        ['tracking_number' => 'TRK-12345']
    );
    
    expect($redemption->fresh()->status)->toBe('in_transit');
    expect($redemption->tracking_number)->toBe('TRK-12345');
    
    // Child should receive notification
    Notification::assertSentTo($child->owner, RewardShippedNotification::class);
});
```

**QR-Pay Bonus**
```php
it('child receives 2x bonus on QR-pay at merchant', function () {
    $child = createMinorAccount();
    $merchant = MerchantPartner::factory()->create();
    
    // Record QR transaction
    $service = new MerchantRedemptionService();
    $service->recordQrBonus($child, $merchant, 50000); // 500 SZL base
    
    // Should award 2x bonus (100 pts for 500 SZL)
    expect($child->fresh()->points_balance)->toBe(100);
    
    // Ledger entry should be created
    expect(MinorPointsLedger::where([
        'minor_account_id' => $child->id,
        'source' => 'qr_bonus',
        'reference_id' => $merchant->id,
    ])->exists())->toBeTrue();
});
```

### End-to-End (Mobile) Tests

**Child Redemption Flow**
1. Child views dashboard, taps "Shop"
2. Browse catalog (filter, search, sort)
3. Tap reward detail → read description + reviews
4. Tap "Redeem" → confirmation modal
5. Confirm → redemption submitted
6. If parent approval required: Wait for notification
7. Once approved: Merchant fulfills
8. Child receives tracking number
9. Once delivered: Leave feedback + celebrate

**Parent Approval Flow**
1. Parent views dashboard
2. See "New Approval Needed" notification
3. Tap → see pending redemptions
4. Review child's request + reason
5. Approve/Decline → child notified
6. Track fulfillment in analytics

---

## Deployment & Rollout

### Phase 8 Deployment Checklist

- [ ] Database migrations (reward catalog, redemption orders, merchant tables)
- [ ] Backend services deployed (MinorRewardService, MinorRedemptionService, etc.)
- [ ] API endpoints tested (unit + integration)
- [ ] Mobile app update (catalog UI, redemption flow, order tracking)
- [ ] Parent control UI (Filament admin)
- [ ] Merchant dashboard (fulfillment queue, analytics)
- [ ] WebSocket channels configured + tested
- [ ] Push notifications (Firebase Cloud Messaging)
- [ ] Email/SMS templates (order confirmations, parent approvals)
- [ ] Reward catalog seeded (5–10 initial merchants)
- [ ] QA testing (happy path + edge cases)
- [ ] Beta test (10 families)
- [ ] Documentation (parent + child guides, merchant onboarding)
- [ ] Launch (full rollout to production)

### Feature Flags

```php
config('features.minor_rewards_shop') = env('MINOR_REWARDS_SHOP_ENABLED', false);
config('features.parent_redemption_approval') = env('PARENT_APPROVAL_ENABLED', true);
config('features.merchant_qr_bonus') = env('QR_BONUS_ENABLED', false); // Phase 9
```

**Progressive rollout:** 
1. Week 1: Seeded rewards only (admin dashboard)
2. Week 2: Merchant partner beta (5 partners)
3. Week 3–4: Gradual rollout to families (10% → 50% → 100%)

---

## Success Metrics (Phase 8)

| Metric | Target | Measurement |
|--------|--------|-------------|
| Catalog Adoption | 60%+ of children view catalog | Analytics: unique users / total children |
| Redemption Rate | 30%+ of earned points redeemed | Sum(points_redeemed) / sum(points_earned) |
| Merchant Performance | 90%+ fulfillment success | Completed orders / total submitted |
| Parent Approval Workflow | 95%+ approval decisions made | Approved + declined / total pending |
| Child Engagement | 45%+ weekly active users (rewards) | Children tapping rewards UI |
| Merchant Satisfaction | 4.0+ star rating | Aggregated feedback scores |
| System Reliability | 99.9% uptime | Monitoring dashboard |

---

## Known Constraints & Deferred Features

### Phase 8 Only
- Child-to-child reward gifting (Phase 9)
- Recurring redemption subscriptions (Phase 10)
- Reward pre-orders for out-of-stock items (Phase 9+)
- Gamified leaderboards across schools (deferred, requires partnership)
- Interest accrual on savings toward reward goals (Phase 10)

### External Dependencies
- Merchant API integrations (MTN, Shoprite, etc.)
- Shipping/fulfillment providers (for physical rewards)
- KYC provider updates (if reward tiers change at age 18)

---

## Appendix: API Contract Summary

### Child-Facing Endpoints

```
GET    /api/minor-accounts/{childId}/rewards?category=airtime&sort=popular&limit=12&offset=0
GET    /api/minor-accounts/{childId}/rewards/{rewardId}
GET    /api/minor-accounts/{childId}/rewards/search?q=airtime&limit=10
POST   /api/minor-accounts/{childId}/redemptions { reward_id, quantity, shipping_address_id?, child_phone_number }
GET    /api/minor-accounts/{childId}/redemptions?status=pending,active&limit=20&offset=0
GET    /api/minor-accounts/{childId}/redemptions/{orderId}
POST   /api/minor-accounts/{childId}/redemptions/{orderId}/cancel
POST   /api/minor-accounts/{childId}/redemptions/{orderId}/feedback { rating, comment }
GET    /api/minor-accounts/{childId}/rewards-dashboard
GET    /api/minor-accounts/{childId}/earning-analytics?period=month
GET    /api/minor-accounts/{childId}/achievements/streaks
GET    /api/minor-accounts/{childId}/leaderboards?scope=siblings|school
```

### Parent-Facing Endpoints

```
GET    /api/admin/minor-accounts/{childId}/redemptions?status=awaiting_approval&limit=20
POST   /api/admin/minor-accounts/{childId}/redemptions/{orderId}/approve
POST   /api/admin/minor-accounts/{childId}/redemptions/{orderId}/decline { reason }
GET    /api/admin/minor-accounts/{childId}/rewards-settings
PUT    /api/admin/minor-accounts/{childId}/rewards-settings { approval_threshold, daily_limit, blocked_categories }
GET    /api/admin/minor-accounts/{childId}/redemption-analytics?period=month
```

### Merchant-Facing Endpoints

```
GET    /api/merchants/{partnerId}/redemptions?status=new,sent,failed&limit=50
POST   /api/merchants/{partnerId}/redemptions/{orderId}/mark-sent { tracking_number }
POST   /api/merchants/{partnerId}/redemptions/{orderId}/mark-failed { reason }
GET    /api/merchants/{partnerId}/analytics?period=month
POST   /api/merchants/{partnerId}/qr-pay-bonus { child_account_id, amount_minor, transaction_reference }
```

---

**Phase 8 Complete. Ready for implementation via `writing-plans` skill.**

