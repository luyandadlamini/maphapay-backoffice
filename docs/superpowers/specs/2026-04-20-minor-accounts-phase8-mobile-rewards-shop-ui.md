# Minor Accounts Phase 8: Mobile Rewards & Shop — UI/UX Specification

**Date:** 2026-04-20  
**Platform:** React Native (iOS & Android)  
**Repository:** `/Users/Lihle/Development/Coding/maphapayrn`  
**Status:** Design — Ready for Implementation  
**Preconditions:** Phases 1–7 complete (backoffice & mobile)

---

## Executive Summary

This spec covers the **complete mobile UI/UX implementation** for Phase 8 (Rewards & Shop). It details:
- Child-facing screens (rewards dashboard, catalog, redemption flow, order tracking)
- Parent admin panels (Filament integration for approval workflow, limits, analytics)
- Merchant partner dashboard (fulfillment queue, QR-pay tracking, performance)
- React Native component architecture, state management, and testing patterns
- Accessibility standards (48dp+ buttons, theme colors, dark mode)

**Scope:** Mobile app only. Backend APIs documented in parallel `2026-04-20-minor-accounts-phase8-mobile-rewards-shop.md`.

---

## Navigation Architecture

### Child Tab Structure (Ages 6–17)

```
KidDashboard (Bottom Tab Navigator)
├─ Home Tab (existing)
│  └─ RewardsDashboardWidget (new Phase 8)
│     ├─ Points Balance Display
│     ├─ Achievement Badges Carousel
│     ├─ Earning Progress Bar
│     └─ Quick Redeem CTAs
│
├─ Shop Tab (NEW Phase 8)
│  ├─ RewardsCatalogScreen
│  │  ├─ CatalogGrid (paginated, lazy-load)
│  │  ├─ FilterSheet (category, price, stock)
│  │  ├─ SearchBar
│  │  └─ RewardDetailModal
│  │     ├─ ProductInfo
│  │     ├─ RedemptionTimeline
│  │     ├─ ReviewsSection (optional)
│  │     ├─ ShareWidget
│  │     └─ [Redeem] CTA
│  │
│  └─ RedemptionFlowModal (4 steps)
│     ├─ Step 1: Confirmation (reward, points, delivery)
│     ├─ Step 2: ShippingAddress (conditional — physical rewards only)
│     ├─ Step 3: PhoneNumber (conditional — SMS delivery only)
│     └─ Step 4: Success (celebration / awaiting-approval waiting state)
│     Note: ParentApproval is a post-submit waiting state inside Step 4,
│     not its own step — triggered when API returns awaiting_approval.
│
├─ MyRewards Tab (NEW Phase 8)
│  ├─ OrderStatusTabs (All, Pending, Active, Complete, Failed)
│  ├─ OrderCardList (paginated)
│  └─ OrderDetailScreen
│     ├─ RewardSummary
│     ├─ StatusTimeline
│     ├─ DeliveryProof
│     ├─ FeedbackForm
│     └─ [Share] [Support Contact]
│
├─ Insights Tab (expanded Phase 8)
│  ├─ EarningBreakdown (pie/bar chart)
│  ├─ EarningTrend (8-week chart)
│  ├─ Leaderboard (siblings, school)
│  └─ ChallengesAndStreaks
│
└─ Account Tab (existing)
   └─ [Parent can manage child here]
```

### Parent Dashboard (Filament Web Admin)

```
/admin/minor-accounts/{childId}
├─ Rewards Activity Widget (main dashboard)
│
├─ Rewards Management
│  ├─ Redemption Approval Queue
│  │  └─ List (pending → approve/decline)
│  ├─ Redemption Limits Settings
│  │  ├─ Approval threshold slider
│  │  ├─ Daily/weekly caps
│  │  ├─ Category blocklist
│  │  └─ [Save]
│  └─ Redemption Analytics
│     ├─ Trend chart
│     ├─ Category breakdown
│     ├─ Recent redemptions
│     └─ [Download Report]
```

### Merchant Partner Dashboard (Web Admin)

```
/merchants/{partnerId}
├─ Child Payments (QR-Pay Tracking)
│  ├─ Transaction count
│  ├─ Total value + bonus points
│  └─ Commission earned
│
├─ Fulfillment Queue
│  ├─ New orders (incoming)
│  ├─ Sent orders (tracking)
│  └─ Failed orders (reasons)
│
└─ Analytics
   ├─ Redemption rate
   ├─ Fulfillment success
   ├─ Customer rating
   └─ [Download Report]
```

---

## Child-Facing Screens (Detailed)

### Screen 1: Rewards Dashboard Widget (Home Tab)

**Location:** Home tab, below existing balance/chores widgets  
**Triggered:** Auto-load on tab focus  
**Scroll Position:** User can scroll past other widgets

**Layout (ScrollView):**

```
┌────────────────────────────────────┐
│ 🎁 REWARDS                         │
├────────────────────────────────────┤
│                                    │
│ ┌──────────────────────────────┐   │
│ │ Points Balance Widget        │   │
│ │ ┌────────────────────────────┐   │
│ │ │ 💰 1,250 Points           │   │
│ │ │ ↑ +50 from chores         │   │
│ │ │ 🎁 4 rewards available    │   │
│ │ └────────────────────────────┘   │
│ │ [Shop Now] [Earn More]           │
│ └──────────────────────────────────┘
│                                    │
│ ┌──────────────────────────────┐   │
│ │ Achievement Badges Strip     │   │
│ │ ┌──┐ ┌──┐ ┌──┐ ┌──┐        │   │
│ │ │🌟│ │💪│ │👑│ │🚀│  →    │   │
│ │ │  │ │  │ │  │ │  │        │   │
│ │ └──┘ └──┘ └──┘ └──┘        │   │
│ └──────────────────────────────┘   │
│                                    │
│ ┌──────────────────────────────┐   │
│ │ Earning Progress             │   │
│ │ Next: 50 SZL Airtime         │   │
│ │ ████████░░ 8/10 pts          │   │
│ │ Estimated in 3 days          │   │
│ │ [View Similar]               │   │
│ └──────────────────────────────┘   │
│                                    │
│ ┌──────────────────────────────┐   │
│ │ Quick Redeem Carousel        │   │
│ │ ┌─────────┬─────────┬────────┐   │
│ │ │MTN 50   │1GB Data │Drink   │   │
│ │ │Airtime  │Bundle  │Voucher │   │
│ │ │100 pts✓ │150 pts✗│200 pts✓│   │
│ │ │[Redeem] │[Soon]  │[Redeem]│   │
│ │ └─────────┴─────────┴────────┘   │
│ └──────────────────────────────┘   │
│                                    │
└────────────────────────────────────┘
```

**Component: `RewardsDashboardWidget`**

```typescript
interface RewardsDashboardWidgetProps {
  minorAccountUuid: string;
}

export function RewardsDashboardWidget({
  minorAccountUuid,
}: RewardsDashboardWidgetProps) {
  const theme = useAppTheme();
  const navigation = useNavigation();
  
  // Hooks
  const { data: pointsResponse, isLoading } = useMinorPointsBalance(minorAccountUuid);
  const { data: achievementsResponse } = useMinorAchievements(minorAccountUuid);
  const { data: catalogResponse } = useMinorRewardsCatalog({
    featured: true,
    limit: 3,
  });
  
  const points = pointsResponse?.data.available_points ?? 0;
  const achievements = achievementsResponse?.data ?? [];
  const featuredRewards = catalogResponse?.data ?? [];
  
  return (
    <View style={{
      backgroundColor: theme.colors.surfaceVariant,
      borderRadius: 12,
      padding: 16,
      marginVertical: 8,
    }}>
      {/* Points Balance */}
      <Pressable
        onPress={() => navigation.navigate('RewardsDashboard')}
        style={{
          minHeight: 48,
          backgroundColor: theme.colors.primary,
          borderRadius: 8,
          padding: 16,
          marginBottom: 12,
        }}
      >
        <Text style={{color: theme.colors.onPrimary, fontSize: 14}}>
          💰 {points.toLocaleString()} Points
        </Text>
        <Text style={{color: theme.colors.onPrimary, fontSize: 12, marginTop: 4}}>
          {points > 0 ? `🎁 ${featuredRewards.length} rewards available` : 'Start earning!'}
        </Text>
      </Pressable>
      
      {/* Achievements Carousel */}
      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        style={{marginBottom: 12}}
      >
        {achievements.map((achievement) => (
          <View
            key={achievement.id}
            style={{
              width: 70,
              height: 90,
              backgroundColor: achievement.unlocked_at
                ? theme.colors.tertiaryContainer
                : theme.colors.surfaceVariant,
              borderRadius: 8,
              alignItems: 'center',
              justifyContent: 'center',
              marginRight: 8,
              opacity: achievement.unlocked_at ? 1 : 0.5,
            }}
          >
            <Text style={{fontSize: 24}}>{achievement.icon}</Text>
            <Text style={{
              fontSize: 10,
              color: theme.colors.onSurface,
              marginTop: 4,
              textAlign: 'center',
            }}>
              {achievement.title}
            </Text>
          </View>
        ))}
      </ScrollView>
      
      {/* Earning Progress */}
      <View style={{
        backgroundColor: theme.colors.surface,
        borderRadius: 8,
        padding: 12,
        marginBottom: 12,
      }}>
        <Text style={{color: theme.colors.onSurface, fontSize: 12, fontWeight: '600'}}>
          Next Reward
        </Text>
        <Text style={{color: theme.colors.onSurfaceVariant, fontSize: 14, marginTop: 4}}>
          {featuredRewards[0]?.name ?? 'Keep earning!'}
        </Text>
        <View style={{height: 4, backgroundColor: theme.colors.surfaceVariant, borderRadius: 2, marginTop: 8}}>
          <View
            style={{
              height: '100%',
              width: `${Math.min((points / (featuredRewards[0]?.price_points ?? 100)) * 100, 100)}%`,
              backgroundColor: theme.colors.primary,
              borderRadius: 2,
            }}
          />
        </View>
      </View>
      
      {/* Featured Carousel */}
      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
      >
        {featuredRewards.map((reward) => (
          {/* Single Pressable per card — no nested Pressable (causes touch conflicts on RN) */}
          <Pressable
            key={reward.id}
            onPress={() => navigation.navigate('RewardDetail', {rewardId: reward.id})}
            style={{
              width: 140,
              marginRight: 8,
              backgroundColor: theme.colors.surface,
              borderRadius: 8,
              padding: 8,
            }}
          >
            <Image
              source={{uri: reward.image_url}}
              style={{width: '100%', height: 80, borderRadius: 4}}
            />
            <Text style={{fontSize: 12, color: theme.colors.onSurface, marginTop: 4}}>
              {reward.name}
            </Text>
            <Text style={{
              fontSize: 14,
              fontWeight: '700',
              color: theme.colors.primary,
              marginTop: 4,
            }}>
              {reward.price_points} pts
            </Text>
            {/* Affordability badge — tapping the whole card navigates to detail where Redeem lives */}
            <View
              style={{
                minHeight: 48,
                backgroundColor: points >= reward.price_points
                  ? theme.colors.primaryContainer
                  : theme.colors.surfaceVariant,
                borderRadius: 4,
                alignItems: 'center',
                justifyContent: 'center',
                marginTop: 8,
              }}
            >
              <Text style={{
                color: points >= reward.price_points
                  ? theme.colors.onPrimaryContainer
                  : theme.colors.onSurfaceVariant,
                fontSize: 12,
                fontWeight: '600',
              }}>
                {points >= reward.price_points ? '✓ Tap to Redeem' : `Need ${reward.price_points - points} more pts`}
              </Text>
            </View>
          </Pressable>
        ))}
      </ScrollView>
    </View>
  );
}
```

**Hooks Required:**
- `useMinorPointsBalance(childId)` → `{ available_points, pending_points, total_earned }`
- `useMinorAchievements(childId)` → Array of achievement objects with unlocked status
- `useMinorRewardsCatalog(filters)` → Paginated rewards list

---

### Screen 2: Rewards Catalog (Shop Tab)

**Location:** "Shop" tab in KidDashboard  
**Scroll:** Infinite scroll with "Load More" pagination

**Layout:**

```
┌────────────────────────────────┐
│ 🏪 REWARDS SHOP               │
├────────────────────────────────┤
│ [Search...           ] [Filter]│
├────────────────────────────────┤
│ Grid View (2 columns):         │
│ ┌──────────────┐ ┌──────────────┐
│ │ [Image]      │ │ [Image]      │
│ │ MTN 50 SZL   │ │ 1GB Data     │
│ │ Airtime      │ │ Bundle       │
│ │ 100 pts ✓    │ │ 150 pts ✗    │
│ │ [Redeem]     │ │ [Sold Out]   │
│ └──────────────┘ └──────────────┘
│ ┌──────────────┐ ┌──────────────┐
│ │ ...          │ │ ...          │
│ └──────────────┘ └──────────────┘
│ [Load More...]                 │
└────────────────────────────────┘

Filter Sheet (Modal, Swipe Down):
┌────────────────────────────────┐
│ ⬇ Filters                      │
├────────────────────────────────┤
│ Category                       │
│ ◉ All                         │
│ ○ Airtime & Data              │
│ ○ Vouchers                    │
│ ○ Experiences                 │
│                                │
│ Price Range                   │
│ 0 pts ────●──── 1,000 pts     │
│                                │
│ ☑ In Stock Only               │
│                                │
│ Sort By                       │
│ ◉ Most Popular                │
│ ○ Newest                      │
│ ○ Price: Low to High          │
│                                │
│ [Reset] [Apply]               │
└────────────────────────────────┘
```

**Component: `RewardsCatalogScreen`**

```typescript
interface RewardsCatalogScreenProps {
  minorAccountUuid: string;
}

export function RewardsCatalogScreen({
  minorAccountUuid,
}: RewardsCatalogScreenProps) {
  const theme = useAppTheme();
  const [filters, setFilters] = useState({
    category: 'all',
    priceRange: [0, 1000],
    inStock: true,
    sort: 'popular',
  });
  const [searchQuery, setSearchQuery] = useState('');
  const [showFilterSheet, setShowFilterSheet] = useState(false);
  const [page, setPage] = useState(1);
  
  // Hooks
  // useMinorRewardsCatalog must use useInfiniteQuery internally (not useQuery)
  // to expose hasNextPage + fetchNextPage
  const { data: catalogResponse, isLoading, hasNextPage, fetchNextPage } = useMinorRewardsCatalog({
    category: filters.category,
    minPrice: filters.priceRange[0],
    maxPrice: filters.priceRange[1],
    inStock: filters.inStock,
    sort: filters.sort,
    query: searchQuery,
    limit: 12,
  });
  
  const { data: pointsResponse } = useMinorPointsBalance(minorAccountUuid);
  const points = pointsResponse?.data.available_points ?? 0;
  
  const rewards = catalogResponse?.data ?? [];
  
  return (
    <SafeAreaView style={{
      flex: 1,
      backgroundColor: theme.colors.background,
    }}>
      {/* Header */}
      <View style={{
        paddingHorizontal: 16,
        paddingVertical: 12,
        backgroundColor: theme.colors.surface,
        borderBottomColor: theme.colors.outline,
        borderBottomWidth: 1,
      }}>
        <Text style={{
          fontSize: 20,
          fontWeight: '700',
          color: theme.colors.onSurface,
          marginBottom: 12,
        }}>
          🏪 Rewards Shop
        </Text>
        
        {/* Search Bar */}
        <View style={{
          flexDirection: 'row',
          gap: 8,
        }}>
          <TextInput
            placeholder="Search rewards..."
            value={searchQuery}
            onChangeText={setSearchQuery}
            style={{
              flex: 1,
              minHeight: 44,
              backgroundColor: theme.colors.surfaceVariant,
              borderRadius: 8,
              paddingHorizontal: 12,
              color: theme.colors.onSurface,
            }}
            placeholderTextColor={theme.colors.onSurfaceVariant}
          />
          <Pressable
            onPress={() => setShowFilterSheet(true)}
            style={{
              minHeight: 44,
              minWidth: 44,
              backgroundColor: theme.colors.primary,
              borderRadius: 8,
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            <Text style={{fontSize: 20}}>⚙️</Text>
          </Pressable>
        </View>
      </View>
      
      {/* Rewards Grid */}
      <FlatList
        data={rewards}
        numColumns={2}
        keyExtractor={(item) => item.id.toString()}
        renderItem={({item}) => (
          <RewardCard
            reward={item}
            availablePoints={points}
            onPress={() => {
              // Navigate to detail
            }}
          />
        )}
        contentContainerStyle={{
          padding: 8,
        }}
        columnWrapperStyle={{
          gap: 8,
          marginBottom: 8,
        }}
        ListFooterComponent={
          hasNextPage ? (
            <Pressable
              onPress={() => fetchNextPage()}
              disabled={isLoading}
              style={{
                minHeight: 44,
                backgroundColor: theme.colors.primary,
                borderRadius: 8,
                alignItems: 'center',
                justifyContent: 'center',
                marginHorizontal: 8,
                marginVertical: 16,
              }}
            >
              <Text style={{
                color: theme.colors.onPrimary,
                fontWeight: '600',
              }}>
                {isLoading ? 'Loading...' : 'Load More'}
              </Text>
            </Pressable>
          ) : null
        }
      />
      
      {/* Filter Sheet */}
      {showFilterSheet && (
        <FilterBottomSheet
          filters={filters}
          onFiltersChange={setFilters}
          onClose={() => setShowFilterSheet(false)}
        />
      )}
    </SafeAreaView>
  );
}

interface RewardCardProps {
  reward: Reward;
  availablePoints: number;
  onPress: () => void;
}

function RewardCard({reward, availablePoints, onPress}: RewardCardProps) {
  const theme = useAppTheme();
  const canAfford = availablePoints >= reward.price_points;
  const inStock = reward.stock !== 0; // -1 means unlimited
  
  return (
    <Pressable
      onPress={onPress}
      disabled={!canAfford || !inStock}
      style={{
        flex: 0.5,
        minHeight: 240,
        backgroundColor: theme.colors.surface,
        borderRadius: 12,
        padding: 8,
        opacity: (canAfford && inStock) ? 1 : 0.6,
      }}
    >
      <Image
        source={{uri: reward.image_url}}
        style={{width: '100%', height: 120, borderRadius: 8}}
      />
      
      <Text style={{
        fontSize: 13,
        fontWeight: '600',
        color: theme.colors.onSurface,
        marginTop: 8,
      }}>
        {reward.name}
      </Text>
      
      <Text style={{
        fontSize: 12,
        color: theme.colors.onSurfaceVariant,
        marginTop: 4,
      }}>
        {reward.category}
      </Text>
      
      <Text style={{
        fontSize: 16,
        fontWeight: '700',
        color: theme.colors.primary,
        marginTop: 8,
      }}>
        {reward.price_points} pts
      </Text>
      
      <View style={{
        flexDirection: 'row',
        gap: 4,
        marginTop: 4,
      }}>
        <Text style={{
          fontSize: 11,
          color: inStock ? theme.colors.secondary : theme.colors.error,
          fontWeight: '500',
        }}>
          {inStock ? '✓ In Stock' : '✗ Sold Out'}
        </Text>
      </View>
      
      <Pressable
        disabled={!canAfford || !inStock}
        style={{
          minHeight: 48, // 48dp minimum tap target (accessibility requirement)
          backgroundColor: (canAfford && inStock)
            ? theme.colors.primary
            : theme.colors.surfaceVariant,
          borderRadius: 6,
          alignItems: 'center',
          justifyContent: 'center',
          marginTop: 8,
        }}
      >
        <Text style={{
          color: (canAfford && inStock)
            ? theme.colors.onPrimary
            : theme.colors.onSurfaceVariant,
          fontSize: 13,
          fontWeight: '600',
        }}>
          {canAfford && inStock ? 'Redeem' : (canAfford ? 'Sold Out' : 'Soon')}
        </Text>
      </Pressable>
    </Pressable>
  );
}
```

**Hooks Required:**
- `useMinorRewardsCatalog(filters, pagination)` → Paginated catalog with search/filter support
- `useMinorPointsBalance(childId)` → Current points balance

---

### Screen 3: Reward Detail Modal

**Triggered By:** Tapping reward card in catalog

**Layout:**

```
┌────────────────────────────────┐
│ ← [X]                          │ (Modal header)
├────────────────────────────────┤
│ [Large Product Image]          │
│ (400×250px)                    │
│                                │
│ MTN 50 SZL Airtime             │
│ ⭐ 4.5 (120 reviews)           │
│                                │
│ Price: 100 Points              │
│ ✓ In Stock (24 available)      │
│                                │
│ Description:                   │
│ "Instant airtime credit.       │
│ Works on all MTN networks in   │
│ Eswatini and SADC."            │
│                                │
│ Redemption Time: 5 mins        │
│ Validity: Immediate            │
│                                │
│ How It Works:                  │
│ 1. Tap Redeem                 │
│ 2. Confirm your phone #        │
│ 3. Get airtime instantly       │
│                                │
│ Parent Approval: ✓ Not Req'd   │
│                                │
│ 📢 [Share] [Save for Later]    │
│                                │
│ [Redeem 100 Points]            │
└────────────────────────────────┘
```

**Component: `RewardDetailModal`**

```typescript
interface RewardDetailModalProps {
  rewardId: string;
  minorAccountUuid: string;
  onClose: () => void;
  onRedeemStart: () => void;
}

export function RewardDetailModal({
  rewardId,
  minorAccountUuid,
  onClose,
  onRedeemStart,
}: RewardDetailModalProps) {
  const theme = useAppTheme();
  const [showSaved, setShowSaved] = useState(false);
  
  // Hooks
  const { data: rewardResponse, isLoading } = useMinorRewardDetail(rewardId);
  const { data: pointsResponse } = useMinorPointsBalance(minorAccountUuid);
  
  const reward = rewardResponse?.data;
  const points = pointsResponse?.data.available_points ?? 0;
  
  if (isLoading) {
    return (
      <Modal visible transparent>
        <View style={{
          flex: 1,
          backgroundColor: theme.colors.backdrop, // never hardcode rgba
          justifyContent: 'flex-end',
        }}>
          <ActivityIndicator color={theme.colors.primary} size="large" />
        </View>
      </Modal>
    );
  }
  
  if (!reward) {
    return null;
  }
  
  const canAfford = points >= reward.price_points;
  
  return (
    <Modal
      visible
      animationType="slide"
      transparent
      onRequestClose={onClose}
    >
      <SafeAreaView style={{
        flex: 1,
        backgroundColor: theme.colors.background,
      }}>
        {/* Header */}
        <View style={{
          flexDirection: 'row',
          justifyContent: 'space-between',
          alignItems: 'center',
          paddingHorizontal: 16,
          paddingVertical: 12,
          borderBottomColor: theme.colors.outline,
          borderBottomWidth: 1,
        }}>
          <Pressable onPress={onClose} hitSlop={8}>
            <Text style={{fontSize: 24}}>←</Text>
          </Pressable>
          <Pressable onPress={onClose} hitSlop={8}>
            <Text style={{fontSize: 24}}>✕</Text>
          </Pressable>
        </View>
        
        <ScrollView contentContainerStyle={{padding: 16}}>
          {/* Image */}
          <Image
            source={{uri: reward.image_url}}
            style={{
              width: '100%',
              height: 250,
              borderRadius: 12,
              marginBottom: 16,
            }}
          />
          
          {/* Title & Rating */}
          <Text style={{
            fontSize: 24,
            fontWeight: '700',
            color: theme.colors.onSurface,
            marginBottom: 8,
          }}>
            {reward.name}
          </Text>
          
          {reward.reviews && (
            <View style={{flexDirection: 'row', alignItems: 'center', marginBottom: 12}}>
              <Text style={{fontSize: 14, color: theme.colors.onSurface}}>
                ⭐ {reward.rating.toFixed(1)} ({reward.review_count} reviews)
              </Text>
            </View>
          )}
          
          {/* Price & Stock */}
          <View style={{
            backgroundColor: theme.colors.primaryContainer,
            borderRadius: 8,
            padding: 12,
            marginBottom: 16,
          }}>
            <Text style={{
              fontSize: 16,
              fontWeight: '700',
              color: theme.colors.primary,
            }}>
              {reward.price_points} Points
            </Text>
            <Text style={{
              fontSize: 12,
              color: theme.colors.onPrimaryContainer,
              marginTop: 4,
            }}>
              {reward.stock > 0 ? `✓ In Stock (${reward.stock} available)` : '✗ Sold Out'}
            </Text>
          </View>
          
          {/* Description */}
          <Text style={{
            fontSize: 14,
            color: theme.colors.onSurface,
            lineHeight: 20,
            marginBottom: 16,
          }}>
            {reward.description}
          </Text>
          
          {/* Details */}
          <View style={{
            backgroundColor: theme.colors.surface,
            borderRadius: 8,
            padding: 12,
            marginBottom: 16,
            gap: 8,
          }}>
            <View style={{flexDirection: 'row', justifyContent: 'space-between'}}>
              <Text style={{color: theme.colors.onSurfaceVariant, fontSize: 12}}>
                Redemption Time
              </Text>
              <Text style={{color: theme.colors.onSurface, fontWeight: '500', fontSize: 12}}>
                {reward.redemption_time ?? '5 minutes'}
              </Text>
            </View>
            <View style={{flexDirection: 'row', justifyContent: 'space-between'}}>
              <Text style={{color: theme.colors.onSurfaceVariant, fontSize: 12}}>
                Validity
              </Text>
              <Text style={{color: theme.colors.onSurface, fontWeight: '500', fontSize: 12}}>
                {reward.validity ?? 'Immediate'}
              </Text>
            </View>
          </View>
          
          {/* How It Works */}
          <Text style={{
            fontSize: 14,
            fontWeight: '600',
            color: theme.colors.onSurface,
            marginBottom: 8,
          }}>
            How It Works
          </Text>
          <View style={{
            backgroundColor: theme.colors.surface,
            borderRadius: 8,
            padding: 12,
            marginBottom: 16,
            gap: 8,
          }}>
            {(reward.how_it_works ?? []).map((step, idx) => (
              <Text
                key={idx}
                style={{
                  fontSize: 12,
                  color: theme.colors.onSurface,
                  lineHeight: 18,
                }}
              >
                {idx + 1}. {step}
              </Text>
            ))}
          </View>
          
          {/* Parent Approval Notice */}
          <View style={{
            backgroundColor: theme.colors.secondaryContainer, // warningContainer not in MD3 — use secondaryContainer or add custom token
            borderRadius: 8,
            padding: 12,
            marginBottom: 16,
            flexDirection: 'row',
            gap: 8,
          }}>
            <Text style={{fontSize: 16}}>ℹ️</Text>
            <Text style={{
              fontSize: 12,
              color: theme.colors.onSurface,
              flex: 1,
            }}>
              {reward.requires_approval
                ? 'Parent approval required for this reward'
                : 'This reward does not require parent approval'}
            </Text>
          </View>
          
          {/* Share & Save */}
          <View style={{
            flexDirection: 'row',
            gap: 8,
            marginBottom: 16,
          }}>
            <Pressable
              style={{
                flex: 1,
                minHeight: 44,
                backgroundColor: theme.colors.surfaceVariant,
                borderRadius: 8,
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <Text style={{fontSize: 14, fontWeight: '600'}}>📢 Share</Text>
            </Pressable>
            <Pressable
              onPress={() => setShowSaved(!showSaved)}
              style={{
                flex: 1,
                minHeight: 44,
                backgroundColor: theme.colors.surfaceVariant,
                borderRadius: 8,
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <Text style={{fontSize: 14, fontWeight: '600'}}>
                {showSaved ? '❤️ Saved' : '🤍 Save'}
              </Text>
            </Pressable>
          </View>
        </ScrollView>
        
        {/* CTA Button (Fixed) */}
        <View style={{
          paddingHorizontal: 16,
          paddingVertical: 12,
          borderTopColor: theme.colors.outline,
          borderTopWidth: 1,
        }}>
          <Pressable
            disabled={!canAfford || reward.stock === 0}
            onPress={onRedeemStart}
            style={{
              minHeight: 48,
              backgroundColor: (canAfford && reward.stock !== 0)
                ? theme.colors.primary
                : theme.colors.surfaceVariant,
              borderRadius: 8,
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            <Text style={{
              color: (canAfford && reward.stock !== 0)
                ? theme.colors.onPrimary
                : theme.colors.onSurfaceVariant,
              fontSize: 16,
              fontWeight: '700',
            }}>
              Redeem {reward.price_points} Points
            </Text>
          </Pressable>
        </View>
      </SafeAreaView>
    </Modal>
  );
}
```

**Hooks Required:**
- `useMinorRewardDetail(rewardId)` → Full reward object with description, images, reviews
- `useMinorPointsBalance(childId)` → Current points

---

### Screen 4: Redemption Flow (4-Step Modal)

**Triggered By:** Tapping "Redeem" on reward  
**Animated:** Slides up with progress indicator

```
┌────────────────────────────────┐
│ Redeem Reward          [1/4]   │ (Progress)
├────────────────────────────────┤
│ [Content varies by step]       │
│                                │
│ [Back] [Next/Confirm]          │
└────────────────────────────────┘
```

**Component: `RedemptionFlowModal`**

```typescript
interface RedemptionFlowModalProps {
  reward: Reward;
  minorAccountUuid: string;
  onSuccess: (order: RedemptionOrder) => void;
  onClose: () => void;
}

export function RedemptionFlowModal({
  reward,
  minorAccountUuid,
  onSuccess,
  onClose,
}: RedemptionFlowModalProps) {
  const theme = useAppTheme();
  const [step, setStep] = useState(1); // 1-4
  const [shippingAddressId, setShippingAddressId] = useState<string | null>(null);
  const [phoneNumber, setPhoneNumber] = useState('');
  const [error, setError] = useState<string | null>(null);
  
  // Hooks
  const { data: pointsResponse } = useMinorPointsBalance(minorAccountUuid);
  const { mutate: submitRedemption, isPending } = useSubmitRedemption(minorAccountUuid);
  
  const points = pointsResponse?.data.available_points ?? 0;
  const canAfford = points >= reward.price_points;
  
  const handleNext = () => {
    if (step < 4) setStep(step + 1);
  };
  
  const handleBack = () => {
    if (step > 1) setStep(step - 1);
  };
  
  const handleConfirm = () => {
    submitRedemption(
      {
        reward_id: reward.id,
        shipping_address_id: shippingAddressId,
        child_phone_number: phoneNumber,
      },
      {
        onSuccess: (response) => {
          if (response.data.success) {
            onSuccess(response.data.data);
            setStep(4); // Success screen
          } else {
            setError(response.data.message ?? 'Redemption failed');
          }
        },
        onError: (error) => {
          setError(error.message ?? 'Something went wrong');
        },
      }
    );
  };
  
  return (
    <Modal
      visible
      animationType="slide"
      transparent
      onRequestClose={onClose}
    >
      <SafeAreaView style={{
        flex: 1,
        backgroundColor: theme.colors.background,
      }}>
        {/* Header with Progress */}
        <View style={{
          paddingHorizontal: 16,
          paddingVertical: 12,
          borderBottomColor: theme.colors.outline,
          borderBottomWidth: 1,
        }}>
          <View style={{
            flexDirection: 'row',
            justifyContent: 'space-between',
            alignItems: 'center',
            marginBottom: 12,
          }}>
            <Text style={{
              fontSize: 18,
              fontWeight: '700',
              color: theme.colors.onSurface,
            }}>
              Redeem Reward
            </Text>
            <Text style={{
              fontSize: 14,
              color: theme.colors.onSurfaceVariant,
            }}>
              {step}/4
            </Text>
          </View>
          
          {/* Progress Bar */}
          <View style={{
            height: 4,
            backgroundColor: theme.colors.surfaceVariant,
            borderRadius: 2,
            overflow: 'hidden',
          }}>
            <View
              style={{
                height: '100%',
                width: `${(step / 4) * 100}%`,
                backgroundColor: theme.colors.primary,
              }}
            />
          </View>
        </View>
        
        {/* Step Content */}
        <ScrollView contentContainerStyle={{
          flex: 1,
          padding: 16,
          justifyContent: 'space-between',
        }}>
          {step === 1 && (
            <RedemptionStep1Confirmation
              reward={reward}
              points={points}
              canAfford={canAfford}
            />
          )}
          {step === 2 && reward.requires_shipping && (
            <RedemptionStep2Address
              selectedAddressId={shippingAddressId}
              onSelectAddress={setShippingAddressId}
            />
          )}
          {step === 3 && reward.requires_phone && (
            <RedemptionStep3Phone
              phoneNumber={phoneNumber}
              onChangePhone={setPhoneNumber}
              error={error}
            />
          )}
          {step === 4 && (
            <RedemptionStep4Success reward={reward} />
          )}
        </ScrollView>
        
        {/* Footer Buttons */}
        {step < 4 && (
          <View style={{
            flexDirection: 'row',
            gap: 12,
            paddingHorizontal: 16,
            paddingVertical: 12,
            borderTopColor: theme.colors.outline,
            borderTopWidth: 1,
          }}>
            <Pressable
              onPress={handleBack}
              disabled={step === 1}
              style={{
                flex: 1,
                minHeight: 48,
                backgroundColor: step === 1
                  ? theme.colors.surfaceVariant
                  : theme.colors.surface,
                borderColor: theme.colors.outline,
                borderWidth: 1,
                borderRadius: 8,
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <Text style={{
                color: step === 1
                  ? theme.colors.onSurfaceVariant
                  : theme.colors.onSurface,
                fontWeight: '600',
              }}>
                Back
              </Text>
            </Pressable>
            
            <Pressable
              onPress={step === 3 ? handleConfirm : handleNext}
              disabled={isPending || !canAfford}
              style={{
                flex: 1,
                minHeight: 48,
                backgroundColor: (!canAfford || isPending)
                  ? theme.colors.surfaceVariant
                  : theme.colors.primary,
                borderRadius: 8,
                alignItems: 'center',
                justifyContent: 'center',
              }}
            >
              <Text style={{
                color: (!canAfford || isPending)
                  ? theme.colors.onSurfaceVariant
                  : theme.colors.onPrimary,
                fontWeight: '700',
              }}>
                {isPending ? 'Processing...' : (step === 3 ? 'Confirm' : 'Next')}
              </Text>
            </Pressable>
          </View>
        )}
      </SafeAreaView>
    </Modal>
  );
}

// Step 1: Confirmation
function RedemptionStep1Confirmation({
  reward,
  points,
  canAfford,
}: {
  reward: Reward;
  points: number;
  canAfford: boolean;
}) {
  const theme = useAppTheme();
  
  return (
    <View style={{gap: 16}}>
      <Text style={{
        fontSize: 16,
        fontWeight: '600',
        color: theme.colors.onSurface,
      }}>
        Confirm Redemption
      </Text>
      
      {/* Reward Summary */}
      <View style={{
        backgroundColor: theme.colors.surface,
        borderRadius: 12,
        padding: 16,
        gap: 12,
      }}>
        <Image
          source={{uri: reward.image_url}}
          style={{width: '100%', height: 150, borderRadius: 8}}
        />
        <Text style={{
          fontSize: 18,
          fontWeight: '700',
          color: theme.colors.onSurface,
        }}>
          {reward.name}
        </Text>
      </View>
      
      {/* Points Summary */}
      <View style={{
        backgroundColor: theme.colors.primaryContainer,
        borderRadius: 12,
        padding: 16,
        gap: 8,
      }}>
        <View style={{
          flexDirection: 'row',
          justifyContent: 'space-between',
        }}>
          <Text style={{color: theme.colors.onPrimaryContainer}}>
            You'll spend
          </Text>
          <Text style={{
            fontWeight: '700',
            color: theme.colors.primary,
            fontSize: 16,
          }}>
            {reward.price_points} pts
          </Text>
        </View>
        <View style={{
          flexDirection: 'row',
          justifyContent: 'space-between',
        }}>
          <Text style={{color: theme.colors.onPrimaryContainer}}>
            You'll have
          </Text>
          <Text style={{
            fontWeight: '700',
            color: theme.colors.primary,
            fontSize: 16,
          }}>
            {(points - reward.price_points).toLocaleString()} pts left
          </Text>
        </View>
      </View>
      
      {!canAfford && (
        <View style={{
          backgroundColor: theme.colors.errorContainer,
          borderRadius: 8,
          padding: 12,
          flexDirection: 'row',
          gap: 8,
        }}>
          <Text style={{fontSize: 16}}>⚠️</Text>
          <Text style={{
            fontSize: 12,
            color: theme.colors.onErrorContainer,
            flex: 1,
          }}>
            You need {reward.price_points - points} more points to redeem this reward.
          </Text>
        </View>
      )}
    </View>
  );
}

// Step 2: Shipping Address
function RedemptionStep2Address({
  selectedAddressId,
  onSelectAddress,
}: {
  selectedAddressId: string | null;
  onSelectAddress: (id: string) => void;
}) {
  const theme = useAppTheme();
  const { data: addressesResponse } = useMinorShippingAddresses();
  
  const addresses = addressesResponse?.data ?? [];
  
  return (
    <View style={{gap: 16}}>
      <Text style={{
        fontSize: 16,
        fontWeight: '600',
        color: theme.colors.onSurface,
      }}>
        Shipping Address
      </Text>
      
      <View style={{gap: 8}}>
        {addresses.map((addr) => (
          <Pressable
            key={addr.id}
            onPress={() => onSelectAddress(addr.id)}
            style={{
              backgroundColor: selectedAddressId === addr.id
                ? theme.colors.primaryContainer
                : theme.colors.surface,
              borderRadius: 8,
              padding: 12,
              borderColor: selectedAddressId === addr.id
                ? theme.colors.primary
                : theme.colors.outline,
              borderWidth: 1,
            }}
          >
            <Text style={{
              fontWeight: '600',
              color: theme.colors.onSurface,
            }}>
              {addr.label ?? 'Home'}
            </Text>
            <Text style={{
              fontSize: 12,
              color: theme.colors.onSurfaceVariant,
              marginTop: 4,
            }}>
              {addr.street}, {addr.city}
            </Text>
          </Pressable>
        ))}
      </View>
    </View>
  );
}

// Step 3: Phone Number
function RedemptionStep3Phone({
  phoneNumber,
  onChangePhone,
  error,
}: {
  phoneNumber: string;
  onChangePhone: (phone: string) => void;
  error: string | null;
}) {
  const theme = useAppTheme();
  
  return (
    <View style={{gap: 16}}>
      <Text style={{
        fontSize: 16,
        fontWeight: '600',
        color: theme.colors.onSurface,
      }}>
        Phone Number
      </Text>
      
      <Text style={{
        fontSize: 14,
        color: theme.colors.onSurfaceVariant,
      }}>
        We'll send your reward to this number
      </Text>
      
      <TextInput
        placeholder="+268 76 123 456"
        value={phoneNumber}
        onChangeText={onChangePhone}
        keyboardType="phone-pad"
        style={{
          minHeight: 48,
          backgroundColor: theme.colors.surface,
          borderColor: error ? theme.colors.error : theme.colors.outline,
          borderWidth: 1,
          borderRadius: 8,
          paddingHorizontal: 12,
          color: theme.colors.onSurface,
        }}
        placeholderTextColor={theme.colors.onSurfaceVariant}
      />
      
      {error && (
        <Text style={{
          fontSize: 12,
          color: theme.colors.error,
        }}>
          {error}
        </Text>
      )}
    </View>
  );
}

// Step 4: Success
// Requires: import LottieView from 'lottie-react-native';
function RedemptionStep4Success({reward}: {reward: Reward}) {
  const theme = useAppTheme();
  
  return (
    <View style={{
      alignItems: 'center',
      justifyContent: 'center',
      gap: 16,
    }}>
      <LottieView
        source={require('@/assets/animations/confetti.json')}
        autoPlay
        loop={false}
        style={{width: 200, height: 200}}
      />
      
      <Text style={{
        fontSize: 24,
        fontWeight: '700',
        color: theme.colors.onSurface,
        textAlign: 'center',
      }}>
        ✅ Reward Redeemed!
      </Text>
      
      <Text style={{
        fontSize: 14,
        color: theme.colors.onSurfaceVariant,
        textAlign: 'center',
        lineHeight: 20,
      }}>
        We're sending {reward.name} to you right now. Check your notifications!
      </Text>
    </View>
  );
}
```

**Hooks Required:**
- `useMinorPointsBalance(childId)` → Points balance
- `useSubmitRedemption(childId)` → Mutation hook for POST redemption
- `useMinorShippingAddresses()` → Saved addresses list

---

### Screen 5: Order History & Tracking

**Location:** "My Rewards" tab  
**Tabs:** All, Pending, Active, Complete, Failed

**Layout:**

```
┌────────────────────────────────┐
│ 📦 My Rewards                  │
├────────────────────────────────┤
│ All  Pending  Active  Complete │
├────────────────────────────────┤
│ Order Cards (List):            │
│ ┌──────────────────────────────┐
│ │ MTN 50 SZL Airtime           │
│ │ Order #RW-123456             │
│ │ 100 pts · 3 days ago         │
│ │ Status: ✓ Delivered          │
│ │ [View Details] [Share]       │
│ └──────────────────────────────┘
│ ┌──────────────────────────────┐
│ │ 1GB Data Bundle              │
│ │ ...                          │
│ └──────────────────────────────┘
└────────────────────────────────┘

Order Detail Screen (Separate):
┌────────────────────────────────┐
│ ← Order #RW-123456             │
├────────────────────────────────┤
│ Status: ✓ Delivered            │
│ MTN 50 SZL Airtime             │
│ 100 pts spent                  │
│                                │
│ Timeline:                      │
│ ✓ 2:15 PM — Order placed      │
│ ✓ 2:16 PM — Parent approved   │
│ ✓ 2:18 PM — Processed         │
│ ✓ 2:21 PM — Delivered         │
│                                │
│ [Leave Feedback] [Share]       │
└────────────────────────────────┘
```

**Component: `OrderHistoryScreen`**

```typescript
interface OrderHistoryScreenProps {
  minorAccountUuid: string;
}

export function OrderHistoryScreen({
  minorAccountUuid,
}: OrderHistoryScreenProps) {
  const theme = useAppTheme();
  const [tab, setTab] = useState<'all' | 'pending' | 'active' | 'complete' | 'failed'>('all');
  
  // Hook
  const { data: ordersResponse, isLoading } = useMinorRedemptionOrders(
    minorAccountUuid,
    tab === 'all' ? undefined : tab
  );
  
  const orders = ordersResponse?.data ?? [];
  
  return (
    <SafeAreaView style={{
      flex: 1,
      backgroundColor: theme.colors.background,
    }}>
      {/* Header */}
      <View style={{
        paddingHorizontal: 16,
        paddingVertical: 12,
        backgroundColor: theme.colors.surface,
        borderBottomColor: theme.colors.outline,
        borderBottomWidth: 1,
      }}>
        <Text style={{
          fontSize: 20,
          fontWeight: '700',
          color: theme.colors.onSurface,
        }}>
          📦 My Rewards
        </Text>
      </View>
      
      {/* Tabs */}
      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        style={{
          borderBottomColor: theme.colors.outline,
          borderBottomWidth: 1,
        }}
      >
        {(['all', 'pending', 'active', 'complete', 'failed'] as const).map((t) => (
          <Pressable
            key={t}
            onPress={() => setTab(t)}
            style={{
              paddingHorizontal: 16,
              paddingVertical: 12,
              borderBottomColor: tab === t ? theme.colors.primary : 'transparent',
              borderBottomWidth: tab === t ? 2 : 0,
            }}
          >
            <Text style={{
              fontWeight: tab === t ? '700' : '600',
              color: tab === t ? theme.colors.primary : theme.colors.onSurfaceVariant,
              textTransform: 'capitalize',
            }}>
              {t}
            </Text>
          </Pressable>
        ))}
      </ScrollView>
      
      {/* Order List */}
      <FlatList
        data={orders}
        keyExtractor={(item) => item.id.toString()}
        renderItem={({item}) => (
          <OrderCard
            order={item}
            onPress={() => {
              // Navigate to detail
            }}
          />
        )}
        contentContainerStyle={{
          padding: 12,
        }}
        ListEmptyComponent={
          <View style={{
            flex: 1,
            alignItems: 'center',
            justifyContent: 'center',
            paddingVertical: 40,
          }}>
            <Text style={{
              fontSize: 14,
              color: theme.colors.onSurfaceVariant,
              textAlign: 'center',
            }}>
              No {tab === 'all' ? 'redemptions' : `${tab} rewards`} yet.
              {tab === 'complete' && '\n\nStart redeeming to see your rewards here!'}
            </Text>
          </View>
        }
      />
    </SafeAreaView>
  );
}

interface OrderCardProps {
  order: RedemptionOrder;
  onPress: () => void;
}

function OrderCard({order, onPress}: OrderCardProps) {
  const theme = useAppTheme();
  
  const statusColor = {
    // warning/info/success are not standard MD3 tokens — use closest equivalents
    // or extend the theme with custom tokens before implementation
    pending: theme.colors.tertiary,       // amber-ish in most MD3 palettes
    active: theme.colors.primary,         // blue-ish
    complete: theme.colors.secondary,     // green-ish
    failed: theme.colors.error,
  }[order.status] ?? theme.colors.outline;
  
  const statusIcon = {
    pending: '⏳',
    active: '📦',
    complete: '✅',
    failed: '❌',
  }[order.status] ?? '❓';
  
  return (
    <Pressable
      onPress={onPress}
      style={{
        backgroundColor: theme.colors.surface,
        borderRadius: 12,
        padding: 12,
        marginBottom: 8,
        flexDirection: 'row',
        gap: 12,
      }}
    >
      {/* Thumbnail */}
      <Image
        source={{uri: order.reward.image_url}}
        style={{
          width: 60,
          height: 60,
          borderRadius: 8,
        }}
      />
      
      {/* Content */}
      <View style={{flex: 1, justifyContent: 'space-between'}}>
        <View>
          <Text style={{
            fontWeight: '600',
            color: theme.colors.onSurface,
            fontSize: 14,
          }}>
            {order.reward.name}
          </Text>
          <Text style={{
            fontSize: 11,
            color: theme.colors.onSurfaceVariant,
            marginTop: 2,
          }}>
            Order #{order.id.toString().slice(0, 6).toUpperCase()}
          </Text>
        </View>
        
        <View style={{flexDirection: 'row', gap: 8, alignItems: 'center'}}>
          <Text style={{
            fontSize: 11,
            color: theme.colors.onSurfaceVariant,
          }}>
            {order.points_redeemed} pts · {formatTimeAgo(order.created_at)}
          </Text>
        </View>
      </View>
      
      {/* Status */}
      <View style={{
        justifyContent: 'center',
        alignItems: 'flex-end',
        gap: 8,
      }}>
        <Text style={{fontSize: 16}}>{statusIcon}</Text>
        <Text style={{
          fontSize: 10,
          fontWeight: '600',
          color: statusColor,
          textTransform: 'capitalize',
        }}>
          {order.status}
        </Text>
      </View>
    </Pressable>
  );
}
```

**Hooks Required:**
- `useMinorRedemptionOrders(childId, status?)` → Paginated orders list

---

## State Management (Redux)

### Store Shape

```typescript
interface RewardsSliceState {
  // Catalog
  catalog: {
    list: Reward[];
    total: number;
    isLoading: boolean;
    filters: {
      category: string;
      priceRange: [number, number];
      inStock: boolean;
      sort: 'popular' | 'newest' | 'price-low' | 'price-high';
      query: string;
    };
    pagination: {
      page: number;
      limit: number;
      hasMore: boolean;
    };
  };
  
  // Detail
  detail: {
    reward: Reward | null;
    isLoading: boolean;
    error: string | null;
  };
  
  // Redemptions
  redemptions: {
    orders: RedemptionOrder[];
    selectedOrder: RedemptionOrder | null;
    isLoading: boolean;
    filters: {
      status: 'all' | 'pending' | 'active' | 'complete' | 'failed';
    };
  };
  
  // Parent Controls
  parentControls: {
    settings: {
      approvalThreshold: number;
      dailyLimit: number;
      blockedCategories: string[];
    };
    approvalQueue: RedemptionApproval[];
    analytics: {
      totalRedeemed: number;
      avgPerRedemption: number;
      topCategory: string;
    };
  };
}
```

### Redux Slices

```typescript
// slices/rewardsSlice.ts
import { createSlice, createAsyncThunk } from '@reduxjs/toolkit';

export const fetchCatalog = createAsyncThunk(
  'rewards/fetchCatalog',
  async (params: CatalogParams) => {
    const response = await minorRewardsApi.getCatalog(params);
    if (!response.data.success) throw new Error(response.data.message);
    return response.data.data;
  }
);

const rewardsSlice = createSlice({
  name: 'rewards',
  initialState,
  extraReducers: (builder) => {
    builder
      .addCase(fetchCatalog.pending, (state) => {
        state.catalog.isLoading = true;
      })
      .addCase(fetchCatalog.fulfilled, (state, action) => {
        state.catalog.list = action.payload;
        state.catalog.isLoading = false;
      })
      .addCase(fetchCatalog.rejected, (state, action) => {
        state.catalog.isLoading = false;
      });
  },
});

export default rewardsSlice.reducer;
```

---

## Testing Strategy (Mobile)

### 1. Component Source-Reading Tests

```typescript
// tests/features/minor-accounts/RewardsDashboardWidget.test.ts
import { readFileSync } from 'fs';
import { join } from 'path';

describe('RewardsDashboardWidget component source', () => {
  const source = readFileSync(
    join(__dirname, '../../../src/features/minor-accounts/presentation/RewardsDashboardWidget.tsx'),
    'utf-8'
  );
  
  it('exports RewardsDashboardWidget as named export', () => {
    expect(source).toMatch(/export function RewardsDashboardWidget/);
  });
  
  it('uses useAppTheme hook for color theming', () => {
    expect(source).toContain('useAppTheme()');
  });
  
  it('uses theme.colors.* for all colors (no hardcoded values)', () => {
    const hasHardcodedColors = /#[0-9a-f]{6}|rgba?\(/.test(source);
    expect(hasHardcodedColors).toBe(false);
  });
  
  it('uses useMinorPointsBalance hook', () => {
    expect(source).toContain('useMinorPointsBalance');
  });
  
  it('renders Pressable with minHeight 48 for buttons', () => {
    expect(source).toContain('minHeight: 48');
  });
  
  it('includes dark mode support', () => {
    expect(source).toContain('theme.colors.background');
    expect(source).toContain('theme.colors.onSurface');
  });
  
  it('sets 5-minute cache staleTime', () => {
    expect(source).toMatch(/staleTime.*1000\s*\*\s*60\s*\*\s*5/);
  });
  
  it('validates API response.data.success before using data', () => {
    expect(source).toContain('response.data.success');
    expect(source).toContain('throw new Error');
  });
  
  it('disables query if required ID is missing', () => {
    expect(source).toMatch(/enabled:\s*!!/);
  });
});
```

### 2. Hook Tests (Mocked API)

```typescript
// tests/features/minor-accounts/hooks/useMinorRewardsCatalog.test.ts
import { renderHook, waitFor } from '@testing-library/react-native';
import { useMinorRewardsCatalog } from '@/features/minor-accounts/hooks';
import * as api from '@/api/minorRewardsApi';

jest.mock('@/api/minorRewardsApi');

describe('useMinorRewardsCatalog hook', () => {
  it('fetches catalog with filters', async () => {
    const mockData = {
      data: {
        success: true,
        data: [{id: '1', name: 'Airtime', price_points: 100}],
      },
    };
    (api.getCatalog as jest.Mock).mockResolvedValue(mockData);
    
    const {result} = renderHook(() =>
      useMinorRewardsCatalog({category: 'airtime', limit: 12})
    );
    
    await waitFor(() => {
      expect(result.current.isLoading).toBe(false);
    });
    
    expect(result.current.data?.data).toEqual(mockData.data.data);
  });
  
  it('throws error if response.data.success is false', async () => {
    (api.getCatalog as jest.Mock).mockResolvedValue({
      data: {success: false, message: 'Failed'},
    });
    
    const {result} = renderHook(() => useMinorRewardsCatalog({}));
    
    await waitFor(() => {
      expect(result.current.error).toBeDefined();
    });
  });
  
  it('respects 5-minute staleTime', () => {
    const {result} = renderHook(() => useMinorRewardsCatalog({}));
    // Verify via React Query internals
    expect(result.current.isFetching).toBe(false); // Cached after first fetch
  });
});
```

### 3. E2E Flow Tests

```typescript
// tests/features/minor-accounts/RedemptionFlow.e2e.test.ts
import { render, fireEvent, waitFor } from '@testing-library/react-native';
import { RedemptionFlowModal } from '@/features/minor-accounts/presentation';

describe('Redemption flow E2E', () => {
  it('completes full redemption from confirmation to success', async () => {
    const mockReward = {
      id: '1',
      name: 'Airtime',
      price_points: 100,
      image_url: 'http://...',
      requires_shipping: false,
      requires_phone: true,
    };
    
    const onSuccess = jest.fn();
    const {getByText, getByPlaceholder} = render(
      <RedemptionFlowModal
        reward={mockReward}
        minorAccountUuid="uuid"
        onSuccess={onSuccess}
        onClose={jest.fn()}
      />
    );
    
    // Step 1: Confirm
    expect(getByText('Confirm Redemption')).toBeDefined();
    fireEvent.press(getByText('Next'));
    
    // Step 2: Phone
    const phoneInput = getByPlaceholder('+268 76 123 456');
    fireEvent.changeText(phoneInput, '+268 76 123 456');
    fireEvent.press(getByText('Confirm'));
    
    // Step 3: Success
    await waitFor(() => {
      expect(getByText('✅ Reward Redeemed!')).toBeDefined();
      expect(onSuccess).toHaveBeenCalled();
    });
  });
});
```

---

## Accessibility Standards

All components must follow:

1. **Touch Targets:** Minimum 48dp (button, pressable area)
2. **Colors:** `theme.colors.*` only (no hardcoded hex/rgba)
3. **Dark Mode:** Auto-adapt with useAppTheme()
4. **Contrast:** WCAG AA (4.5:1 for text)
5. **Text Scaling:** Support system font size scaling
6. **Labels:** All interactive elements have descriptive text

**Checklist for Phase 8:**
- [ ] All buttons/pressables ≥ 48dp
- [ ] All colors from theme (verified via source-reading tests)
- [ ] Dark mode tested on all screens
- [ ] No hardcoded values
- [ ] Tested with TalkBack (Android) / VoiceOver (iOS)

---

## File Structure

```
src/features/minor-accounts/
├─ domain/
│  ├─ rewardTypes.ts (Reward, RedemptionOrder, etc.)
│  ├─ redemptionTypes.ts (RedemptionApproval, etc.)
│  └─ merchantTypes.ts (MerchantPartner, QrBonus, etc.)
│
├─ hooks/
│  ├─ useMinorRewardsCatalog.ts
│  ├─ useMinorRewardDetail.ts
│  ├─ useMinorPointsBalance.ts
│  ├─ useMinorRedemptionOrders.ts
│  ├─ useSubmitRedemption.ts
│  ├─ useMinorShippingAddresses.ts
│  └─ useParentRedemptionApprovals.ts
│
├─ presentation/
│  ├─ RewardsDashboardWidget.tsx
│  ├─ RewardsCatalogScreen.tsx
│  ├─ RewardDetailModal.tsx
│  ├─ RedemptionFlowModal.tsx
│  │  ├─ steps/
│  │  │  ├─ RedemptionStep1Confirmation.tsx
│  │  │  ├─ RedemptionStep2Address.tsx
│  │  │  ├─ RedemptionStep3Phone.tsx
│  │  │  └─ RedemptionStep4Success.tsx
│  ├─ OrderHistoryScreen.tsx
│  ├─ OrderDetailScreen.tsx
│  ├─ EarningProgressScreen.tsx
│  └─ components/
│     ├─ RewardCard.tsx
│     ├─ OrderCard.tsx
│     ├─ FilterBottomSheet.tsx
│     └─ StatusTimeline.tsx
│
├─ api/
│  └─ minorRewardsApi.ts (API client methods)
│
└─ store/
   ├─ rewardsSlice.ts
   └─ redemptionsSlice.ts

tests/features/minor-accounts/
├─ RewardsDashboardWidget.test.ts
├─ RewardsCatalogScreen.test.ts
├─ RedemptionFlowModal.test.ts
├─ OrderHistoryScreen.test.ts
├─ hooks/
│  ├─ useMinorRewardsCatalog.test.ts
│  ├─ useSubmitRedemption.test.ts
│  └─ ...
└─ e2e/
   └─ RedemptionFlow.e2e.test.ts
```

---

## Implementation Checklist

### Phase 8 Delivery Milestones

**Week 1: Foundation**
- [ ] Domain types (Reward, RedemptionOrder, MerchantPartner)
- [ ] API hooks (catalog, detail, points, orders)
- [ ] Redux slices (catalog, redemptions, parent controls)
- [ ] 20+ source-reading tests passing

**Week 2: Child Screens**
- [ ] RewardsDashboardWidget (home tab integration)
- [ ] RewardsCatalogScreen (browse, filter, search)
- [ ] RewardDetailModal (view full details)
- [ ] 40+ tests passing

**Week 3: Redemption Flow**
- [ ] RedemptionFlowModal (4-step checkout)
- [ ] Step 1–4 components (confirmation, address, phone, success)
- [ ] useSubmitRedemption hook (mutation)
- [ ] Success animation (confetti)
- [ ] 60+ tests passing

**Week 4: Order Tracking & Parent Controls**
- [ ] OrderHistoryScreen (My Rewards tab)
- [ ] OrderDetailScreen (full tracking timeline)
- [ ] Parent approval queue (Filament integration)
- [ ] Parent limits settings (Filament form)
- [ ] Analytics dashboard (Filament graphs)
- [ ] 80+ tests passing

**Final: Integration & Polish**
- [ ] End-to-end flow testing (child → parent → merchant)
- [ ] Dark mode verification
- [ ] Accessibility audit (48dp, theme colors, contrast)
- [ ] Performance profiling (lazy loading, bundle size)
- [ ] Real backend API integration (mock → real)
- [ ] All tests passing, no console errors/warnings

---

## Known Constraints & Deferred Features

### Phase 8 In Scope
- Child reward catalog browsing
- Redemption checkout (4-step flow)
- Order tracking & timeline
- Parent approval workflow
- Parent limits & blocking
- Merchant fulfillment queue

### Phase 9+ (Deferred)
- Reward reviews & ratings UI
- Child-to-child reward gifting
- Recurring subscription redemptions
- Gamified leaderboards (school-wide)
- Interest accrual UI
- Recurring chore scheduling (admin only)

### External Dependencies
- Backend APIs must be live (Phase 8 backoffice)
- Merchant partner integrations (MTN, Shoprite, etc.)
- WebSocket Soketi setup for real-time updates
- Firebase Cloud Messaging for push notifications

---

## Success Criteria

- ✅ 80+ tests passing (all source-reading, unit, integration)
- ✅ Zero hardcoded colors (all theme.colors.*)
- ✅ Dark mode tested on all screens
- ✅ All buttons/pressables ≥ 48dp
- ✅ No console errors/warnings
- ✅ TypeScript strict mode passing
- ✅ 5-minute cache staleTime on all queries
- ✅ Query guards (`enabled: !!uuid`)
- ✅ Success flag validation on all API responses
- ✅ Full feature parity with backend spec
- ✅ Integration with KidDashboard (Home, Shop, MyRewards, Insights tabs)

---

**Mobile Phase 8 UI Spec Complete. Ready for implementation.**

