# Transactions auto-post to friend chat — implementation plan

**Status:** proposed
**Author:** Claude (with @luyandadlamini)
**Date:** 2026-05-15

## Goal

Any money movement between two confirmed friends — whether initiated from chat, the send-money screen, request-money, or via an MTN MoMo IPN — appears in their shared direct chat thread as a system-generated bubble. If they are not friends, the transaction still processes but does not touch chat.

## Architecture

```
              ┌────────────────────────────────────────────┐
   chat ──▶   │  ThreadPaymentController / ThreadRequest   │ (existing — keep)
              └────────────────────────────────────────────┘
                              │
                              ▼ Message saved with idempotency_key = tx:{id} / mr:{id}:created
                              │
send-money ───▶ AuthorizedTransaction.status: pending→completed
                              │
MTN IPN    ───▶ AuthorizedTransaction.status: pending→completed
                              │
request-money ──▶ MoneyRequest.created / status changes
                              │
                              ▼
              ┌────────────────────────────────────────────┐
              │ AuthorizedTransactionChatSyncObserver      │  ── DB::afterCommit ──▶  SyncTransactionToChatService
              │ MoneyRequestChatSyncObserver               │
              └────────────────────────────────────────────┘
                              │
                              ▼
              ┌────────────────────────────────────────────┐
              │ SyncTransactionToChatService               │
              │  1. friendship check (single-direction)    │
              │  2. ThreadResolver::findOrCreateDirect()   │
              │  3. dedup by idempotency_key (unique idx)  │
              │  4. Message::create(...) or payload mutate │
              │  5. broadcast ChatMessageSent              │
              └────────────────────────────────────────────┘
```

## Idempotency keys (deterministic)

| Event                             | Key                                  |
|-----------------------------------|--------------------------------------|
| Send money completed              | `tx:{authorized_transaction_id}`     |
| Money request created (any path)  | `mr:{money_request_id}:created`      |
| Money request declined            | `mr:{money_request_id}:declined`     |
| Money request fulfilled           | *(no new key — in-place payload mutation on the `mr:{id}:created` row)* |
| Thread auto-created preamble      | `thr:{thread_id}:created-from-tx`    |

The existing UNIQUE index on `messages.idempotency_key` enforces dedup at the DB level. Migration extends the column to `varchar(64)` to fit these keys.

## Message types & payload shapes

All reuse the existing enum (no schema change to the enum itself).

```json
// type=payment (existing shape, slightly extended)
{
  "amount": 250.00,
  "asset_code": "SZL",
  "note": "Lunch",
  "recipientUserId": "42",
  "linkedRequestId": null,
  "authorizedTransactionId": "uuid...",   // NEW — set by observer path
  "source": "external"                     // NEW — "chat" | "external"
}

// type=request (existing)
{ "moneyRequestId": "...", "amount": 250.00, "note": "...", "status": "pending|paid|declined", "targetUserId": "..." }

// type=system (new payload kind for declines)
{ "kind": "money_request_declined", "moneyRequestId": "...", "amount": 250.00 }
```

The `source` field lets the chat client tell the user "Sent from your wallet" vs an in-chat send.

## Files

### New

1. `database/migrations/2026_05_15_000001_extend_messages_idempotency_key_length.php` — `varchar(36)` → `varchar(64)`
2. `app/Domain/SocialMoney/Services/ThreadResolver.php` — `findOrCreateDirect(int $userA, int $userB): Thread`
3. `app/Domain/SocialMoney/Services/SyncTransactionToChatService.php` — single entry point: `postPaymentMessage`, `postRequestMessage`, `markRequestPaid`, `postRequestDeclined`
4. `app/Domain/SocialMoney/Observers/AuthorizedTransactionChatSyncObserver.php`
5. `app/Domain/SocialMoney/Observers/MoneyRequestChatSyncObserver.php`
6. `tests/Feature/Domain/SocialMoney/SyncTransactionToChatTest.php`

### Modified

- `app/Providers/AppServiceProvider.php` — register the two observers
- `app/Http/Controllers/Api/SocialMoney/ThreadPaymentController.php` — set `idempotency_key = "tx:{authorizedTransactionId}"` on the in-chat payment message so the observer's later dedup check finds it (only when the controller knows the authorized-txn id; otherwise leaves null)
- `app/Http/Controllers/Api/SocialMoney/ThreadRequestController.php` — set `idempotency_key = "mr:{moneyRequestId}:created"` on the in-chat request message

## Friendship rule

Single-direction check against existing `friendships` table:

```php
DB::table('friendships')
  ->where('user_id', $actorId)
  ->where('friend_id', $counterpartyId)
  ->where('status', 'accepted')
  ->exists();
```

Per inventory, friendships are stored as both ordered pairs at acceptance time, so one direction is sufficient.

## Observer details

**AuthorizedTransactionChatSyncObserver::updated($txn)**
- Guard: `$txn->wasChanged('status') && $txn->status === 'completed'`
- Guard: `$txn->remark` ∈ {`send_money`, `request_money_received`}
- Extract counterparty:
  - `send_money`: `payload.recipient_user_id`
  - `request_money_received`: `payload.requester_user_id` (the requester gets credited; the *sender* in chat is the original recipient)
- `DB::afterCommit(fn() => $service->postPaymentMessage(...))`

**MoneyRequestChatSyncObserver::created($req)** *and* **::updated($req)**
- `created`: `DB::afterCommit(fn() => $service->postRequestMessage(...))` — dedup via `mr:{id}:created`
- `updated` and `wasChanged('status')`:
  - `fulfilled` → `$service->markRequestPaid($req)` (mutate payload of existing `mr:{id}:created` message)
  - `rejected` → `$service->postRequestDeclined($req)` — idempotency `mr:{id}:declined`

## Tests (initial)

`SyncTransactionToChatTest`:

1. ✅ Friends, send money outside chat → payment message appears in their direct thread, both users get broadcast.
2. ✅ Not friends → transaction succeeds, no message created.
3. ✅ No direct thread exists → thread + 2 participants + system preamble + payment message all created.
4. ✅ Same authorized-txn fires observer twice → only one chat message (DB unique constraint).
5. ✅ Money request created outside chat → request message appears in thread for both users.
6. ✅ Money request fulfilled → existing request bubble payload flips to `status: paid`.
7. ✅ Money request rejected → system decline message appears.
8. ✅ In-chat send (existing ThreadPaymentController flow) → only one message, not duplicated by observer.

## Migration deploy (Laravel Cloud)

```bash
php artisan migrate --path=database/migrations/2026_05_15_000001_extend_messages_idempotency_key_length.php --force
```

## Mobile-side follow-up (next session)

- Chat client already renders `payment` and `request` bubbles. Verify the new `source: "external"` field is shown subtly ("Sent from your wallet") on payment bubbles.
- New rendering for `type=system` + `payload.kind=money_request_declined` — a muted "Request declined" pill.
- Confirm `ChatMessageSent` listener invalidates the right TanStack Query keys so the new bubbles appear in real time without re-opening the thread.
- Optional: when send-money screen has selected a friend recipient, no longer make the duplicate optimistic chat message — let the observer be the source of truth.

## Out of scope

- Group threads: only `direct` threads are touched. P2P only.
- Cards transactions: `card_product` remark is excluded from the observer.
- Bill split payments: already have their own `bill_split` message type; not changed.
