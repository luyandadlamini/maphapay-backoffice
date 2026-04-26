# Phase 12: Virtual Card Support for Minor Accounts (Rise Tier 13+) - Design & Implementation

## Context

You are creating the spec and implementation plan for Phase 12 of the Minor Accounts feature (ages 6-17), according to the original plan at `docs/superpowers/plans/2026-04-16-minor-accounts-feature-ages-6-17.md` (lines 253-260).

**What was completed in Phase 11:**
- Merchant bonus system for QR payments at partnered merchants
- MinorMerchantBonusService, MinorMerchantBonusTransaction
- API endpoints for merchant discovery with bonus metadata
- Filament admin for merchant partner configuration

**Phase 12 Scope:** Virtual card support for Rise tier (ages 13+)

## Your Task

### Step 1: Review Existing Codebase

Before anything else, explore the existing CardIssuance domain:

```
app/Domain/CardIssuance/
├── Models/Card.php
├── Services/CardProvisioningService.php
├── Services/CardTransactionSyncService.php
├── Adapters/DemoCardIssuerAdapter.php
├── Adapters/RainCardIssuerAdapter.php
├── ValueObjects/VirtualCard.php
├── Contracts/CardIssuerInterface.php
└── Enums/ (CardStatus, CardNetwork, etc)
```

Check:
1. How cards are currently provisioned
2. What limits and controls exist
3. How the card issuer adapters work
4. Any existing API endpoints

Also check the original plan document for:
- Lines 253-260: Virtual card requirements
- Any other references to card features

### Step 2: Write the Spec

Create a new spec document at:
`docs/superpowers/specs/2026-04-24-minor-accounts-phase12-virtual-card-spec.md`

The spec MUST include:

1. **Executive Summary** - What we're building and why
2. **Scope** - What's in/out of scope
3. **Preconditions** - What Phase 11 provides that we build on
4. **Data Model Changes** - New fields/tables
5. **API Contract** - Exact endpoints with request/response
6. **Filament Admin** - Management for parents/admins
7. **Failure Modes** - Error handling, edge cases
8. **Verification Strategy** - How to test

Key requirements from the original plan:
- Virtual card for Rise tier (ages 13+) only
- Parent approval required for card issuance
- Card spending limits mirror account-level controls (daily/monthly limits)
- Card can be frozen independently of account
- Merchant category blocks enforced at card level
- Works with Apple Pay / Google Pay provisioning

### Step 3: Write the Implementation Plan

After the spec is created, write a detailed implementation plan at:
`docs/superpowers/plans/2026-04-24-minor-accounts-phase12-virtual-card-plan.md`

The plan MUST follow the format from `writing-plans` skill:
- Use checkbox (`- [ ]`) syntax for tasks
- Include actual code in each step (not placeholders)
- Reference exact file paths
- Use TDD approach

### Key Design Decisions Already Made (do not change):

1. **Extend existing CardIssuance domain** - Don't create separate domain
2. **Parent approval required** - Parent must approve before card is created
3. **Dual approval flow** - Either parent triggers it OR child requests + parent approves
4. **Spend limit = MIN(card_limit, account_limit)** - Most restrictive wins

## Deliverables

1. **Spec document** - committed to git
2. **Implementation plan** - saved and ready for execution

## Important Notes

- Phase 11 already created: MerchantPartner, MinorMerchantBonusService, MinorMerchantBonusTransaction
- Minor accounts use account_type = 'minor' in accounts table
- Minor account access via MinorAccountAccessService
- Use existing patterns from Phase 11 (follow same structure)

## Output FORMAT

When complete, provide:
- File paths of spec and plan created
- Summary of what was designed
- Key implementation tasks identified