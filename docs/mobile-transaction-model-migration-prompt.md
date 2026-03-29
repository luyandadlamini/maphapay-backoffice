# Mobile Transaction Model Migration — Agent Prompt

## Working Directory

`maphapayrn` (React Native client)

## Context

This is the final open item from a two-pass security and migration audit of the MaphaPay → FinAegis
(`maphapay-backoffice`) migration. Items 1–4 (Critical/High) were fixed in the backoffice repo:

1. MTN callback replay protection — done (mtn_callback_log migration + QueryException guard)
2. PIN reset grant flow — done (verify-reset-code now issues a cache-backed grant; reset-pin consumes it)
3. MigrateLegacyBalances — done (bcmath parity checks, durable run logs, --force flag)
4. MigrateLegacySocialGraph SZL hardcoding — done (reads $row->currency, skips missing)

This session addresses item 5:

> The transaction list and detail layers are still carrying a mix of old and new assumptions.
> Some detail fields are synthesized or defaulted rather than being sourced from a canonical
> detail payload.

## Backend Canonical Transaction Contract

`maphapay-backoffice` is the **single source of truth**. Field names must not be aliased or
adapted on the backend. The mobile adapts to the backend.

### Canonical transaction list fields

| Field | Type | Notes |
|---|---|---|
| `id` | string | UUID |
| `reference` | string | human-readable reference |
| `description` | string | |
| `amount` | string | major-unit decimal, e.g. `"10.50"` |
| `type` | string | `deposit` \| `withdrawal` \| `transfer` |
| `subtype` | string | `send_money` \| `request_money` \| `mtn_collection` \| `mtn_disbursement` \| etc. |
| `asset_code` | string | e.g. `SZL` |
| `created_at` | ISO8601 string | |

### Canonical filter params

- `?type=deposit` / `?type=withdrawal` / `?type=transfer` — **not** `plus` / `minus`
- `?subtype=send_money` etc.
- `?search=`

### Previously broken assumptions in the mobile

From the first-pass audit, the `all-transactions.tsx` screen was sending `plus`/`minus` instead
of `deposit`/`withdrawal`. That was fixed in the earlier pass. The remaining issue is deeper in
the data layer.

## Files to Audit and Fix

These four files still carry a mix of old (legacy backend) and new (backoffice) field assumptions:

1. `src/features/home/data/homeDataSource.ts`
   - Check: does it map transaction list items through a canonical model, or does it still
     reference legacy fields (`trx_type`, `remark`, `trx`, `details`, `remarks`)?
   - Goal: all fields consumed from the canonical contract above.

2. `src/features/wallet/data/walletDataSource.ts`
   - Check: same as above. Does the wallet data layer apply any legacy field aliasing?
   - Goal: single canonical mapping, no synthesized or defaulted fields.

3. `src/features/wallet/hooks/useTransactionDetail.ts`
   - Check: does the detail hook synthesize fields (e.g. build `description` from multiple
     fields because the backend didn't return it, or default `type` when missing)?
   - Goal: detail fields sourced directly from the backend payload without synthesis.

4. `src/features/wallet/hooks/useTransactions.ts`
   - Check: does the list hook apply transformations that assume legacy field names or shapes?
   - Goal: transform once through a single canonical mapping layer; no per-screen remapping.

## Method

1. Read all four files in full before touching anything.
2. Identify every place a legacy field name is referenced:
   - `trx_type`, `remark`, `trx`, `details`, `remarks` → these are banned per the API contract.
   - Any field being synthesized/defaulted that should come directly from the backend.
3. Define (or locate) one canonical `Transaction` TypeScript type aligned to the contract above.
   If one already exists, consolidate to it. If not, create it in a shared types file.
4. Update all four files to consume only the canonical type.
5. Run `npx tsc --noEmit` to confirm TypeScript passes after changes.
6. Do NOT change any backend files. Do NOT add legacy aliases to the backend.

## What NOT to do

- Do not add `trx_type`, `remark`, `trx`, `details`, or `remarks` anywhere — these are legacy
  aliases explicitly banned by the API contract.
- Do not default `type` or `subtype` on the client when missing — surface the absence as an
  error or unknown state so bugs are visible.
- Do not create per-screen mapping layers. One shared type, one canonical mapping per data source.
- Do not touch `maphapay-backoffice`.

## Definition of Done

1. All four files reference only canonical field names from the contract above.
2. No synthesized or defaulted fields remain.
3. `npx tsc --noEmit` passes with zero errors.
4. The canonical `Transaction` type (or equivalent) is in one shared location and reused across
   home, wallet list, and wallet detail.
5. Changes committed to a feature branch in `maphapayrn`.
