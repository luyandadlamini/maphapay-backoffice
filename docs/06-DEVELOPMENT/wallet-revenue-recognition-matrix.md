# Wallet revenue recognition matrix (workshop)

> **Audience:** Finance, product, and engineering.  
> **Purpose:** Align each **wallet revenue stream** with evidence in the admin, recognition status, and the eventual data source for Phase B mart work (see [ADR-006](../ADR/ADR-006-wallet-revenue-mart.md)).

This is a **living** document: update cells when finance signs off rules or when new streams ship.

## Legend

| Status        | Meaning |
|---------------|---------|
| **Pending**   | No signed recognition rule; admin shows mapping-pending / blocked copy only. |
| **Directional** | Heuristic or partial coverage; not statutory reporting. |
| **Signed**    | Finance-approved rule implemented in code or mart. |

## Matrix

| Stream (`WalletRevenueStream`) | Admin evidence (default drill-down) | Recognition status | Primary data sources (future / current) | Notes |
|--------------------------------|-------------------------------------|--------------------|----------------------------------------|--------|
| `p2p_send` | Global transactions | Directional (admin v1) | `transaction_projections` (`type = transfer`, `status = completed` in range) | Admin **activity** only (REQ-REV-002); not fee revenue until finance mapping. |
| `cashout` | Global transactions | Directional (admin v1) | `transaction_projections` (`type = withdrawal`, `status = completed` in range) | Same multi-asset caveat as ADR-006; partner settlement not joined. |
| `request_money` | Payment intents | Pending | Payment intents, projections | Intent outcome vs ledger timing. |
| `merchant_pay` | Payment intents | Pending | Intents, merchant fees | QR / card rails may differ. |
| `merchant_qr` | Merchant partners | Pending | Partner agreements, intent fees | Partner-specific fee schedules. |
| `topup_momo` | MTN MoMo transactions | Pending | MoMo records, FX if any | External rail reconciliation. |
| `savings_pockets` | Pockets | Pending | Pocket balances, interest rules | Interest accrual policy TBD. |
| `group_savings` | Group savings | Pending | Group ledgers, fees | Pool vs member attribution. |
| `utilities` | Payment intents | Pending | Bill pay fees | Often thin margin; map by Biller. |
| `mcard` | Card issuance | Pending | Issuance fees, interchange | Issuer vs programme split. |
| `rewards` | Reward profiles | Pending | Redemption cost, breakage | Economics vs cash movement. |

## Workshop prompts (finance session)

1. For each stream, what is the **recognition point** (authorization, settlement, cash movement)?  
2. Which **fee keys** in Platform Settings apply, and are they exclusive or stacked?  
3. Reporting **currency** and FX policy for cross-asset dashboards.  
4. Which streams are **in scope** for Phase B mart day-one vs later?

## Engineering links

- Enum: `App\Domain\Analytics\WalletRevenueStream`  
- Evidence URLs: `App\Filament\Admin\Support\WalletRevenueStreamEvidence`  
- Targets: `App\Domain\Analytics\Models\RevenueTarget` + Filament `RevenueTargetResource`  
- Anomaly scan (per tenant): `php artisan revenue:scan-anomalies:for-tenants`  
