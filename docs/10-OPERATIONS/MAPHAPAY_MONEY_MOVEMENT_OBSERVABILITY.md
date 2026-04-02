# MaphaPay Money Movement Observability And Rollout

Date: 2026-04-03

## Scope

This runbook covers the MaphaPay compatibility money-movement paths:

- `POST /api/send-money/store`
- `POST /api/request-money/store`
- `POST /api/request-money/received-store/{moneyRequest}`
- `POST /api/verification-process/verify/otp`
- `POST /api/verification-process/verify/pin`

## Structured Logs

Backend money-movement telemetry is emitted to:

- `config('maphapay_migration.observability.log_channel')` default: `structured`
- `config('maphapay_migration.observability.audit_channel')` default: `audit`

Primary events:

- `send_money_initiation_started`
- `send_money_initiation_succeeded`
- `send_money_initiation_failed`
- `request_money_initiation_started`
- `request_money_initiation_succeeded`
- `request_money_initiation_failed`
- `request_money_accept_initiation_started`
- `request_money_accept_initiation_succeeded`
- `request_money_accept_initiation_failed`
- `verification_succeeded`
- `verification_failed`
- `idempotency_replay`
- `idempotency_conflict`
- `duplicate_acceptance_prevented`
- `money_request_transition`
- `rollout_blocked`

Recommended log filters:

- `message = "maphapay.compat.money_movement"`
- `context.domain = "maphapay_money_movement"`
- `context.event = "<event-name>"`

Questions these logs answer:

- Retry volume: filter `context.event in ["idempotency_replay", "operation_record_replay"]`
- Verification failures: filter `context.event = "verification_failed"`
- Duplicate accept prevention: filter `context.event = "duplicate_acceptance_prevented"`
- Request lifecycle transitions: filter `context.event = "money_request_transition"`

## Metrics

Prometheus export endpoint:

- `GET /api/metrics`
- `GET /api/prometheus`

Money-movement counters:

- `maphapay_money_movement_retries_total`
- `maphapay_money_movement_verification_failures_total`
- `maphapay_money_request_duplicate_acceptance_prevented_total`
- `maphapay_money_movement_rollout_blocked_total`

Suggested dashboard panels:

1. Retries
   Query: `maphapay_money_movement_retries_total`
2. Verification failures
   Query: `maphapay_money_movement_verification_failures_total`
3. Duplicate acceptance prevention
   Query: `maphapay_money_request_duplicate_acceptance_prevented_total`
4. Rollout blocks
   Query: `maphapay_money_movement_rollout_blocked_total`

## Rollout Flags

Global compat gates:

- `MAPHAPAY_MIGRATION_ENABLE_VERIFICATION`
- `MAPHAPAY_MIGRATION_ENABLE_SEND_MONEY`
- `MAPHAPAY_MIGRATION_ENABLE_REQUEST_MONEY`

Granular request-money rollout gates:

- `MAPHAPAY_MIGRATION_ENABLE_REQUEST_MONEY_CREATE`
- `MAPHAPAY_MIGRATION_ENABLE_REQUEST_MONEY_ACCEPT`

If the granular flags are unset they inherit the value of `MAPHAPAY_MIGRATION_ENABLE_REQUEST_MONEY`.

## Recommended Production Sequence

1. Enable verification routes first.
   Keep in-flight OTP/PIN completion available before widening initiation traffic.
2. Enable send-money initiation.
3. Enable `MAPHAPAY_MIGRATION_ENABLE_REQUEST_MONEY_CREATE`.
4. Verify request creation logs and metrics in production.
5. Enable `MAPHAPAY_MIGRATION_ENABLE_REQUEST_MONEY_ACCEPT`.
6. Watch retries, verification failures, and duplicate-acceptance prevention for at least one full business cycle before widening further.

## Smoke Checks

After any rollout change:

1. Hit one controlled sandbox transfer path.
2. Confirm `X-Request-ID` is present in the response.
3. Confirm structured log events appear for initiation and verification.
4. Confirm Prometheus counters are non-zero for the exercised path.
5. If rollout is intentionally disabled, confirm the route returns `404` and `maphapay_money_movement_rollout_blocked_total` increments.
