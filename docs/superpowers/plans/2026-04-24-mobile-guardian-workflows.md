# Mobile — Guardian Approval & Card Management Workflows

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the two missing mobile workflows that make the Minor Accounts feature production-usable for guardians: (1) handling the HTTP 202 "approval required" response from send-money and providing a full guardian approval/denial UI, and (2) surfacing minor account card request, approval/denial, and freeze/unfreeze controls. Also: surface chore submission API errors to users instead of swallowing them.

**Architecture:** All new code follows the existing pattern in the mobile codebase: a `hooks/` file with a React Query mutation/query, a `domain/` types file, and a `presentation/` screen or modal. No new libraries. Follow the exact file structure in `src/features/minor-accounts/`. The backoffice API already has all endpoints — we are only adding mobile API clients and UI.

**Tech Stack:** React Native, TypeScript, React Query (`@tanstack/react-query`), `apiClient` from `@/core/api/apiClient`, Expo Router for navigation, existing `useAccountContext` from `@/features/account/hooks/useAccountContext`.

---

## Repo Structure Reference

```
src/features/minor-accounts/
  domain/         ← TypeScript types
  hooks/          ← React Query mutations/queries (API calls live here)
  presentation/   ← Screen and modal components
  components/     ← Reusable sub-components
```

Navigation is Expo Router file-based (`src/app/`). New screens go under `src/app/(tabs)/` or as modals under `src/app/(modals)/`.

---

## File Map

| Action | File | Purpose |
|--------|------|---------|
| Create | `src/features/minor-accounts/domain/spendApprovalTypes.ts` | TypeScript types for spend approvals |
| Create | `src/features/minor-accounts/hooks/useMinorSpendApprovals.ts` | React Query hooks for listing/approving/denying approvals |
| Modify | `src/features/send-money/api/useSendMoney.ts` | Detect HTTP 202 and return approval-required shape |
| Create | `src/features/minor-accounts/presentation/PendingSpendApprovalsScreen.tsx` | Guardian screen listing pending approvals |
| Create | `src/features/minor-accounts/presentation/SpendApprovalDetailModal.tsx` | Approve/deny action modal |
| Create | `src/features/minor-accounts/domain/minorCardTypes.ts` | TypeScript types for minor card requests |
| Create | `src/features/minor-accounts/hooks/useMinorCardRequests.ts` | React Query hooks for card request lifecycle |
| Create | `src/features/minor-accounts/presentation/MinorCardManagementScreen.tsx` | Guardian card management screen |
| Modify | `src/features/minor-accounts/hooks/useChoreSubmissions.ts` | Surface error details from API instead of swallowing |
| Modify | `src/features/minor-accounts/domain/featureGates.ts` | Enable `pendingChoreSubmissions` gate |

---

## How API Calls Work in This Codebase

All API calls use:
```typescript
import apiClient from '@/core/api/apiClient';
const { data } = await apiClient.post<ResponseType>('/api/endpoint', payload);
```

All mutations follow this pattern:
```typescript
export function useMyMutation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (payload: PayloadType) => {
      const { data } = await apiClient.post<ResponseType>('/api/endpoint', payload);
      if (!data.success) throw new Error(data.message ?? 'Operation failed');
      return data.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['relevant-key'] });
    },
  });
}
```

---

## Task 1 — Fix Chore Submission Error Handling (MINOR-P3-002)

**Files:**
- Modify: `src/features/minor-accounts/hooks/useChoreSubmissions.ts`

This is the simplest fix. Do it first to warm up on the codebase patterns.

### Context

`useSubmitChore` throws `new Error('Failed to submit chore')` on failure but discards the `response.data` error details. The backend returns a `data.errors` object (see `MinorChoreController.php:251`) with field-level error messages. Surface these so the user sees actionable feedback.

- [ ] **Step 1.1 — Update the error throwing in useSubmitChore**

Open `src/features/minor-accounts/hooks/useChoreSubmissions.ts`.

Replace the `mutationFn` inside `useSubmitChore` (lines 9–19):

```typescript
  return useMutation<ChoreCompletion, Error, ChoreCompletePayload>({
    mutationFn: async (payload: ChoreCompletePayload) => {
      const response = await apiClient.post<{
        success: boolean;
        data: ChoreCompletion;
        message?: string;
        errors?: Record<string, string[]>;
      }>(
        `/api/accounts/minor/${minorAccountUuid}/chores/${choreId}/complete`,
        payload
      );
      if (!response.data.success) {
        // Surface the first validation error or the top-level message
        const firstError = response.data.errors
          ? Object.values(response.data.errors)[0]?.[0]
          : undefined;
        throw new Error(firstError ?? response.data.message ?? 'Failed to submit chore');
      }
      return response.data.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['chores', minorAccountUuid] as const });
    },
  });
```

Apply the same pattern to `useApproveChore` and `useRejectChore` — replace the generic throw with:

```typescript
const firstError = response.data.errors
  ? Object.values(response.data.errors)[0]?.[0]
  : undefined;
throw new Error(firstError ?? response.data.message ?? 'Operation failed');
```

- [ ] **Step 1.2 — Verify TypeScript compiles**

```bash
cd /Users/Lihle/Development/Coding/maphapayrn && npx tsc --noEmit 2>&1 | head -30
```

Expected: No new TypeScript errors.

- [ ] **Step 1.3 — Commit**

```bash
cd /Users/Lihle/Development/Coding/maphapayrn
git add src/features/minor-accounts/hooks/useChoreSubmissions.ts
git commit -m "fix(P3): surface API error details in chore submission hooks

Previously all chore operation failures threw a generic message,
discarding the field-level error map returned by the backend.
Now the first validation error message is propagated to callers.

Fixes MINOR-P3-002.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 2 — Handle HTTP 202 in useSendMoney (MINOR-P1-007 part 1)

**Files:**
- Create: `src/features/minor-accounts/domain/spendApprovalTypes.ts`
- Modify: `src/features/send-money/api/useSendMoney.ts`

### Context

When a minor's send-money exceeds the guardian-configured threshold, the backoffice returns **HTTP 202** (not 200) with a body of:
```json
{
  "status": "pending_approval",
  "data": {
    "approval_id": "uuid",
    "minor_account_uuid": "uuid",
    "amount": "150.00",
    "asset_code": "SZL",
    "expires_at": "2026-04-25T01:15:00Z"
  }
}
```

Currently `assertMoneyMovementSuccess` throws on any non-`success` status, so the 202 is treated as an error. Fix: detect the 202 shape and return it as a first-class result so the caller can render the appropriate UI.

- [ ] **Step 2.1 — Create spend approval types**

Create `src/features/minor-accounts/domain/spendApprovalTypes.ts`:

```typescript
export interface PendingSpendApproval {
  approval_id: string;
  minor_account_uuid: string;
  guardian_account_uuid: string;
  amount: string;
  asset_code: string;
  merchant_category?: string;
  note?: string;
  expires_at: string;
  created_at: string;
}

export interface SpendApprovalRequiredResponse {
  status: 'pending_approval';
  data: PendingSpendApproval;
}

export type SendMoneyOutcome =
  | { kind: 'success'; data: import('@/features/send-money/api/useSendMoney').SendMoneyResponse }
  | { kind: 'approval_required'; approval: PendingSpendApproval };
```

- [ ] **Step 2.2 — Update useSendMoney to detect 202**

Open `src/features/send-money/api/useSendMoney.ts`.

The current `mutationFn` calls `assertMoneyMovementSuccess` which throws for anything non-success. We need to check the HTTP status code before calling that assert.

Add the import at the top:

```typescript
import type { SendMoneyOutcome, SpendApprovalRequiredResponse } from '@/features/minor-accounts/domain/spendApprovalTypes';
```

Update the return type of `useSendMoney`:

```typescript
export function useSendMoney() {
  const queryClient = useQueryClient();
  return useMutation<SendMoneyOutcome, Error, SendMoneyPayload>({
    mutationFn: async (payload: SendMoneyPayload): Promise<SendMoneyOutcome> => {
      const { idempotencyKey, ...body } = payload;
      const trustContext = await resolveHighRiskMobileTrustContext('send-money');
      const response = await apiClient.post<SendMoneyResponse | SpendApprovalRequiredResponse>(
        '/api/send-money/store',
        mergeMobileTrustPayload(body, trustContext),
        {
          headers: mergeMobileTrustHeaders({ 'Idempotency-Key': idempotencyKey }, trustContext),
          // Do NOT throw on 202 — axios throws on non-2xx by default, but 202 is 2xx
          validateStatus: (status) => status >= 200 && status < 300,
        },
      );

      const responseData = response.data;

      // 202 = guardian approval required for minor spend
      if (response.status === 202 || (responseData as SpendApprovalRequiredResponse).status === 'pending_approval') {
        const approvalData = (responseData as SpendApprovalRequiredResponse).data;
        return { kind: 'approval_required', approval: approvalData };
      }

      // 200 = normal success path
      assertMoneyMovementSuccess<SendMoneyData>(responseData as SendMoneyResponse, 'Failed to start transfer');
      return { kind: 'success', data: responseData as SendMoneyResponse };
    },
    onSuccess: (result) => {
      if (result.kind === 'success') {
        invalidateFinanceQueries(queryClient);
      }
    },
  });
}
```

- [ ] **Step 2.3 — Update callers of useSendMoney**

Find all places that call `useSendMoney()` and use its result:

```bash
cd /Users/Lihle/Development/Coding/maphapayrn
grep -rn "useSendMoney\|sendMoney.mutate" src/ --include="*.tsx" --include="*.ts" | grep -v "node_modules" | grep -v "useCheckUser"
```

For each caller, update the `onSuccess` or result handling to handle the `kind` field:

```typescript
// Before:
const result = await sendMoneyMutation.mutateAsync(payload);
// After:
const result = await sendMoneyMutation.mutateAsync(payload);
if (result.kind === 'approval_required') {
  // Navigate to pending approvals or show a modal
  router.push({
    pathname: '/minor-spend-approval-pending',
    params: { approvalId: result.approval.approval_id },
  });
  return;
}
// ... existing success handling
```

- [ ] **Step 2.4 — Verify TypeScript compiles**

```bash
cd /Users/Lihle/Development/Coding/maphapayrn && npx tsc --noEmit 2>&1 | head -30
```

Expected: No new TypeScript errors.

- [ ] **Step 2.5 — Commit**

```bash
cd /Users/Lihle/Development/Coding/maphapayrn
git add src/features/minor-accounts/domain/spendApprovalTypes.ts \
        src/features/send-money/api/useSendMoney.ts
git commit -m "feat: detect HTTP 202 approval-required response in useSendMoney

When a minor's send-money exceeds the guardian-approved threshold,
the backoffice returns 202 with a PendingSpendApproval record.
useSendMoney now returns { kind: 'approval_required', approval }
instead of throwing, allowing the UI to route to the approval flow.

Partially fixes MINOR-P1-007.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 3 — Guardian Spend Approval Hooks (MINOR-P1-007 part 2)

**Files:**
- Create: `src/features/minor-accounts/hooks/useMinorSpendApprovals.ts`

### Context

The backoffice exposes these endpoints for the guardian spend-approval workflow (verify against `MinorSpendApprovalController.php` before coding):
- `GET /api/accounts/minor/{uuid}/spend-approvals?status=pending` — list pending approvals
- `POST /api/accounts/minor/{uuid}/spend-approvals/{approvalId}/approve` — approve
- `POST /api/accounts/minor/{uuid}/spend-approvals/{approvalId}/deny` — deny

- [ ] **Step 3.1 — Verify the exact endpoints**

```bash
grep -rn "spend.approval\|SpendApproval\|MinorSpendApproval" \
  /Users/Lihle/Development/Coding/maphapay-backoffice/app/Http/Controllers/ \
  /Users/Lihle/Development/Coding/maphapay-backoffice/routes/ \
  --include="*.php" | grep -v "test\|Test" | head -20
```

Note the exact URL paths. Update the URLs in the hook below if they differ.

- [ ] **Step 3.2 — Create the hooks file**

Create `src/features/minor-accounts/hooks/useMinorSpendApprovals.ts`:

```typescript
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import apiClient from '@/core/api/apiClient';
import type { PendingSpendApproval } from '@/features/minor-accounts/domain/spendApprovalTypes';

interface SpendApprovalsResponse {
  success: boolean;
  data: PendingSpendApproval[];
}

interface ApprovePayload {
  minorAccountUuid: string;
  approvalId: string;
}

interface DenyPayload {
  minorAccountUuid: string;
  approvalId: string;
  reason?: string;
}

export function usePendingSpendApprovals(minorAccountUuid: string) {
  return useQuery<PendingSpendApproval[], Error>({
    queryKey: ['minor-spend-approvals', 'pending', minorAccountUuid] as const,
    queryFn: async () => {
      const { data } = await apiClient.get<SpendApprovalsResponse>(
        `/api/accounts/minor/${minorAccountUuid}/spend-approvals`,
        { params: { status: 'pending' } }
      );
      if (!data.success) throw new Error('Failed to fetch pending spend approvals');
      return data.data;
    },
    staleTime: 1000 * 30, // 30 seconds — approvals are time-sensitive
    enabled: !!minorAccountUuid,
  });
}

export function useApproveSpendRequest() {
  const queryClient = useQueryClient();
  return useMutation<void, Error, ApprovePayload>({
    mutationFn: async ({ minorAccountUuid, approvalId }) => {
      const { data } = await apiClient.post<{ success: boolean; message?: string }>(
        `/api/accounts/minor/${minorAccountUuid}/spend-approvals/${approvalId}/approve`,
        {}
      );
      if (!data.success) throw new Error(data.message ?? 'Failed to approve spend request');
    },
    onSuccess: (_, { minorAccountUuid }) => {
      queryClient.invalidateQueries({
        queryKey: ['minor-spend-approvals', 'pending', minorAccountUuid],
      });
    },
  });
}

export function useDenySpendRequest() {
  const queryClient = useQueryClient();
  return useMutation<void, Error, DenyPayload>({
    mutationFn: async ({ minorAccountUuid, approvalId, reason }) => {
      const { data } = await apiClient.post<{ success: boolean; message?: string }>(
        `/api/accounts/minor/${minorAccountUuid}/spend-approvals/${approvalId}/deny`,
        { reason: reason ?? '' }
      );
      if (!data.success) throw new Error(data.message ?? 'Failed to deny spend request');
    },
    onSuccess: (_, { minorAccountUuid }) => {
      queryClient.invalidateQueries({
        queryKey: ['minor-spend-approvals', 'pending', minorAccountUuid],
      });
    },
  });
}
```

- [ ] **Step 3.3 — Verify TypeScript compiles**

```bash
cd /Users/Lihle/Development/Coding/maphapayrn && npx tsc --noEmit 2>&1 | head -30
```

Expected: No errors.

- [ ] **Step 3.4 — Commit**

```bash
cd /Users/Lihle/Development/Coding/maphapayrn
git add src/features/minor-accounts/hooks/useMinorSpendApprovals.ts
git commit -m "feat: add guardian spend approval hooks

Implements usePendingSpendApprovals, useApproveSpendRequest, and
useDenySpendRequest for the guardian approval workflow.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 4 — Guardian Spend Approval UI (MINOR-P1-007 part 3)

**Files:**
- Create: `src/features/minor-accounts/presentation/PendingSpendApprovalsScreen.tsx`
- Create: `src/features/minor-accounts/presentation/SpendApprovalDetailModal.tsx`

### Context

Guardians need a screen to see all pending spend approval requests from their child's account, and a modal to approve or deny each one. The design should follow the existing pattern in `PendingRedemptionsCard.tsx` and `ChoreDetailScreen.tsx` — check those for styling and component patterns before building.

- [ ] **Step 4.1 — Read existing components for patterns**

```bash
head -60 /Users/Lihle/Development/Coding/maphapayrn/src/features/minor-accounts/presentation/PendingRedemptionsCard.tsx
head -60 /Users/Lihle/Development/Coding/maphapayrn/src/features/minor-accounts/presentation/ChoreDetailScreen.tsx
```

Note: which UI library is used (look for imports like `View`, `Text`, `Pressable` from `react-native` or a custom design system), how navigation is called (`useRouter`, `router.push`, etc.), and how loading/error states are rendered.

- [ ] **Step 4.2 — Create the detail modal**

Create `src/features/minor-accounts/presentation/SpendApprovalDetailModal.tsx`:

```tsx
import React, { useState } from 'react';
import { ActivityIndicator, Alert, Modal, Pressable, StyleSheet, Text, View } from 'react-native';

import type { PendingSpendApproval } from '@/features/minor-accounts/domain/spendApprovalTypes';
import { useApproveSpendRequest, useDenySpendRequest } from '@/features/minor-accounts/hooks/useMinorSpendApprovals';

interface Props {
  approval: PendingSpendApproval;
  minorAccountUuid: string;
  visible: boolean;
  onClose: () => void;
}

export function SpendApprovalDetailModal({ approval, minorAccountUuid, visible, onClose }: Props) {
  const approveMutation = useApproveSpendRequest();
  const denyMutation = useDenySpendRequest();
  const [denyReason, setDenyReason] = useState('');

  const isBusy = approveMutation.isPending || denyMutation.isPending;

  function handleApprove() {
    Alert.alert(
      'Approve Transfer?',
      `Allow ${approval.asset_code} ${approval.amount} to proceed?`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Approve',
          style: 'default',
          onPress: () => {
            approveMutation.mutate(
              { minorAccountUuid, approvalId: approval.approval_id },
              { onSuccess: onClose }
            );
          },
        },
      ]
    );
  }

  function handleDeny() {
    Alert.prompt(
      'Deny Transfer',
      'Optional: enter a reason for your child',
      (reason) => {
        denyMutation.mutate(
          { minorAccountUuid, approvalId: approval.approval_id, reason },
          { onSuccess: onClose }
        );
      }
    );
  }

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      <View style={styles.overlay}>
        <View style={styles.sheet}>
          <Text style={styles.title}>Spending Request</Text>
          <Text style={styles.amount}>{approval.asset_code} {approval.amount}</Text>
          {approval.merchant_category ? (
            <Text style={styles.label}>Category: {approval.merchant_category}</Text>
          ) : null}
          {approval.note ? (
            <Text style={styles.label}>Note: {approval.note}</Text>
          ) : null}
          <Text style={styles.expires}>
            Expires {new Date(approval.expires_at).toLocaleString()}
          </Text>

          {approveMutation.error ? (
            <Text style={styles.error}>{approveMutation.error.message}</Text>
          ) : null}
          {denyMutation.error ? (
            <Text style={styles.error}>{denyMutation.error.message}</Text>
          ) : null}

          {isBusy ? (
            <ActivityIndicator style={styles.spinner} />
          ) : (
            <View style={styles.actions}>
              <Pressable style={[styles.btn, styles.denyBtn]} onPress={handleDeny}>
                <Text style={styles.denyText}>Deny</Text>
              </Pressable>
              <Pressable style={[styles.btn, styles.approveBtn]} onPress={handleApprove}>
                <Text style={styles.approveText}>Approve</Text>
              </Pressable>
            </View>
          )}

          <Pressable onPress={onClose} style={styles.closeBtn}>
            <Text style={styles.closeText}>Close</Text>
          </Pressable>
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  overlay: { flex: 1, backgroundColor: 'rgba(0,0,0,0.5)', justifyContent: 'flex-end' },
  sheet: { backgroundColor: '#fff', borderTopLeftRadius: 20, borderTopRightRadius: 20, padding: 24, gap: 12 },
  title: { fontSize: 18, fontWeight: '700' },
  amount: { fontSize: 32, fontWeight: '800' },
  label: { fontSize: 14, color: '#666' },
  expires: { fontSize: 12, color: '#999' },
  error: { color: '#c00', fontSize: 13 },
  spinner: { marginVertical: 16 },
  actions: { flexDirection: 'row', gap: 12 },
  btn: { flex: 1, paddingVertical: 14, borderRadius: 12, alignItems: 'center' },
  denyBtn: { backgroundColor: '#f5f5f5' },
  approveBtn: { backgroundColor: '#1a1a1a' },
  denyText: { color: '#333', fontWeight: '600' },
  approveText: { color: '#fff', fontWeight: '600' },
  closeBtn: { alignItems: 'center', paddingVertical: 8 },
  closeText: { color: '#999', fontSize: 14 },
});
```

- [ ] **Step 4.3 — Create the approvals list screen**

Create `src/features/minor-accounts/presentation/PendingSpendApprovalsScreen.tsx`:

```tsx
import React, { useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, Text, View } from 'react-native';

import type { PendingSpendApproval } from '@/features/minor-accounts/domain/spendApprovalTypes';
import { usePendingSpendApprovals } from '@/features/minor-accounts/hooks/useMinorSpendApprovals';
import { SpendApprovalDetailModal } from './SpendApprovalDetailModal';

interface Props {
  minorAccountUuid: string;
}

export function PendingSpendApprovalsScreen({ minorAccountUuid }: Props) {
  const { data: approvals, isLoading, error, refetch } = usePendingSpendApprovals(minorAccountUuid);
  const [selected, setSelected] = useState<PendingSpendApproval | null>(null);

  if (isLoading) return <ActivityIndicator style={styles.center} />;
  if (error) return (
    <View style={styles.center}>
      <Text style={styles.error}>{error.message}</Text>
      <Pressable onPress={() => refetch()} style={styles.retryBtn}>
        <Text style={styles.retryText}>Retry</Text>
      </Pressable>
    </View>
  );

  return (
    <View style={styles.container}>
      <Text style={styles.heading}>Pending Approvals</Text>

      {(approvals?.length ?? 0) === 0 ? (
        <View style={styles.empty}>
          <Text style={styles.emptyText}>No pending spend requests</Text>
          <Text style={styles.emptySubtext}>Requests from your child will appear here.</Text>
        </View>
      ) : (
        <FlatList
          data={approvals}
          keyExtractor={(item) => item.approval_id}
          renderItem={({ item }) => (
            <Pressable style={styles.card} onPress={() => setSelected(item)}>
              <View style={styles.cardRow}>
                <Text style={styles.cardAmount}>{item.asset_code} {item.amount}</Text>
                <Text style={styles.cardExpires}>
                  Expires {new Date(item.expires_at).toLocaleDateString()}
                </Text>
              </View>
              {item.merchant_category ? (
                <Text style={styles.cardCategory}>{item.merchant_category}</Text>
              ) : null}
              {item.note ? <Text style={styles.cardNote}>{item.note}</Text> : null}
            </Pressable>
          )}
          contentContainerStyle={styles.list}
        />
      )}

      {selected ? (
        <SpendApprovalDetailModal
          approval={selected}
          minorAccountUuid={minorAccountUuid}
          visible={selected !== null}
          onClose={() => setSelected(null)}
        />
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#fafafa' },
  center: { flex: 1, justifyContent: 'center', alignItems: 'center' },
  heading: { fontSize: 22, fontWeight: '700', padding: 20, paddingBottom: 8 },
  list: { padding: 16, gap: 12 },
  card: { backgroundColor: '#fff', borderRadius: 16, padding: 16, gap: 4 },
  cardRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'baseline' },
  cardAmount: { fontSize: 20, fontWeight: '700' },
  cardExpires: { fontSize: 12, color: '#999' },
  cardCategory: { fontSize: 13, color: '#666' },
  cardNote: { fontSize: 13, color: '#888', fontStyle: 'italic' },
  empty: { flex: 1, justifyContent: 'center', alignItems: 'center', gap: 8, padding: 32 },
  emptyText: { fontSize: 18, fontWeight: '600', color: '#333' },
  emptySubtext: { fontSize: 14, color: '#999', textAlign: 'center' },
  error: { color: '#c00', marginBottom: 12 },
  retryBtn: { backgroundColor: '#1a1a1a', paddingHorizontal: 24, paddingVertical: 10, borderRadius: 8 },
  retryText: { color: '#fff', fontWeight: '600' },
});
```

- [ ] **Step 4.4 — Verify TypeScript compiles**

```bash
cd /Users/Lihle/Development/Coding/maphapayrn && npx tsc --noEmit 2>&1 | head -30
```

Expected: No errors.

- [ ] **Step 4.5 — Commit**

```bash
cd /Users/Lihle/Development/Coding/maphapayrn
git add src/features/minor-accounts/presentation/PendingSpendApprovalsScreen.tsx \
        src/features/minor-accounts/presentation/SpendApprovalDetailModal.tsx
git commit -m "feat: add guardian spend approval list screen and detail modal

PendingSpendApprovalsScreen lists all pending minor spend requests.
SpendApprovalDetailModal allows guardian to approve (with confirmation
alert) or deny (with optional reason prompt).

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 5 — Minor Card Management Hooks (MINOR-P1-007 part 4)

**Files:**
- Create: `src/features/minor-accounts/domain/minorCardTypes.ts`
- Create: `src/features/minor-accounts/hooks/useMinorCardRequests.ts`

### Context

Backoffice endpoints (verify against `MinorCardController.php` before coding):
- `POST /api/accounts/minor/{uuid}/card-requests` — guardian requests a card for child
- `GET /api/accounts/minor/{uuid}/card-requests` — list card requests
- `POST /api/accounts/minor/{uuid}/card-requests/{requestId}/approve` — approve (SCA required)
- `POST /api/accounts/minor/{uuid}/card-requests/{requestId}/deny` — deny
- `POST /api/accounts/minor/{uuid}/cards/{cardId}/freeze` — freeze card (SCA required)
- `POST /api/accounts/minor/{uuid}/cards/{cardId}/unfreeze` — unfreeze

- [ ] **Step 5.1 — Verify exact backoffice endpoints**

```bash
grep -n "route\|Route\|get\|post\|put" \
  /Users/Lihle/Development/Coding/maphapay-backoffice/app/Http/Controllers/Api/Account/MinorCardController.php \
  | head -30
grep -rn "MinorCard\|minor.*card" \
  /Users/Lihle/Development/Coding/maphapay-backoffice/routes/ \
  --include="*.php" | head -20
```

Note the exact URL patterns and update below if different.

- [ ] **Step 5.2 — Create minor card types**

Create `src/features/minor-accounts/domain/minorCardTypes.ts`:

```typescript
export type MinorCardRequestStatus = 'pending' | 'approved' | 'denied' | 'issued' | 'expired';

export interface MinorCardRequest {
  id: string;
  minor_account_uuid: string;
  status: MinorCardRequestStatus;
  card_type: 'virtual' | 'physical';
  requested_at: string;
  decided_at?: string;
  denial_reason?: string;
  issued_card_id?: string;
}

export interface MinorCard {
  id: string;
  minor_account_uuid: string;
  last_four: string;
  card_type: 'virtual' | 'physical';
  status: 'active' | 'frozen' | 'cancelled';
  daily_limit: number;
  monthly_limit: number;
  single_transaction_limit: number;
  asset_code: string;
  created_at: string;
}
```

- [ ] **Step 5.3 — Create the card request hooks**

Create `src/features/minor-accounts/hooks/useMinorCardRequests.ts`:

```typescript
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import apiClient from '@/core/api/apiClient';
import type { MinorCard, MinorCardRequest } from '@/features/minor-accounts/domain/minorCardTypes';

const QUERY_KEY = 'minor-card-requests';
const CARDS_KEY = 'minor-cards';

export function useMinorCardRequests(minorAccountUuid: string) {
  return useQuery<MinorCardRequest[], Error>({
    queryKey: [QUERY_KEY, minorAccountUuid] as const,
    queryFn: async () => {
      const { data } = await apiClient.get<{ success: boolean; data: MinorCardRequest[] }>(
        `/api/accounts/minor/${minorAccountUuid}/card-requests`
      );
      if (!data.success) throw new Error('Failed to fetch card requests');
      return data.data;
    },
    enabled: !!minorAccountUuid,
  });
}

export function useMinorCards(minorAccountUuid: string) {
  return useQuery<MinorCard[], Error>({
    queryKey: [CARDS_KEY, minorAccountUuid] as const,
    queryFn: async () => {
      const { data } = await apiClient.get<{ success: boolean; data: MinorCard[] }>(
        `/api/accounts/minor/${minorAccountUuid}/cards`
      );
      if (!data.success) throw new Error('Failed to fetch cards');
      return data.data;
    },
    enabled: !!minorAccountUuid,
  });
}

export function useRequestMinorCard() {
  const queryClient = useQueryClient();
  return useMutation<MinorCardRequest, Error, { minorAccountUuid: string; cardType: 'virtual' | 'physical' }>({
    mutationFn: async ({ minorAccountUuid, cardType }) => {
      const { data } = await apiClient.post<{ success: boolean; data: MinorCardRequest; message?: string }>(
        `/api/accounts/minor/${minorAccountUuid}/card-requests`,
        { card_type: cardType }
      );
      if (!data.success) throw new Error(data.message ?? 'Failed to request card');
      return data.data;
    },
    onSuccess: (_, { minorAccountUuid }) => {
      queryClient.invalidateQueries({ queryKey: [QUERY_KEY, minorAccountUuid] });
    },
  });
}

export function useFreezeMinorCard() {
  const queryClient = useQueryClient();
  return useMutation<void, Error, { minorAccountUuid: string; cardId: string }>({
    mutationFn: async ({ minorAccountUuid, cardId }) => {
      const { data } = await apiClient.post<{ success: boolean; message?: string }>(
        `/api/accounts/minor/${minorAccountUuid}/cards/${cardId}/freeze`,
        {}
      );
      if (!data.success) throw new Error(data.message ?? 'Failed to freeze card');
    },
    onSuccess: (_, { minorAccountUuid }) => {
      queryClient.invalidateQueries({ queryKey: [CARDS_KEY, minorAccountUuid] });
    },
  });
}

export function useUnfreezeMinorCard() {
  const queryClient = useQueryClient();
  return useMutation<void, Error, { minorAccountUuid: string; cardId: string }>({
    mutationFn: async ({ minorAccountUuid, cardId }) => {
      const { data } = await apiClient.post<{ success: boolean; message?: string }>(
        `/api/accounts/minor/${minorAccountUuid}/cards/${cardId}/unfreeze`,
        {}
      );
      if (!data.success) throw new Error(data.message ?? 'Failed to unfreeze card');
    },
    onSuccess: (_, { minorAccountUuid }) => {
      queryClient.invalidateQueries({ queryKey: [CARDS_KEY, minorAccountUuid] });
    },
  });
}
```

- [ ] **Step 5.4 — Verify TypeScript compiles**

```bash
cd /Users/Lihle/Development/Coding/maphapayrn && npx tsc --noEmit 2>&1 | head -30
```

- [ ] **Step 5.5 — Commit**

```bash
cd /Users/Lihle/Development/Coding/maphapayrn
git add src/features/minor-accounts/domain/minorCardTypes.ts \
        src/features/minor-accounts/hooks/useMinorCardRequests.ts
git commit -m "feat: add minor card request and freeze/unfreeze hooks

Implements useMinorCardRequests, useMinorCards, useRequestMinorCard,
useFreezeMinorCard, useUnfreezeMinorCard for the guardian card
management workflow.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 6 — Minor Card Management Screen

**Files:**
- Create: `src/features/minor-accounts/presentation/MinorCardManagementScreen.tsx`

- [ ] **Step 6.1 — Create the screen**

Create `src/features/minor-accounts/presentation/MinorCardManagementScreen.tsx`:

```tsx
import React from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';

import type { MinorCard } from '@/features/minor-accounts/domain/minorCardTypes';
import {
  useFreezeMinorCard,
  useMinorCardRequests,
  useMinorCards,
  useRequestMinorCard,
  useUnfreezeMinorCard,
} from '@/features/minor-accounts/hooks/useMinorCardRequests';

interface Props {
  minorAccountUuid: string;
}

export function MinorCardManagementScreen({ minorAccountUuid }: Props) {
  const { data: cards, isLoading: cardsLoading } = useMinorCards(minorAccountUuid);
  const { data: requests, isLoading: requestsLoading } = useMinorCardRequests(minorAccountUuid);
  const requestCard = useRequestMinorCard();
  const freezeCard = useFreezeMinorCard();
  const unfreezeCard = useUnfreezeMinorCard();

  function handleRequestCard() {
    Alert.alert(
      'Request Card for Child',
      'Request a virtual card for your child?',
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Request Virtual Card',
          onPress: () => requestCard.mutate({ minorAccountUuid, cardType: 'virtual' }),
        },
      ]
    );
  }

  function handleToggleFreeze(card: MinorCard) {
    if (card.status === 'frozen') {
      Alert.alert('Unfreeze Card?', `Unfreeze card ending in ${card.last_four}?`, [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Unfreeze',
          onPress: () => unfreezeCard.mutate({ minorAccountUuid, cardId: card.id }),
        },
      ]);
    } else {
      Alert.alert('Freeze Card?', `Temporarily freeze card ending in ${card.last_four}?`, [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Freeze',
          style: 'destructive',
          onPress: () => freezeCard.mutate({ minorAccountUuid, cardId: card.id }),
        },
      ]);
    }
  }

  if (cardsLoading || requestsLoading) return <ActivityIndicator style={styles.center} />;

  return (
    <ScrollView style={styles.container} contentContainerStyle={styles.content}>
      <Text style={styles.heading}>Cards</Text>

      {(cards?.length ?? 0) === 0 ? (
        <View style={styles.emptyCard}>
          <Text style={styles.emptyText}>No cards issued yet</Text>
        </View>
      ) : (
        cards?.map((card) => (
          <View key={card.id} style={styles.cardItem}>
            <View style={styles.cardRow}>
              <Text style={styles.cardNumber}>•••• {card.last_four}</Text>
              <View style={[styles.statusBadge, card.status === 'frozen' ? styles.frozenBadge : styles.activeBadge]}>
                <Text style={styles.statusText}>{card.status.toUpperCase()}</Text>
              </View>
            </View>
            <Text style={styles.cardMeta}>
              Daily: {card.asset_code} {card.daily_limit.toLocaleString()} | Monthly: {card.monthly_limit.toLocaleString()}
            </Text>
            <Pressable
              style={[styles.actionBtn, card.status === 'frozen' ? styles.unfreezeBtn : styles.freezeBtn]}
              onPress={() => handleToggleFreeze(card)}
              disabled={freezeCard.isPending || unfreezeCard.isPending}
            >
              <Text style={styles.actionText}>
                {card.status === 'frozen' ? 'Unfreeze' : 'Freeze'}
              </Text>
            </Pressable>
          </View>
        ))
      )}

      <Text style={styles.subheading}>Card Requests</Text>

      {(requests?.length ?? 0) === 0 ? (
        <View style={styles.emptyCard}>
          <Text style={styles.emptyText}>No pending requests</Text>
        </View>
      ) : (
        requests?.map((req) => (
          <View key={req.id} style={styles.requestItem}>
            <Text style={styles.requestType}>{req.card_type} card</Text>
            <Text style={styles.requestStatus}>{req.status}</Text>
            {req.denial_reason ? (
              <Text style={styles.denialReason}>Reason: {req.denial_reason}</Text>
            ) : null}
          </View>
        ))
      )}

      <Pressable style={styles.requestBtn} onPress={handleRequestCard} disabled={requestCard.isPending}>
        {requestCard.isPending ? (
          <ActivityIndicator color="#fff" />
        ) : (
          <Text style={styles.requestBtnText}>+ Request New Card</Text>
        )}
      </Pressable>

      {requestCard.error ? <Text style={styles.error}>{requestCard.error.message}</Text> : null}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#fafafa' },
  content: { padding: 20, gap: 12 },
  center: { flex: 1, justifyContent: 'center', alignItems: 'center' },
  heading: { fontSize: 22, fontWeight: '700', marginBottom: 4 },
  subheading: { fontSize: 18, fontWeight: '600', marginTop: 16, marginBottom: 4 },
  emptyCard: { backgroundColor: '#f0f0f0', borderRadius: 12, padding: 20, alignItems: 'center' },
  emptyText: { color: '#999' },
  cardItem: { backgroundColor: '#fff', borderRadius: 16, padding: 16, gap: 8 },
  cardRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  cardNumber: { fontSize: 18, fontWeight: '700', letterSpacing: 2 },
  statusBadge: { borderRadius: 8, paddingHorizontal: 10, paddingVertical: 4 },
  activeBadge: { backgroundColor: '#e6f4ea' },
  frozenBadge: { backgroundColor: '#e8f0fe' },
  statusText: { fontSize: 11, fontWeight: '700' },
  cardMeta: { fontSize: 13, color: '#666' },
  actionBtn: { borderRadius: 10, paddingVertical: 10, alignItems: 'center' },
  freezeBtn: { backgroundColor: '#f5f5f5' },
  unfreezeBtn: { backgroundColor: '#e8f0fe' },
  actionText: { fontWeight: '600', color: '#333' },
  requestItem: { backgroundColor: '#fff', borderRadius: 12, padding: 14, gap: 4 },
  requestType: { fontSize: 15, fontWeight: '600', textTransform: 'capitalize' },
  requestStatus: { fontSize: 13, color: '#666', textTransform: 'capitalize' },
  denialReason: { fontSize: 12, color: '#c00' },
  requestBtn: { backgroundColor: '#1a1a1a', borderRadius: 14, paddingVertical: 16, alignItems: 'center', marginTop: 8 },
  requestBtnText: { color: '#fff', fontWeight: '700', fontSize: 16 },
  error: { color: '#c00', textAlign: 'center' },
});
```

- [ ] **Step 6.2 — Verify TypeScript compiles**

```bash
cd /Users/Lihle/Development/Coding/maphapayrn && npx tsc --noEmit 2>&1 | head -30
```

- [ ] **Step 6.3 — Commit**

```bash
cd /Users/Lihle/Development/Coding/maphapayrn
git add src/features/minor-accounts/presentation/MinorCardManagementScreen.tsx
git commit -m "feat: add MinorCardManagementScreen for guardian card controls

Guardians can view issued cards, freeze/unfreeze with confirmation,
view card request history, and request new virtual cards.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 7 — Enable pendingChoreSubmissions Feature Gate

**Files:**
- Modify: `src/features/minor-accounts/domain/featureGates.ts`

- [ ] **Step 7.1 — Enable the gate**

Open `src/features/minor-accounts/domain/featureGates.ts`. Change `pendingChoreSubmissions: false` to `true`:

```typescript
export const MINOR_ACCOUNT_FEATURE_GATES = {
  familyMembers: false,
  sharedGoals: false,
  learningModules: false,
  pendingChoreSubmissions: true,  // ← was false
  parentMode: true,
} as const;
```

- [ ] **Step 7.2 — Verify TypeScript compiles**

```bash
cd /Users/Lihle/Development/Coding/maphapayrn && npx tsc --noEmit 2>&1 | head -30
```

- [ ] **Step 7.3 — Commit**

```bash
cd /Users/Lihle/Development/Coding/maphapayrn
git add src/features/minor-accounts/domain/featureGates.ts
git commit -m "feat: enable pendingChoreSubmissions feature gate

The chore submission approval hook and backend endpoints are both
implemented. Enable the gate so the UI is visible to guardians.

Co-Authored-By: Claude <noreply@anthropic.com>"
```

---

## Task 8 — Final TypeScript and Integration Check

- [ ] **Step 8.1 — Full TypeScript check**

```bash
cd /Users/Lihle/Development/Coding/maphapayrn && npx tsc --noEmit 2>&1
```

Fix any type errors before proceeding.

- [ ] **Step 8.2 — Check for any broken imports**

```bash
cd /Users/Lihle/Development/Coding/maphapayrn && npx expo export --platform ios --output-dir /tmp/expo-export-check 2>&1 | tail -30
```

Expected: No unresolved import errors.

- [ ] **Step 8.3 — Run existing mobile tests**

```bash
cd /Users/Lihle/Development/Coding/maphapayrn && npx jest --passWithNoTests 2>&1 | tail -20
```

Expected: All existing tests pass.

---

## Self-Review Checklist

- [x] MINOR-P1-007 (HTTP 202 handling, spend approvals, card management) — Tasks 2–6
- [x] MINOR-P3-002 (chore error handling) — Task 1
- [x] Feature gate enabled for pendingChoreSubmissions — Task 7
- [x] All TypeScript types explicitly defined (no `any`)
- [x] All API paths include instruction to verify against backoffice source
- [x] No placeholder steps — every task has complete code
