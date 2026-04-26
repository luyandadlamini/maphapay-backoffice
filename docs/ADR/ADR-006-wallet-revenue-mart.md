# ADR-006: Wallet revenue mart (finance facts) — scope and deferral

## Status

Accepted

## Context

Admin **Revenue & Performance** surfaces (REQ-DASH-001, REQ-REV-001–004, REQ-DATA-010, REQ-PERF-001) need **repeatable, bounded-cost** aggregates: stream mix, period comparisons, and alignment with **targets** (`revenue_targets`) and **settings-backed fees**.

Today, operational reporting already touches **`transaction_projections`** (tenant-scoped, event-sourced projection). Examples include batch regulatory summaries that aggregate counts and raw `amount` sums with explicit notes that **totals are not additive across different `asset_code` values** when treated as one currency.

That limitation is structural: wallet revenue questions are **fee- and product-surface-specific**, often require **normalisation to a reporting currency**, and may join metadata not present on every projection row. Querying `transaction_projections` directly from Filament widgets for arbitrary windows therefore risks:

- **Incorrect finance numbers** if multi-asset rows are summed naively.
- **Latency and load** on the hot projection table without narrow filters and caps.
- **Duplicated business rules** if every widget encodes its own definition of “revenue”.

## Decision

1. **Phase A (current):** Do **not** introduce new mart tables (`finance_facts_daily`, `revenue_events`, etc.) solely for the first admin revenue iteration. Prefer:
   - **Explicit, documented read paths** (existing models and small query objects).
   - **`Cache::remember` (or tagged cache where already standard)** around aggregate reads used by Filament widgets, with:
     - **Strict tenant context** (same conventions as other tenant models and scheduled commands).
     - **Explicit calendar bounds** (e.g. start/end of month in UTC, or documented tenant timezone if product later standardises on one).
     - **Hard `LIMIT` / capped windows** on any exploratory listing (REQ-PERF-001).
   - **No claim** that a single scalar “total revenue” derived from raw `TransactionProjection` sums across mixed `asset_code` is finance-grade without per-asset grouping or a fee ledger source.

2. **Phase B (optional backlog):** Add a **dedicated mart** only after product and finance sign off on **stream definitions**, **recognition rules**, and **reporting currency**. The mart is then the single place that encodes those rules.

## Mart design constraints (Phase B — specification, not implemented here)

When Phase B is approved, the mart implementation **must** document and implement:

| Topic | Requirement |
|--------|-------------|
| **Grain** | Default **one row per tenant × calendar date (UTC) × revenue stream / metric key**. Hourly grain is **out of scope** unless a separate REQ explicitly needs intraday charts; if added later, partition or separate table to avoid exploding row counts. |
| **Retention** | Configurable retention (e.g. **24 months** at daily grain), then optional roll-up to **monthly** aggregates for long-range dashboards. |
| **Backfill** | Cursor-based backfill from **canonical inputs** agreed with finance: either enriched facts from ledger/postings, fee tables, and/or classified projections — **not** blind replay of projection sums without classification. Idempotent **upsert** per natural key. |
| **Idempotency** | Natural key e.g. `(tenant_identifier, bucket_date, stream_code, metric_name)` (exact column names to match tenancy schema conventions). Replays of the same source event or job run **must not double-count**. |
| **Query patterns** | Filament widgets read **only** the mart (or thin views on it) for trend and comparison cards; indexes on `(tenant, bucket_date)` and `(tenant, stream_code, bucket_date)` as needed after query review. |

## Consequences

- **Positive:** Avoids premature schema and ETL complexity; keeps v1 honest about what raw projections can and cannot prove.
- **Negative:** Some widgets remain **“evidence / directional”** until Phase B; finance must treat multi-asset aggregates as **non-authoritative** unless implemented per ADR above.
- **Operational:** Scheduled jobs that read **tenant-persisted** tables (e.g. `revenue_targets`) must run **inside `tenancy()->initialize($tenant)`** for each landlord tenant (see `revenue:scan-anomalies:for-tenants`). The single-tenant artisan entrypoint `revenue:scan-anomalies` remains for diagnostics when the process is already bound to one tenant database.

## References

- `App\Domain\Account\Models\TransactionProjection` — projection shape and `UsesTenantConnection`.
- `App\Domain\Account\Workflows\BatchProcessingActivity` — example of projection aggregates with **volume caveats** across assets.
- Wallet revenue admin plan: `docs/superpowers/plans/2026-04-24-maphapay-wallet-revenue-admin-implementation-plan.md` (Task 13).
- Workshop matrix (stream × evidence × recognition): `docs/06-DEVELOPMENT/wallet-revenue-recognition-matrix.md`.

## Commands (Phase A)

| Command | When to use |
|---------|-------------|
| `php artisan revenue:scan-anomalies:for-tenants` | **Production schedule** — iterates landlord tenants, initializes tenancy per tenant, runs read-only checks. Options: `--notify`, `--tenant=` (single tenant). |
| `php artisan revenue:scan-anomalies` | **Diagnostics / tests** — current default DB context only; use when tenancy is already initialized or in single-database dev. |
