# Hybrid Finance Scope Architecture

## Summary

MaphaPay should use a hybrid finance ownership model:

- Personal finance is user-scoped.
- Organization finance is team/tenant-scoped.

This approach fits the product direction better than forcing all finance behavior through tenant context. It supports the current consumer wallet experience while creating a clean path for merchants, businesses, and organizations.

## Why Hybrid Is The Right Approach

MaphaPay is not only a consumer wallet. It is evolving into a broader money platform that will serve:

- individual users
- merchants
- businesses
- organizations

Those groups do not operate with the same data ownership model.

Personal users think in terms of:

- my wallet
- my savings
- my budget
- my transaction history
- money I sent or received

Businesses and organizations think in terms of:

- our balances
- our approvals
- our transaction reporting
- our budgets
- our merchant settlement
- our staff roles and permissions

Because of that, one universal scoping rule is the wrong design. Personal finance should not depend on `currentTeam`, while organization finance should explicitly require team/tenant context.

## Core Principle

Every finance route and finance write path must be one of the following:

1. explicitly personal
2. explicitly organization
3. explicitly dual-mode with a required context selector

Finance scope must never be determined accidentally by middleware order or by whichever context happens to be present.

## Scope Model

### Personal Finance

- owner: `user`
- primary key of ownership: `user_uuid`
- account access rule: authenticated user can only access their own accounts and derived projections
- should not depend on `currentTeam`

Use personal scope for:

- wallet dashboard
- P2P send money
- request money
- transaction history
- savings pockets
- personal budgeting
- expense breakdown
- smart finance insights
- rewards

### Organization Finance

- owner: `team` / tenant
- primary key of ownership: `team_id` with active tenant context
- account access rule: authenticated user must belong to the active team and satisfy role/policy checks
- must fail closed when tenant context is missing or invalid

Use organization scope for:

- merchant wallets
- business balances
- organization budgets
- approval workflows
- maker-checker controls
- merchant settlement
- staff expense management
- business reporting
- treasury or shared operational finance

## Route Strategy

### Personal Routes

The current consumer/mobile compatibility finance routes should remain personal by default.

Examples:

- `/api/dashboard`
- `/api/transactions`
- `/api/send-money/*`
- `/api/request-money/*`
- `/api/pockets/*`
- `/api/budget*`

These routes should read by authenticated user ownership and not require team/tenant middleware.

### Organization Routes

Create a separate route surface for business and merchant finance.

Recommended pattern:

- `/api/org/dashboard`
- `/api/org/transactions`
- `/api/org/wallets`
- `/api/org/budgets`
- `/api/org/approvals`
- `/api/org/settlements`
- `/api/org/merchant/payments`

These routes should:

- require tenant middleware
- require explicit team membership
- fail closed when context is missing

## Ownership Model

Finance records should always have a clear ownership meaning, even if the physical schema evolves in stages.

Recommended logical ownership model:

- personal accounts belong to a `user`
- organization accounts belong to a `team`

Over time, this can be made explicit in finance tables and projections through fields such as:

- `scope_type = personal | organization`
- `scope_owner_id`
- `owner_user_uuid`
- `owner_team_id`

The exact schema shape can vary, but the meaning must be unambiguous.

## Read Model Rules

### Personal Read Models

Personal read models should:

- collect account UUIDs owned by the authenticated user
- query balances, transactions, projections, and analytics from those accounts
- avoid any dependency on `currentTeam`

### Organization Read Models

Organization read models should:

- initialize tenant context from the active team
- query only tenant-owned data
- enforce team membership and role permissions

### Shared Projection Infrastructure

Projection infrastructure can still be shared, but every projected finance record should carry enough metadata to identify its scope and classification.

Recommended projection metadata:

- `scope_type`
- `scope_owner_id`
- `source_domain`
- `analytics_bucket`
- `category_slug`

## Authorization Rules

### Personal Authorization

- user can access only data derived from their own accounts
- ownership is the primary authorization rule

### Organization Authorization

- user must belong to the team
- user must have sufficient role/permission for the requested action
- sensitive flows should use policy checks beyond mere membership

This creates a clean distinction:

- personal finance uses ownership-based authorization
- organization finance uses membership-plus-role authorization

## Client Strategy

### Consumer Mobile App

The consumer app should continue using personal routes only.

It should not need team context for:

- wallet
- send money
- savings
- budgeting
- history

### Future Merchant Or Business Client

A merchant or business-facing client should use organization routes and explicit tenant context.

### If One App Supports Both Modes

If the mobile app eventually supports both personal and business modes, the UI must make the context explicit.

Recommended mode switch:

- Personal
- Business

When the mode changes:

- route families should change
- query keys should change
- caches should separate
- analytics should separate

The app should not silently merge personal and organization finance by default.

## Implementation Rules

1. Personal finance routes must stay user-scoped unless there is a deliberate product reason otherwise.
2. Organization finance routes must be tenant-scoped and fail closed.
3. Finance writes must stamp scope information explicitly.
4. Read models must not infer scope from incidental middleware state.
5. Cross-scope access must be impossible by construction, not just by UI convention.

## Recommended Rollout

### Phase 1: Formalize Current Scope

- declare existing mobile compat finance routes as personal
- document that they are user-owned, not team-owned

### Phase 2: Stamp Scope Metadata

- add scope metadata to newly written projections and finance records
- ensure every new record clearly indicates personal vs organization ownership

### Phase 3: Split Service Boundaries

Create separate service layers such as:

- `PersonalFinance*`
- `OrganizationFinance*`

This prevents future business logic from leaking into personal flows.

### Phase 4: Introduce Organization APIs

- add `/api/org/*` route groups
- apply tenant middleware there
- add role and policy enforcement

### Phase 5: Add Isolation Tests

Minimum isolation tests:

- personal user cannot see organization data
- organization member cannot see another organization’s data
- personal finance still works when the user has no active team
- org finance fails clearly when tenant context is missing

## Benefits

This architecture gives MaphaPay:

- a simpler and more reliable consumer finance experience
- a strong foundation for merchant and business features
- better long-term maintainability
- clearer authorization boundaries
- lower risk of finance bugs caused by ambiguous context

## Recommended Default Decision

MaphaPay should adopt the hybrid model as the platform standard:

- personal wallet and personal money management remain user-scoped
- merchant, business, and organization finance are tenant-scoped

That is the most practical, scalable, and product-aligned approach for MaphaPay.
