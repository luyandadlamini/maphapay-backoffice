# MaphaPay Wallet — Admin Revenue & Performance (Technical / Product Spec)

**Document ID:** SPEC-WALLET-REV-2026-04-24  
**Status:** Draft for engineering + finance + compliance review  
**Applies to:** Laravel Filament admin panel (`app/Filament/Admin`), supporting services, tenant data  
**Primary references:** [docs/maphapay_pass1_inventory.md](../../maphapay_pass1_inventory.md) (mobile capability inventory), existing back office access in `app/Support/Backoffice/BackofficeWorkspaceAccess.php`

---

## 1. Purpose

Deliver a **wallet-aligned Revenue & Performance** experience in the MaphaPay back office that:

- Surfaces **fee revenue, volume, and margin signals** tied to **actual mobile product flows** (not FinAegis-only domains).
- Keeps **controls** (pricing parameters) **auditable**, **least-privilege**, and **consistent** with existing settings infrastructure.
- Provides **role-appropriate home and revenue dashboards** so each department lands on actionable data without noise.
- Reduces operational risk by **avoiding duplicate sources of truth**, **misclassified revenue**, and **unbounded expensive queries** on hot paths.

**Success is measured by:**

- Finance can answer: “What did we earn, from which wallet flows, over period T, in reporting currency?” with documented definitions.
- Support and compliance do **not** see treasury pricing or unreleased margin data unless explicitly granted.
- No dashboard poll or page load causes **unacceptable DB load** (defined in NFRs).
- Every change to fee parameters leaves an **immutable audit trail** compatible with internal control expectations.

---

## 2. Glossary

- **GMV / volume:** Customer money movement relevant to a product flow (not necessarily platform income).
- **Recognized fee revenue (platform):** Amounts the business treats as income under its accounting policy (finance-owned definition); may differ from cash movement timing.
- **COR (cost of revenue):** Direct costs attributable to delivering the flow (e.g. MoMo/issuer/bank charges, interchange pass-through). Must be labeled separately from “fees collected from users.”
- **Workspace:** Coarse authorization bucket used in back office (`platform_administration`, `finance`, `compliance`, `support`) — see `BackofficeWorkspaceAccess`.
- **Stream:** A **wallet product line** used for analytics grouping (enumerated in section 6).
- **Legacy shelf:** Filament navigation group at bottom for FinAegis-era or inactive modules; may be hidden by configuration.

---

## 3. In scope

1. **Persona-based home dashboard** widget composition using **existing** workspace and permission checks; defence in depth via Filament widget `canView()`.
2. **New navigation group:** `Revenue & Performance` with Filament pages for overview, streams, profitability (as data allows), unit economics placeholders, pricing/fees (reuse settings), targets/forecasts, and **evidence deep links** to existing resources.
3. **Legacy navigation shelf** at bottom + optional env/config to hide legacy items from navigation in production.
4. **Aggregate read path** for revenue metrics: prefer **existing** projections / batch outputs / reconciliation artifacts; add persisted daily facts **only** if justified by performance or grain (see REQ-DATA-010).
5. **Security, audit, observability** requirements in section 10–12.

---

## 4. Explicitly out of scope (v1)

- **AgentProtocol** revenue, navigation, metrics, or admin configuration surfaces for MaphaPay.
- **Exchange / DeFi / cross-chain / governance** as **default** revenue streams (may appear only under Legacy shelf; no default wallet revenue KPIs).
- **Replacing** general ledger, statutory reporting, or tax filing — this module is **management intelligence**, not statutory accounts unless finance later expands it.
- **Building a second fee configuration store** parallel to `Setting` + `SettingsService`.

---

## 5. Actors and concerns

- **Finance / Treasury:** Needs revenue, margin bridge, fee controls, reconciliation links, export where allowed.
- **Compliance:** Needs anomaly and volume visibility appropriate to AML program; **must not** have fee write access unless policy explicitly allows.
- **Support:** Needs operational health (failed rails, stuck payouts), user/transaction investigation entry points; **read-only** revenue tiles at most.
- **Platform / Super-admin:** Full configuration including legacy visibility toggle (if implemented).
- **Engineering:** Must enforce POLP, tenant isolation, safe queries, tests, and operational runbooks.

---

## 6. Wallet streams (analytics taxonomy)

Streams must map to mobile inventory rows in [docs/maphapay_pass1_inventory.md](../../maphapay_pass1_inventory.md). v1 enum (string codes, stable across releases):

- `p2p_send` — Send money  
- `request_money` — Request money / pay links (`PaymentIntent`)  
- `merchant_qr` — QR pay  
- `merchant_pay` — Pay merchant  
- `topup_momo` — Top-up (MoMo) / linked wallet funding where MoMo is involved  
- `cashout` — Cash-out / payout  
- `savings_pockets` — Pockets / goals (economics: fee vs float — finance classification)  
- `group_savings` — Group savings / stokvel  
- `utilities` — Utilities / airtime services  
- `mcard` — Virtual/physical cards (`CardIssuance`)  
- `rewards` — Shown as **cost / liability analytics**, not fee revenue, unless finance defines fee income  

Each stream definition in engineering docs must list:

- **Volume source of truth** (which tables/events).  
- **Fee revenue rule** (formula + settings keys or commercial contract reference).  
- **COR inputs** (who maintains them: finance vs integrations).  
- **Drill-down entry** (which Filament resource URL and which filters are supported).

---

## 7. Functional requirements

### REQ-NAV-001 — Revenue navigation group

The admin panel must register a `Revenue & Performance` navigation group ordered near core wallet operations (exact order: product decision; default proposal: after `Transactions` / near `Merchants & Orgs`).

### REQ-NAV-002 — Legacy shelf

A final navigation group (name configurable; default `Legacy & experimental`) must appear **last**. Resources deemed non-wallet-core must use this group. Optional: hide from navigation when `config('maphapay.show_legacy_admin_nav')` is `false` (default for MaphaPay production unless product sets otherwise).

### REQ-DASH-001 — Persona home dashboard

`Dashboard::getWidgets()` must return a widget list **derived from** the authenticated user’s workspace/permissions via **extended** `BackofficeWorkspaceAccess` and existing Gate/`can()` checks. **No parallel RBAC matrix.**

### REQ-DASH-002 — Widget defence in depth

Every widget registered for the admin panel must implement `canView(): bool` consistent with least privilege, even if also filtered by `getWidgets()`.

### REQ-REV-001 — Revenue overview page

A Filament page must show: time range filter, reporting currency (if multi-currency), KPI row for in-scope streams only, trend chart placeholders acceptable until mart exists, anomaly list with severity. **v1 access:** `finance` or `platform_administration` workspaces only; support must not rely on this page for ops (they use persona home dashboard per REQ-DASH-001). A future read-only expansion requires a separate REQ and threat review.

### REQ-REV-002 — Streams page

Tabbed or sectioned layout with **one card per stream** in section 6. Each card: volume, estimated fee revenue (if defined), link to evidence. Cards for streams without data definitions must show **explicit “definition pending finance”** state (not fake zeros).

### REQ-REV-003 — Profitability page

Shows margin bridge **only** when COR inputs exist; otherwise show blocked state with required finance inputs listed.

### REQ-REV-004 — Unit economics page

Shows CAC/LTV only when data contracts exist; otherwise show **non-deceptive** empty state (“not connected”) — **forbidden** to show demo numbers as real.

### REQ-FEE-001 — Pricing & fees

Fee parameters remain stored in `settings` via `SettingsService` keys already defined for wallet fees. The Revenue “Pricing & fees” UI must **reuse** validation and audit patterns from `Settings` / `AdminActionGovernance` (either embed same schema or deep-link with documented UX). **Forbidden:** duplicate validation rules in a second class without a shared single source of truth.

### REQ-TGT-001 — Targets

If no targets store exists, introduce a minimal tenant-scoped table for targets by month + stream + amount + currency; writes restricted to finance/platform workspaces; reads audited if mutated from UI.

### REQ-ALR-001 — Alerts

Anomaly detection v1 uses scheduled jobs + logs + Filament notifications; reuse existing mail/log channels. **Forbidden:** introduce separate third-party alerting stack in v1.

### REQ-DATA-010 — Aggregates discipline

Before adding `finance_facts_daily` (name illustrative): document why `TransactionProjection` / existing batch summaries / reconciliation exports are insufficient. Any mart must be tenant-isolated, idempotent ETL, and **versioned** metric definitions in code (enum + mapping class).

### REQ-SEC-001 — Least privilege

Pricing write, target write, and any “simulate pricing impact” action require `finance` or `platform_administration` workspace per extended `BackofficeWorkspaceAccess` rules (exact mapping in implementation plan).

### REQ-SEC-002 — Tenant isolation

All queries default to current tenant context; cross-tenant aggregation is **forbidden** unless a separate authorized “platform ops” mode exists today — if not, **do not invent** cross-tenant revenue views in v1.

### REQ-SEC-003 — Auditability

Any fee change must record: actor id, timestamp, old/new values, reason string (reuse governance reason capture where applicable), IP/user agent if already captured elsewhere — follow existing audit patterns.

### REQ-PERF-001 — Query safety

Dashboard widgets must not run unbounded JSON scans on each poll. Use: limits, date windows, covering indexes where new tables are added, caching (`cache()->remember` with short TTL) for heavy aggregates, or pre-aggregation jobs.

### REQ-OBS-001 — Observability

Slow query logging or APM tags for new revenue jobs/pages where available; structured log context must exclude full PII payloads.

---

## 8. Non-functional requirements

- **Correctness:** Numbers shown as “revenue” must match the documented definition for that tile; if mixed currencies, show conversion policy or restrict to single reporting currency for v1.
- **Robustness:** Degraded mode — if mart/job fails, UI shows stale badge + last successful timestamp; no silent fallback to random queries.
- **Simplicity:** Prefer fewer moving parts; no new microservice for v1.
- **Security:** POLP, CSRF (Filament defaults), no fee changes without confirmation + reason, rate-limit destructive simulations if implemented.
- **Maintainability:** Stream mapping and extraction rules live in **one** dedicated PHP class or config file per layer (query vs presentation), not scattered in widgets.

---

## 9. Threat model (admin)

- **Unauthorized fee change:** Mitigate with workspace checks + governance reason + audit + optional 2FA policy if org enables it later.
- **Data leak via support UI:** Mitigate with `canView` + page `canAccess` + omit sensitive tiles for support persona tests.
- **DoS via heavy analytics:** Mitigate with caching, job pre-aggregation, pagination, and poll intervals not below safe thresholds without load testing sign-off.
- **Misinterpretation by executives:** Mitigate with labels, tooltips linking to definitions, and “finance sign-off required” banners where definitions incomplete.

---

## 10. Acceptance criteria (summary)

- AC-001: User in support workspace **never** receives Pricing write UI; automated test proves 403 or hidden actions.
- AC-002: User in finance workspace can open Revenue overview and at minimum sees volume-oriented tiles with documented definitions or explicit pending states.
- AC-003: Changing a fee through the governed path produces an audit record and invalidates relevant caches.
- AC-004: Legacy resources do not appear above the Legacy group when hide flag is false (or appear only in Legacy when flag true — product chooses; document chosen behaviour in impl plan).
- AC-005: Dashboard home widget set differs measurably between at least two personas (support vs finance) in tests.

---

## 11. Business sign-off items (not engineering placeholders)

These are **decisions**, not TBD laziness:

1. **Revenue recognition matrix** per stream (what counts as revenue vs pass-through vs customer liability). Owner: Finance.  
2. **Reporting currency** and FX policy for mixed-currency tenants. Owner: Finance.  
3. **Exact Legacy resource list** moved to bottom group vs hidden entirely. Owner: Product + Engineering.  
4. **Whether CGO / basket / treasury widgets** remain on finance-only home or move to a Treasury-only landing. Owner: Product.

---

## 12. Traceability

Implementation tasks in `2026-04-24-maphapay-wallet-revenue-admin-implementation-plan.md` map to REQ IDs above. Changes to definitions require spec version bump.

---

## 13. Revision history

- **0.1 — 2026-04-24 — Engineering:** Initial consolidated spec from planning sessions.
