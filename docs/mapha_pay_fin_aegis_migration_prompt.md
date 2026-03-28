# MaphaPay → FinAegis Backend Migration (SZL Localisation)

## Role
You are a senior fintech architect and Laravel systems engineer specialising in core banking systems, digital wallets, and payment infrastructure.

---

## Core Principle (Very Important)

This migration MUST:

- **Lean heavily on the FinAegis backend architecture** (it is more professionally designed)
- **Adapt the mobile app to fit FinAegis — NOT the other way around**
- **Preserve ALL existing mobile app features and user flows**

> If API endpoints or structures differ, the **mobile app must be updated** to conform to FinAegis.

---

## Objective

Transform FinAegis into a **production-grade backend for MaphaPay** by:

1. Localising currency from **USD → SZL (Swazi Lilangeni, E)**
2. Expanding FinAegis to support MaphaPay features
3. Replacing the existing backend entirely
4. Maintaining full feature parity with the mobile app

---

## System Context

### Mobile App (Frontend)
Path:
```
/Users/Lihle/Development/Coding/maphapayrn
```

Key details:
- React Native + Expo
- TanStack Query (server state)
- Zustand (client state)
- Axios API client
- Bearer token authentication

---

### Existing Backend (To Replace)
Path:
```
/Users/Lihle/Development/Coding/maphapay-backend
```

- Laravel 12
- Sanctum auth
- MTN MoMo integration
- REST API (`/api/...`)

---

### New Backend Base
FinAegis Core Banking Prototype (Laravel)

---

## PHASE 1: FinAegis Deep Analysis

Understand FinAegis thoroughly:

- Core modules (accounts, ledger, transactions)
- Money representation (data types, precision)
- Transaction lifecycle
- Ledger design (double-entry or not)
- API structure and conventions

### Output:
- Architecture summary
- Strengths to preserve
- Limitations to address

---

## PHASE 2: Gap Analysis (Critical)

Compare:
- FinAegis vs current backend

Identify:
- Missing features in FinAegis:
  - MTN MoMo
  - Social money
  - Bill split
  - Wallet linking
  - Dashboard aggregation

### Output:
- Gap analysis table
- What to:
  - Reuse
  - Extend
  - Build from scratch

---

## PHASE 3: Currency Localisation (USD → SZL)

Find ALL USD dependencies:

### Backend:
- Database schemas
- Config files
- Business logic
- Seeders
- API responses

### Frontend:
- Currency formatting
- Hardcoded symbols

---

### Requirements:
- Use SZL (E)
- 2 decimal precision
- Proper rounding rules

### Recommendation:
Implement **multi-currency support** (SZL primary) for future scalability.

---

### Output:
- File-level change map
- Before → After mapping
- Risk analysis

---

## PHASE 4: Architecture Redesign

Extend FinAegis into a wallet-first system:

### Required systems:
- Wallet service
- Transaction engine
- Ledger integrity (double-entry preferred)
- Idempotent transactions
- Audit logging

### Extend to support:
- MTN MoMo
- Social payments
- Wallet linking
- Dashboard API

---

### Output:
- New architecture design
- Service boundaries
- Refactor plan

---

## PHASE 5: API Contract Alignment (Important Rule)

### Rule:
> The mobile app MUST adapt to FinAegis API structure

Do NOT redesign FinAegis APIs to match the app.

---

### Tasks:
- Map existing endpoints to FinAegis equivalents
- Define final API structure
- Update mobile app where necessary

---

### Output:
- Endpoint mapping (old → new)
- Required frontend changes

---

## PHASE 6: Migration Strategy

Design safe backend replacement:

### Include:
- Data migration (users, balances, transactions)
- Parallel run vs hard switch
- Rollback strategy

---

### Output:
- Step-by-step migration plan
- Risk mitigation

---

## PHASE 7: Mobile App Changes

Since the app must conform to FinAegis:

### Identify:
- API changes
- Currency updates
- Data structure adjustments

---

### Output:
- File-level frontend updates
- UX considerations

---

## PHASE 8: Testing & Financial Integrity

Define tests for:

- Currency accuracy
- Transaction correctness
- Idempotency
- Edge cases (duplicates, rounding, large values)

---

## CRITICAL WORKING RULE

### ALWAYS consult documentation before making changes

- FinAegis documentation
- Laravel documentation
- Existing backend docs

> Do NOT guess implementation details if documentation exists.
> Avoid preventable mistakes by verifying against docs first.

---

## DEVELOPMENT STRATEGY (IMPORTANT)

You will:

- Create a **duplicate of the mobile app repository**
- Make all breaking API changes there
- Keep the original app untouched as a fallback

---

## FINAL DELIVERABLE

Create a full report:

```
/docs/maphapay-backend-replacement-plan.md
```

---

### Must include:

- System analysis
- Gap analysis
- Currency migration plan
- Architecture redesign
- API mapping
- Migration strategy
- Risks and mitigations
- File-level implementation guidance

---

## EXECUTION MINDSET

- Treat this as a **real fintech system handling money**
- Be precise and exhaustive
- Prioritise correctness over speed
- Avoid shortcuts

---

## BONUS (OPTIONAL)

If possible, propose:

- Escrow system
- Multi-currency support
- Event-driven architecture
- Real-time updates (WebSockets/Pusher)

---

## FINAL NOTE

The goal is NOT to force-fit FinAegis into MaphaPay.

The goal is to:

> **Evolve FinAegis into a robust, production-grade backend that powers MaphaPay cleanly and professionally.**

