# 10 — Backend Non-Negotiables

Hard rules. The mobile equivalent is in `maphapayrn/docs/cards/06-non-negotiables.md`. Both files are intentionally redundant — each repo is self-contained.

---

1. **Wallet works without a card.** No code path in this domain may reach into wallet/send-money/QR-payment services and disable them. Card subscription state is observable to but not controlling of the wallet.

2. **Failed card subscription does NOT freeze the wallet.** When the subscription transitions to `past_due`, `suspended`, or `cancelled`, the only state mutated is on `card_subscriptions` and `cards`. The wallet's `status` column is never touched by this domain.

3. **PAN and CVV NEVER touch MaphaPay infrastructure.** No DB column. No log line. No error report. No JSON response. No metrics field. No analytics event. Reveal is processor-hosted with a short-TTL signed URL — that's the only mechanism. The `CardAuditService` rejects any string field matching `/^\d{12,19}$/` to enforce this defensively.

4. **No new `mapha_cards` table.** The existing `cards` table is extended via ALTER. Creating a parallel cards table is forbidden; it would split state and double the risk surface.

5. **No `app/Models/Card.php`.** The model is `app/Domain/CardIssuance/Models/Card.php`. Creating the parallel is forbidden; circular imports and confused authorities follow.

6. **KYC gate uses `KycVerificationStatus::canTransact()`.** Not raw string comparison. Not `=== 'approved'`. Not `=== 'verified'`. The enum + method is the only check. The implementation already exists; reuse it.

7. **Backend enforces every entitlement.** Mobile feature flags hide UI. They do NOT enforce rules. Every controller calls into `CardEntitlementService` before the service does anything. Bypassing entitlement for "internal calls" or "admin overrides" still goes through the entitlement service (which knows about admin overrides via context).

8. **Money values are strings in the API.** `"50.00"` not `50.00` not `5000`. Backend conversion via `MoneyConverter::forAsset()`. NEVER `floatval()`. Decimal precision matters; bcmath is the only computation path.

9. **Idempotency is mandatory on every money-affecting endpoint.** Subscribe, upgrade, downgrade, cancel, retry-payment, create-virtual, request-physical, activate-physical, replace, dispute, all webhooks. `Idempotency-Key` header (mobile-driven) OR derived stable key (system jobs). Duplicate keys with same body return cached response; duplicate keys with different body raise an alert.

10. **Audit before mutate.** Every state-changing operation writes to `card_audit_logs` first, then mutates state. If the mutation fails, we still have evidence of the attempt. Webhook controllers persist raw body to audit BEFORE state mutation.

11. **Audit log is append-only.** `card_audit_logs` has no UPDATE or DELETE in any service code. Filament policies forbid edit / delete. Only `super_admin` can export. CI lint fails on any code that issues UPDATE on this table.

12. **`CardSubscriptions` MAY depend on `CardIssuance`. The reverse is forbidden.** No file under `app/Domain/CardIssuance/` may `use App\Domain\CardSubscriptions\...`. The `module.json` `depends_on` array is enforced by the loader.

12a. **Mobile-facing Cards routes have one owner.** `CardSubscriptions` owns `/v1/cards*`, `/v1/card-subscriptions*`, `/v1/card-fees*`, `/v1/card-transactions*`, `/v1/minor-card-requests*`, and `/webhooks/cards*`. `CardIssuance` may expose processor/provisioning internals only; duplicate route ownership is a production blocker.

13. **Webhook signatures are verified with `hash_equals`.** Constant-time comparison. NOT `===`. NOT `==`. NOT `strcmp`.

14. **Multi-tenancy: every tenant table uses `UsesTenantConnection`.** `card_plans` is the only global table. Forgetting the trait on tenant data is a P0 bug — data crosses tenants.

15. **Spend limits are clamped to plan ceilings server-side.** A user-supplied `per_transaction_limit` higher than `plan.single_transaction_limit` is rejected with `LIMIT_EXCEEDS_PLAN` (return 422, do not silently clamp). Silent clamping hides bugs.

16. **Admin actions are governed.** All Filament write actions go through `AdminActionGovernance::guardAndExecute()`. Reasons are mandatory; audit is automatic; role checks are mandatory. No bare service calls from controllers.

17. **No raw SQL in services.** Eloquent + query builder only. Raw SQL is allowed for migrations and dashboard aggregates, never in domain logic. (Existing project convention; do not break it.)

18. **`canViewAny` policies are deny-by-default.** A new resource or action must explicitly list which roles can use it. Forgetting → access denied → forces the developer to think.

19. **Tests are mandatory.** Phase acceptance requires Pest tests passing. PRs without tests are rejected. The mobile team consumes endpoints; if the endpoint isn't covered by Pest, mobile work is blocked.

20. **Migration `down()` works.** Every migration's `down()` reverses cleanly. Tested with `migrate:rollback --pretend`. Never ship a one-way migration.

---

## Self-test before merge

- [ ] Did I add a column or response field that contains PAN or CVV? (revert)
- [ ] Did I weaken the wallet/card boundary? (revert)
- [ ] Did I bypass `CardEntitlementService` for any controller path? (revert)
- [ ] Did I issue UPDATE/DELETE on `card_audit_logs`? (revert)
- [ ] Did I add a tenant table without `UsesTenantConnection`? (fix)
- [ ] Did I import `CardSubscriptions` from inside `CardIssuance`? (refactor)
- [ ] Did I leave a hard-coded plan number in service code instead of reading from `card_plans`? (move to seeder + config)
- [ ] Did I use `==` for HMAC verification? (use `hash_equals`)
- [ ] Did I add a money operation without bcmath? (use `MoneyConverter`)
- [ ] Did my new migration include `down()`? (add it)

If any answer is "yes" / "no" wrong, fix before requesting review.
