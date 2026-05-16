# Investigation: Dashboard Balance Returns 0.00 — Root Cause & Resolution

## Executive Summary

The MaphaPay mobile app's Home screen displays `balance: "0.00"` for a user
who funded their wallet via the Stripe card-issuing test flow.

**Original hypothesis** (now disproven): the dashboard hardcoded `'SZL'`
when the user's account currency was `'USD'`, so a USD→SZL display-time
fallback was added.

**Verified root cause**: `account_balances` only has a `USD` row for that
account because Stripe card provisioning credits USD. The dashboard reads
`getBalance('SZL')` — which is the correct, intended behaviour — and there
genuinely is no SZL to show.

**Status**: Resolved on `main` working tree (uncommitted). The deployed
"fix" was dead code and has been reverted.

---

## Verified Findings

### 1. The deployed FX-fallback was never executing

The Laravel Cloud deployment at `depl-a1ca0f62-8d95-4f91-b40a-2b1e3a94339d`
shipped code that read `$account->currency` and branched on `=== 'USD'`.

`accounts` table has **no `currency` column** (confirmed by `grep` across
all migrations and the `@property` block on `App\Domain\Account\Models\Account`).
Therefore:

- `$account->currency` returned `null`
- `null === 'USD'` was always `false`
- The fallback block never executed

The earlier log line `"currency": "USD"` came from a different model dumped
in a different session and misled the diagnosis.

### 2. The fallback would have caused a worse bug

`Account` is asset-agnostic in this codebase. `AccountBalance` keys on
`(account_uuid, asset_code)` and is the source of truth for what the user
can spend.

The send-money path at
`app/Domain/MoneyMovement/.../InitiateAssetTransferActivity.php:43-52`
queries:

```php
AccountBalance::where('account_uuid', $fromAccountUuid->toString())
    ->where('asset_code', $fromAssetCode)  // 'SZL' by default
    ->first();
```

If the dashboard had displayed `E 1,850` (via USD × 18.5 FX), tapping
**Send** would have returned "Insufficient SZL balance" — the UI would
have lied about spendable money.

### 3. The actual data state

Account `dcb74026-b79b-421f-b04b-20bfaaa34eaa` has only a USD
`account_balances` row from Stripe testing. No SZL row exists, so
`getBalance('SZL')` returns 0 — correctly.

The underlying issue is that the **Stripe card funding pipeline doesn't
credit SZL**. That's a separate, larger fix and is flagged below.

---

## Resolution

### Controller — reverted to clean state

`app/Http/Controllers/Api/Compatibility/Dashboard/DashboardController.php`

- Removed the dead `accountCurrency === 'USD'` FX-fallback block
- Removed verbose `[compat:dashboard] Debug`, `Balance check`, and
  `Account UUIDs` logging (the single `response` info log is kept)
- Restored `CACHE_TTL_SECONDS = 30`
- Updated docblock to explain: SZL is canonical, non-SZL inflows are the
  funding pipeline's responsibility, not the read endpoint's

### Regression test added

`tests/Feature/.../DashboardControllerTest.php::test_usd_only_account_reports_zero_szl_balance_with_no_fx_conversion`

Asserts that an account with a USD AccountBalance and no SZL row returns
`balance: "0.00"`. This locks in the truthful behaviour and prevents
re-introduction of a display-time FX shim.

Also fixed an existing assertion in `test_response_is_cached_per_user` that
checked the wrong cache key (`maphapay.dashboard.{id}` instead of
`maphapay.dashboard.balance.{id}`).

### One-shot data repair

`app/Console/Commands/BackfillSzlBalanceFromUsdCommand.php`
(`php artisan maphapay:backfill-szl-from-usd <account_uuid>`)

For the specific affected account, converts the stranded USD balance into
SZL using the configured FX rate so the dashboard and send-money can see
it. Dry-run by default; requires `--apply` plus interactive confirmation.

Usage for the affected account:

```bash
php artisan maphapay:backfill-szl-from-usd dcb74026-b79b-421f-b04b-20bfaaa34eaa
# review the table, then:
php artisan maphapay:backfill-szl-from-usd dcb74026-b79b-421f-b04b-20bfaaa34eaa --apply
```

Intentionally bypasses the event-sourced aggregate — this is data repair,
not a real money movement. The conversion is logged at INFO for audit.

---

## Verification

```bash
PHP_BIN="$HOME/Library/Application Support/Herd/bin/php"

# Tests (7/7 pass — including the new regression test)
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
DB_DATABASE=maphapay_backoffice_test DB_USERNAME=maphapay_test \
DB_PASSWORD='maphapay_test_password' \
"$PHP_BIN" ./vendor/bin/pest \
  tests/Feature/Http/Controllers/Api/Compatibility/Dashboard/DashboardControllerTest.php

# Static analysis (PHPStan: no errors)
XDEBUG_MODE=off "$PHP_BIN" vendor/bin/phpstan analyse --memory-limit=2G \
  app/Http/Controllers/Api/Compatibility/Dashboard/DashboardController.php \
  app/Console/Commands/BackfillSzlBalanceFromUsdCommand.php

# Code style (php-cs-fixer: no changes needed)
"$PHP_BIN" ./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.php \
  app/Http/Controllers/Api/Compatibility/Dashboard/DashboardController.php \
  app/Console/Commands/BackfillSzlBalanceFromUsdCommand.php
```

---

## Follow-up: Stripe funding pipeline

The real upstream bug — independent of this dashboard work — is that
Stripe card-issuing credits USD into `account_balances` while the rest of
the app treats SZL as the canonical user-facing currency. Funding must
either:

1. **Credit SZL natively** by converting at credit-time using a live FX
   source (recommended; matches send-money semantics), or
2. **Track USD as a non-spendable card-only balance** that's surfaced in
   a separate "Card funds" UI, with a user-initiated SZL conversion step.

Option 1 is simpler and matches what users expect in an SZL-only market.
The hardcoded `STRIPE_FX_RATE_USD_SZL = 18.50` env var is fine as an
interim rate but should move to a real FX feed before public launch.

This is out of scope for the current branch.

---

## Files Touched

| File | Change |
|------|--------|
| `app/Http/Controllers/Api/Compatibility/Dashboard/DashboardController.php` | Reverted dead FX fallback; removed debug logging; restored 30s cache TTL |
| `tests/Feature/Http/Controllers/Api/Compatibility/Dashboard/DashboardControllerTest.php` | Added USD-only regression test; fixed cache-key assertion |
| `app/Console/Commands/BackfillSzlBalanceFromUsdCommand.php` | New: one-shot SZL backfill for stranded USD-funded accounts |
| `docs/INVESTIGATION_BALANCE_BUG_2026_05_16.md` | Rewritten with verified findings and resolution |
