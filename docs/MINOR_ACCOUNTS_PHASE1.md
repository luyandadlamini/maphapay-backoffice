# MaphaPay Minor Accounts Phase 1

This document is the Phase 1 backend reference deliverable for the minor accounts rollout. It describes the shipped API surface, authorization model, permission rules, and operational follow-up for this phase.

## Overview

Phase 1 delivers the backend foundation for minor accounts in the MaphaPay backoffice. Minor accounts are stored in the existing tenant-scoped `accounts` table, while all guardianship relationships continue to use the central `account_memberships` table.

Core design choices:

- Minor accounts use `accounts.account_type = 'minor'`
- Primary parents are represented by `account_memberships.role = 'guardian'`
- Secondary parents are represented by `account_memberships.role = 'co_guardian'`
- Co-parent invitation state lives in the central `guardian_invites` table
- Spending controls are enforced by a dedicated validation rule keyed off `accounts.permission_level`

## Architecture

### Account and membership model

- Tenant-scoped account records live in `accounts`
- Central membership records live in `account_memberships`
- Minor account linkage uses `accounts.parent_account_id`
- Minor account tiering uses `accounts.account_tier`
- Spending autonomy uses `accounts.permission_level`

### Authorization model

- `guardian`
  - View the minor account
  - Update the minor account
  - Delete the minor account
  - Invite co-guardians
  - Change permission level
- `co_guardian`
  - View the minor account
  - Accept an invite and activate membership
  - Cannot update or delete the minor account
  - Cannot change permission level
- Child
  - May view their own minor account context
  - Spending is still constrained by permission level validation

### Spending enforcement

`App\Rules\ValidateMinorAccountPermission` uses `transaction_projections` to enforce:

- View-only lockout for permission levels `1` and `2`
- Daily and monthly spend ceilings for levels `3` through `7`
- Hard blocked transaction categories for all minor accounts

Blocked categories:

- `alcohol`
- `tobacco`
- `gambling`
- `adult_content`

## Endpoints

### Create minor account

`POST /api/accounts/minor`

Request:

```json
{
  "name": "Emma",
  "date_of_birth": "2014-03-15",
  "photo_id_path": "kyc/minors/emma-id.jpg"
}
```

Response:

```json
{
  "success": true,
  "data": {
    "account": {
      "uuid": "minor-account-uuid",
      "account_type": "minor",
      "name": "Emma",
      "account_tier": "grow",
      "permission_level": 3,
      "parent_account_id": "parent-account-uuid"
    },
    "membership": {
      "account_uuid": "minor-account-uuid",
      "user_uuid": "guardian-user-uuid",
      "role": "guardian",
      "status": "active",
      "account_type": "minor"
    }
  }
}
```

### Update permission level

`PUT /api/accounts/minor/{uuid}/permission-level`

Request:

```json
{
  "permission_level": 4
}
```

Response:

```json
{
  "success": true,
  "data": {
    "uuid": "minor-account-uuid",
    "account_type": "minor",
    "account_tier": "grow",
    "permission_level": 4,
    "parent_account_id": "parent-account-uuid"
  }
}
```

Validation rules:

- Levels must be between `1` and `7`
- Levels cannot be reduced through this endpoint
- `grow` accounts cannot exceed level `4`
- `rise` accounts cannot exceed level `7`

### Invite co-guardian

`POST /api/accounts/minor/{minorAccountUuid}/invite-co-guardian`

Response:

```json
{
  "success": true,
  "data": {
    "code": "ABC12345",
    "expires_at": "2026-04-19T17:00:00.000000Z"
  }
}
```

### Accept co-guardian invite

`POST /api/guardian-invites/{code}/accept`

Response:

```json
{
  "success": true,
  "data": {
    "account_uuid": "minor-account-uuid",
    "user_uuid": "co-guardian-user-uuid",
    "role": "co_guardian",
    "status": "active",
    "account_type": "minor"
  }
}
```

Failure cases:

- `404` if the invite code does not exist
- `422` if the invite is expired
- `422` if the invite was already claimed

## Permission Matrix

| Permission Level | Typical Age | Access Pattern | Daily Limit | Monthly Limit |
|---|---:|---|---:|---:|
| 1 | 6-7 | View only | 0 SZL | 0 SZL |
| 2 | 8-9 | View only | 0 SZL | 0 SZL |
| 3 | 10-11 | Limited spend | 500 SZL | 5,000 SZL |
| 4 | 12-13 | Limited spend | 500 SZL | 5,000 SZL |
| 5 | 14-15 | Expanded spend | 1,000 SZL | 10,000 SZL |
| 6 | 16-17 | Higher spend | 2,000 SZL | 15,000 SZL |
| 7 | Parent granted | Higher spend | 2,000 SZL | 15,000 SZL |
| 8 | Personal account only | No minor restrictions | No limit | No limit |

## Guardian Role Differences

| Capability | guardian | co_guardian |
|---|---|---|
| View minor account | Yes | Yes |
| Accept invite | No | Yes |
| Generate invite | Yes | No |
| Update permission level | Yes | No |
| Update minor settings | Yes | No |
| Delete minor account | Yes | No |

## Deployment

Run the minor account migrations in order:

```bash
php artisan migrate --path=database/migrations/2026_04_16_120000_create_minor_account_columns_on_accounts_table.php --force
php artisan migrate --path=database/migrations/2026_04_16_120100_add_guardian_roles_to_account_memberships.php --force
php artisan migrate --path=database/migrations/2026_04_16_130000_create_guardian_invites_table.php --force
```

## Verification

Targeted phase verification:

```bash
php artisan test tests/Feature/Http/Policies/AccountPolicyTest.php
php artisan test tests/Feature/Http/Controllers/Api/MinorAccountControllerTest.php
php artisan test tests/Feature/Http/Controllers/Api/CoGuardianControllerTest.php
php artisan test tests/Feature/Http/Middleware/ResolveAccountContextTest.php
php artisan test tests/Feature/MinorAccountIntegrationTest.php
php artisan test tests/Unit/Rules/ValidateMinorAccountPermissionTest.php
```

Verified snapshot from the feature worktree:

- Result: `61 passed (135 assertions)`
- Scope: policy, controller, invite flow, middleware, integration, and permission-rule coverage
- Limitation: this does not imply the entire project test suite has been executed

## Deferred Work

Phase 1 intentionally does not include:

- Automatic tier promotion when a child ages into the next band
- Automatic age-18 conversion from `minor` to `personal`
- Virtual card issuance and merchant category configuration per child
- Child self-onboarding for older minors
- Event-sourced audit streams for invite lifecycle changes
