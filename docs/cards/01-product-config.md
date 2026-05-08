# 01 — Product Configuration (Canonical)

**This file is byte-identical between repos.** Source of truth for plan codes, fees, limits, and formulas. Anything not in this file is not part of the configuration. Do not introduce new numbers in code; seed them into `card_plans` from this table.

See [`CONTRACT.md`](./CONTRACT.md) for the enum definitions referenced here.

---

## 1. Plan matrix

All amounts in **SZL major units** (E). All `*_bps` values are basis points (1 bp = 0.01%).

| Plan code | Monthly | Virt cards | Phys | Single tx | Daily spend | Monthly spend | ATM daily | ATM monthly | FX bps | ATM fixed | ATM bps | Phys issue | Phys replace | Virt replace | Free virt reissue | Eligibility |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---|
| FREE_WALLET | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | adult |
| VIRTUAL_LITE | 25 | 1 | 0 | 1500 | 1500 | 3000 | 0 | 0 | 350 | 0 | 0 | 0 | 0 | 15 | 0 | adult |
| VIRTUAL_PLUS | 50 | 3 | 0 | 5000 | 7500 | 15000 | 0 | 0 | 300 | 0 | 0 | 0 | 0 | 20 | 1 | adult |
| PHYSICAL_CARD | 65 | 3 | 1 | 7500 | 10000 | 25000 | 1500 | 5000 | 275 | 12 | 150 | 120 | 90 | 20 | 1 | adult |
| PREMIUM_CARD | 120 | 5 | 1 | 15000 | 25000 | 60000 | 3000 | 10000 | 175 | 8 | 100 | 0 | 60 | 20 | 2 | adult |
| MINOR_KHULA_CARD | 20 | 1 | 0 | 500 | 500 | 2000 | 0 | 0 | 350 | 0 | 0 | 0 | 0 | 15 | 0 | minor (Khula, ages 13–17), guardian-billed |

**Chargeback abuse fee:** E100 (all plans except FREE_WALLET).

**Currency:** all subscription billing, fees, and Eswatini-domestic transactions in SZL. ZAR is treated as domestic-equivalent for FX (no markup).

## 2. Plan eligibility rules

| Plan | KYC required | Account type | Notes |
|---|---|---|---|
| FREE_WALLET | basic | any | Default. Auto-assigned on signup. |
| VIRTUAL_LITE | full (`VERIFIED`) | adult | Cannot be assigned to minors. |
| VIRTUAL_PLUS | full (`VERIFIED`) | adult | |
| PHYSICAL_CARD | full (`VERIFIED`) | adult | Plus address verification before card production. |
| PREMIUM_CARD | full (`VERIFIED`) | adult | |
| MINOR_KHULA_CARD | full (`VERIFIED`) on guardian; minor account active | minor (Khula, ages 13–17 only) | Subscription created via `minor_card_requests` approval. Billed to `payer_user_id` = guardian. |

Grow-tier minors (6–12) have **no** card plan available. The mobile UI MUST surface "cards not available for this account type" rather than the upgrade screen.

> **Naming:** "Khula" (siSwati: "to grow") is the tier name for MaphaPay's minor card product, ages 13–17. Use `MINOR_KHULA_CARD` in code and "Khula" in UI copy. Do not use "Rise" anywhere in cards code, docs, or copy — the cards product is fully siSwati-branded.

## 3. FX markup formula

```
IF transaction_currency IN ('SZL', 'ZAR'):
    fx_fee = 0
ELSE:
    fx_fee = billing_amount_in_szl * plan.fx_markup_bps / 10000
```

Worked examples:

| Plan | Billing amount | bps | FX fee |
|---|---:|---:|---:|
| VIRTUAL_LITE | E1,000 | 350 | E35.00 |
| VIRTUAL_PLUS | E1,000 | 300 | E30.00 |
| PHYSICAL_CARD | E1,000 | 275 | E27.50 |
| PREMIUM_CARD | E1,000 | 175 | E17.50 |
| MINOR_KHULA_CARD | E1,000 | 350 | E35.00 |

## 4. ATM fee formula

```
atm_fee = plan.atm_fixed_fee + (withdrawal_amount * plan.atm_percentage_fee_bps / 10000)
```

Worked examples:

| Plan | Withdrawal | Fixed | bps | ATM fee | Total wallet debit |
|---|---:|---:|---:|---:|---:|
| PHYSICAL_CARD | E500 | E12 | 150 | E19.50 | E519.50 |
| PHYSICAL_CARD | E1,000 | E12 | 150 | E27.00 | E1,027.00 |
| PREMIUM_CARD | E500 | E8 | 100 | E13.00 | E513.00 |
| PREMIUM_CARD | E1,000 | E8 | 100 | E18.00 | E1,018.00 |

ATM is rejected (`ATM_NOT_ALLOWED`) on plans where `atm_enabled = false`.

## 5. Free virtual reissue allowance

`free_virtual_reissues_per_month` resets on the first of each calendar month, **not** on the subscription anniversary. Once exhausted, `virtual_card_replacement_fee` applies per replacement.

## 6. Subscription billing schedule

| Day | Action |
|---|---|
| 0 (billing date) | Debit `payer_user_id` wallet for `monthly_fee`. |
| 0, success | Subscription stays/becomes `active`. `next_billing_date` += 1 month. `failed_payment_count = 0`. |
| 0, failure | Status → `past_due`. `failed_payment_count += 1`. `grace_period_ends_at = now + 3 days`. Notify user (and guardian for minor plans). |
| 1, 2, 3 | Daily retry. On success → `active`. On failure → remain `past_due`. |
| 3 (grace expiry) | Status → `suspended`. All cards under subscription → `suspended`. New authorisations decline `SUBSCRIPTION_INACTIVE`. Wallet remains active. |
| 4–13 | Daily retry continues. On success → `active`, restore cards previously suspended for billing only (NOT user-frozen, NOT admin-frozen). |
| 14 | If still unpaid → status → `cancelled`. Cards → `cancelled`. User must re-subscribe (creating a new subscription record) to regain card access. |

Wallet is **never** frozen due to card-subscription failure unless an independent compliance/fraud action is taken.

## 7. Wallet limits (Free Wallet, KYC-tiered)

| Limit | Basic KYC | Full KYC (`VERIFIED`) |
|---|---:|---:|
| Max wallet balance | E2,000 | E50,000 |
| Max monthly inflow | E5,000 | E100,000 |
| Max single MaphaPay-to-MaphaPay transfer | E500 | E10,000 |
| Max daily MaphaPay-to-MaphaPay transfer | E1,500 | E25,000 |
| Max monthly MaphaPay-to-MaphaPay transfer | E5,000 | E100,000 |

These are enforced by existing wallet/send-money services, not by card services. Card monetisation MUST NOT alter them.

## 8. Local wallet fees (unchanged by monetisation)

| Transaction | Fee |
|---|---:|
| MaphaPay → MaphaPay transfer ≤ E500 | E0 |
| MaphaPay → MaphaPay transfer > E500 | E1 |
| Customer pays merchant by QR | E0 |
| Customer pays merchant by Merchant ID | E0 |
| Receive money | E0 |

These are documented for reference. Card monetisation MUST NOT change them.

## 9. Risk thresholds (initial)

| Trigger | Severity | Action |
|---|---|---|
| > 5 declined card auths in 10 minutes | high | Freeze card; create risk event; notify fraud queue. |
| > 10 declined card auths in 24 hours | high | Same as above. |
| > 3 different merchants declining within 30 minutes | high | Same as above. |
| > 2 card replacements in 30 days | medium | Block further replacements until manual review. |
| > 2 disputes in 60 days | medium | Manual review on next dispute. |
| ATM attempt on virtual-only plan | medium | Single-event log; auto-decline. |
| Blocked-MCC attempt | medium | Single-event log; auto-decline. |
| Card created and used internationally within 10 minutes (newly KYC'd user) | medium | Step-up; if step-up fails → high. |
| First international transaction > E2,000 | medium | Step-up. |
| Spend in ≥ 3 countries within 24 hours | medium | Manual review. |

These are enforced by `CardRiskService`. See [`05-services-and-rules.md`](./05-services-and-rules.md).

## 10. Merchant category controls (MVP categories)

User-toggleable blocks (mobile UI labels):

```
Gambling
Crypto
Adult content
High-risk digital goods
Cash-like transactions
```

Each maps to a list of MCC codes maintained in `config/cards/mcc_groups.php`. The list is editable by admins via Filament without a deploy.

## 11. Feature flags

Defined in `config/mobile.php` under `features.cards`, exposed via `GET /api/mobile/config`:

```json
{
  "cards_monetisation_enabled": false,
  "card_subscriptions_enabled": false,
  "virtual_card_lite_enabled": false,
  "virtual_card_plus_enabled": false,
  "physical_card_enabled": false,
  "premium_card_enabled": false,
  "minor_khula_card_enabled": false,
  "card_fx_fees_enabled": false,
  "card_atm_enabled": false,
  "card_disputes_enabled": false,
  "card_admin_risk_controls_enabled": true
}
```

**Critical rule:** flags hide UI; they DO NOT enforce rules. Backend MUST enforce every entitlement regardless of flag state. Flags are for staged rollout, not security.

## 12. Seeder source of truth

The seeder `database/seeders/CardPlanSeeder.php` upserts by `code`. The values it inserts MUST match the table in §1 exactly. CI compares the seeder output to this doc on every PR — drift fails the build.
