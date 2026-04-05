# PASS 1 — Inventory & Forensic Discovery Prompt

## Repo Paths (Use These As Source of Truth)
- MaphaPay Mobile App: `/Users/Lihle/Development/Coding/maphapayrn`
- New Back Office (FinAegis-based): `/Users/Lihle/Development/Coding/maphapay-backoffice`
- Old Backend: `/Users/Lihle/Development/Coding/maphapay-backend`

---

## ROLE
You are a principal fintech systems auditor and backend/UI investigator.

Your ONLY goal in this pass is to perform a COMPLETE INVENTORY of:
- capabilities
- code structures
- admin UI surfaces
- workflows
- domains
- routes
- permissions
- operational tools

DO NOT:
- propose solutions
- redesign anything
- speculate beyond evidence

---

## CORE OBJECTIVE
Build a **full factual inventory** of:
1. What the mobile app requires (product truth)
2. What the old backend enabled (baseline)
3. What the new back office actually provides (current state)

---

## NON-NEGOTIABLE RULES
- No recommendations yet
- No redesign yet
- No assumptions without code evidence
- Distinguish:
  - backend capability
  - API capability
  - UI/admin capability
  - workflow capability

---

## PHASE 1 — MOBILE APP REQUIREMENTS EXTRACTION

From `/Users/Lihle/Development/Coding/maphapayrn` extract:

### A. Features
- wallet
- transactions
- send money
- request money
- QR payments
- merchant payments
- linked wallets
- MTN MoMo flows
- rewards
- social money
- notifications
- KYC/profile
- savings/pockets
- utilities
- bill split
- cards (if any)

### B. For each feature define:
- user-visible state
- lifecycle
- backend dependency
- what admin MUST be able to:
  - view
  - edit
  - approve
  - reverse
  - retry
  - investigate

---

## PHASE 2 — OLD BACKEND INVENTORY

From `/Users/Lihle/Development/Coding/maphapay-backend` extract:

### A. Routes
- `/api`
- `/api/merchant`
- `/api/agent`
- `/admin`
- `/user`
- `/ipn`

### B. Capabilities
- auth
- wallet
- transactions
- send/request money
- MTN MoMo
- budget
- rewards
- social money
- linked wallets
- utilities
- airtime

### C. Admin Capabilities
Document EXACTLY:
- what admins could do
- what operators could see
- what actions existed

---

## PHASE 3 — NEW BACK OFFICE INVENTORY

From `/Users/Lihle/Development/Coding/maphapay-backoffice` extract:

### A. Filament Admin
- panels
- resources
- pages
- widgets
- navigation/sidebar
- actions
- relation managers

### B. Domains/Modules
List all domains enabled or present

### C. APIs
- REST
- GraphQL

### D. Operational Surfaces
Check if UI exists for:
- transactions
- users
- wallets
- compliance
- monitoring
- notifications
- merchants
- treasury
- fraud

### E. Workflows
Check for:
- approvals
- state transitions
- queues
- jobs
- events

### F. Permissions
- roles
- policies
- access control

---

## PHASE 4 — CAPABILITY CLASSIFICATION

For every capability classify as:
- FULLY IMPLEMENTED (UI + workflow)
- UI ONLY
- BACKEND ONLY
- PARTIAL
- NOT PRESENT

---

## PHASE 5 — BUILD INVENTORY TABLE

Create a table with columns:
- Capability
- Source (App / Old Backend / New Back Office)
- Exists in Old Backend (Y/N)
- Exists in New Backend Domain (Y/N)
- Exists in Admin UI (Y/N)
- Workflow Complete (Y/N)
- Notes

---

## REQUIRED OUTPUT FORMAT

1. Mobile App Capability Inventory
2. Old Backend Capability Inventory
3. New Back Office Capability Inventory
4. Admin UI Inventory (sidebar + pages)
5. Capability Classification Table
6. Observations (STRICTLY FACTUAL — no recommendations)

---

## FINAL RULE
If something is not proven in code → mark it:
- "Unverified"
