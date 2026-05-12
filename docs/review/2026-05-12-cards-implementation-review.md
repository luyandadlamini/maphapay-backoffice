# Cards Implementation Review And Remediation — 2026-05-12

## Scope

Reviewed the Cards implementation across:

- Backend `app/Domain/CardSubscriptions`, `app/Domain/CardIssuance`, `/api/mobile/config`, routes, resources, billing/idempotency paths, and `docs/cards`.
- Mobile `src/features/cards`, Cards wallet tab route, Quick Actions route, feature config gates, API fetchers, navigation helpers, and `docs/cards`.

External baseline used:

- OWASP API Security Top 10 2023: object authorization, business-flow abuse, resource limits, API inventory.
- Stripe idempotency guidance: persist/replay first result for a key and reject same-key/different-parameter reuse.
- Stripe Issuing and card lifecycle guidance: card lifecycle is separate from account/wallet lifecycle.
- PCI SSC guidance: PAN/CVV/sensitive authentication data must not be stored, logged, cached, or returned outside an approved reveal path.

## Findings

### Critical — Cards feature config was not delivered in the API envelope, so mobile failed closed to "coming soon"

Evidence:

- Mobile `useCardFeatureGates()` requires `status: success` from `/api/mobile/config` and reads `data.features.cards`.
- Backend `MobileController::getConfig()` did not return that envelope or `features.cards`.
- Mobile then treated missing config as all flags false, causing Cards to render `coming_soon` instead of a loading/error state.

Fix status: fixed.

- Backend now returns `{ status, remark, data }` for mobile config and includes `features.cards` in `app/Http/Controllers/Api/MobileController.php:958`.
- Card feature defaults are now configured in `config/mobile.php:97`.
- Mobile now exposes config loading/error through `useCardFeatureGates()` and renders loading/error before disabled state in `src/features/cards/domain/cardFeatureGates.ts:91` and `src/features/cards/domain/cardHomeScreenRouting.ts:27`.

### Critical — `/api/v1/cards*` route ownership was split between legacy CardIssuance and CardSubscriptions

Evidence:

- Legacy `CardIssuance` had mobile-facing `/v1/cards` list/create/detail/freeze/cancel routes while `CardSubscriptions` also registered the new monetised routes.
- This made the same product surface depend on route ordering and parameter-name differences.

Fix status: fixed.

- Legacy CardIssuance now keeps only `/v1/cards/provision`; the monetised mobile contract is owned by CardSubscriptions in `app/Domain/CardIssuance/Routes/api.php:11`.
- A route uniqueness regression test normalizes parameter names and fails on duplicate `/api/v1/cards*` method/URI ownership.
- Backend and mobile API docs now explicitly mark CardSubscriptions as the sole mobile-facing route authority.

### High — Backend read responses did not match the documented mobile contract

Evidence:

- Several controllers returned raw Laravel resources or `{ data: ... }` without `{ status, remark, data }`.
- `CardResource` emitted legacy fields like `network`, `label`, and omitted contract fields like `card_type`, `card_brand`, `nickname`, and flat `controls`.

Fix status: fixed.

- Added `RespondsWithCardApiEnvelope`.
- Cards, plans, current subscription, card detail, fee preview, transactions, and physical order read paths now return the documented envelope.
- `CardResource` now returns the mobile contract shape and avoids PAN/CVV/full-sensitive fields in `app/Domain/CardSubscriptions/Http/Resources/CardResource.php:15`.

### High — Card lifecycle vocabulary drifted from the shared contract

Evidence:

- Freeze wrote `frozen`, while contract/mobile use `frozen_by_user` and `frozen_by_admin`.

Fix status: fixed.

- User freeze now writes `frozen_by_user`.
- `Card::isFrozen()` recognizes `frozen_by_user` and `frozen_by_admin`.

### High — Idempotency replay could reuse a key across different card operations after HTTP-cache expiry

Evidence:

- `CardProductAuthorizationCoordinator` looked up prior card-product transactions by user/key/remark only. The same key on a different endpoint could replay the wrong pending transaction.

Fix status: fixed.

- Card product mutations now require `Idempotency-Key`.
- Existing key reuse compares canonical business payload hashes and returns `IDEMPOTENCY_PAYLOAD_MISMATCH` on mismatch in `CardProductAuthorizationCoordinator.php:25`.
- Regression tests cover missing keys and same-key/different-operation reuse.

### High — Mobile card detail navigation targeted an unregistered route

Evidence:

- `VirtualCardItem` pushed `/cards/${id}` while the registered Expo Router modal route is `/(modals)/cards/[cardId]/index.tsx`.

Fix status: fixed.

- Added a shared Cards route builder and wired card rows/guardian rows to `/(modals)/cards/[cardId]`.
- Regression test covers the href builder.

### Medium — Contract drift could silently become empty UI

Evidence:

- Mobile fetchers trusted `assertLaravelSuccess()` and then directly dereferenced `envelope.data!.cards`, `plans`, `subscription`, etc. Missing fields would become undefined at call sites.

Fix status: fixed.

- Added strict Cards response parsers that validate envelope, data object, arrays, nullable objects, and object fields in `src/features/cards/api/cardApiEnvelope.ts`.
- Cards fetchers now fail loudly on malformed backend shapes.

### Medium — Wallet/card separation needed an executable regression guard

Evidence:

- Docs require card billing failures to mutate card/subscription state only, not wallet state. There was no targeted test on the boundary.

Fix status: fixed.

- Added a regression test proving failed card billing can mark a subscription `past_due` while leaving the wallet account unfrozen.

## Files Modified

Backend:

- `app/Domain/AuthorizedTransaction/Handlers/CardProductAuthorizedHandler.php`
- `app/Domain/CardIssuance/Models/Card.php`
- `app/Domain/CardIssuance/Routes/api.php`
- `app/Domain/CardSubscriptions/Http/Concerns/RespondsWithCardApiEnvelope.php`
- `app/Domain/CardSubscriptions/Http/Controllers/CardController.php`
- `app/Domain/CardSubscriptions/Http/Controllers/CardFeeController.php`
- `app/Domain/CardSubscriptions/Http/Controllers/CardSubscriptionController.php`
- `app/Domain/CardSubscriptions/Http/Controllers/CardTransactionController.php`
- `app/Domain/CardSubscriptions/Http/Controllers/PhysicalCardOrderController.php`
- `app/Domain/CardSubscriptions/Http/Resources/CardFeePreviewResource.php`
- `app/Domain/CardSubscriptions/Http/Resources/CardResource.php`
- `app/Domain/CardSubscriptions/Http/Resources/CardSubscriptionResource.php`
- `app/Domain/CardSubscriptions/Services/CardLifecycleService.php`
- `app/Domain/CardSubscriptions/Services/CardProductAuthorizationCoordinator.php`
- `app/Http/Controllers/Api/MobileController.php`
- `config/mobile.php`
- `docs/cards/04-api-contract.md`
- `docs/cards/10-non-negotiables.md`
- `docs/cards/README.md`
- `tests/Feature/Cards/Http/CardContractTest.php`
- Existing Cards HTTP tests updated for the documented envelope.

Mobile:

- `docs/cards/03-api-contract.md`
- `docs/cards/06-non-negotiables.md`
- `src/features/cards/api/cardApiEnvelope.ts`
- `src/features/cards/api/cardApiEnvelope.test.mjs`
- `src/features/cards/api/cardFeesApi.ts`
- `src/features/cards/api/cardSubscriptionsApi.ts`
- `src/features/cards/api/cardTransactionsApi.ts`
- `src/features/cards/api/cardsApi.ts`
- `src/features/cards/api/physicalCardOrdersApi.ts`
- `src/features/cards/domain/cardFeatureGates.ts`
- `src/features/cards/domain/cardHomeScreenRouting.ts`
- `src/features/cards/domain/cardRoutes.ts`
- `src/features/cards/presentation/CardHomeScreen.routing.test.mjs`
- `src/features/cards/presentation/CardHomeScreen.tsx`
- `src/features/cards/presentation/GuardianMinorCardScreen.tsx`
- `src/features/cards/presentation/VirtualCardItem.tsx`
- `src/features/cards/presentation/VirtualCardItem.navigation.test.mjs`

## Verification

Backend:

```bash
./vendor/bin/pest tests/Feature/Cards/Http/CardContractTest.php tests/Feature/Cards/Http/CardControllerTest.php tests/Feature/Cards/Http/CardSubscriptionControllerTest.php tests/Feature/Cards/Http/CardPlanControllerTest.php --colors=never
XDEBUG_MODE=off vendor/bin/phpstan analyse app/Domain/CardSubscriptions/Http/Concerns/RespondsWithCardApiEnvelope.php app/Domain/CardSubscriptions/Http/Controllers/CardController.php app/Domain/CardSubscriptions/Http/Controllers/CardFeeController.php app/Domain/CardSubscriptions/Http/Controllers/CardSubscriptionController.php app/Domain/CardSubscriptions/Http/Controllers/CardTransactionController.php app/Domain/CardSubscriptions/Http/Controllers/PhysicalCardOrderController.php app/Domain/CardSubscriptions/Http/Resources/CardFeePreviewResource.php app/Domain/CardSubscriptions/Http/Resources/CardResource.php app/Domain/CardSubscriptions/Http/Resources/CardSubscriptionResource.php app/Domain/CardSubscriptions/Services/CardProductAuthorizationCoordinator.php app/Domain/CardSubscriptions/Services/CardLifecycleService.php app/Domain/AuthorizedTransaction/Handlers/CardProductAuthorizedHandler.php app/Domain/CardIssuance/Models/Card.php app/Domain/CardIssuance/Routes/api.php app/Http/Controllers/Api/MobileController.php --memory-limit=2G --no-progress
```

Result: 16 tests passed, 119 assertions. Targeted PHPStan on touched production files passed.

Mobile:

```bash
npm run test:cards -- --test-name-pattern='CardHome|cardApiEnvelope|VirtualCardItem'
npx tsc --noEmit --pretty false --incremental false --skipLibCheck true
```

Result: 243 tests passed. Targeted TypeScript passed. The existing Node warning about `MODULE_TYPELESS_PACKAGE_JSON` remains unrelated baseline noise.

## Manual Verification Checklist

- Open Cards from Quick Actions. Expected: loading while config/subscription/cards load, then disabled only if backend flag is explicitly false, otherwise upgrade/list state.
- Open Cards from Wallet Cards button. Expected: same Cards tab route, no wallet redirect/freeze.
- Pull to refresh Cards. Expected: mobile config, cards list, and subscription query all refresh.
- With no subscription. Expected: upgrade prompt; Wallet tab remains usable.
- With active subscription and no cards. Expected: empty card list/create CTA if plan allows.
- With cards. Expected: tapping a card opens `/(modals)/cards/[cardId]`.
- With API/config failure. Expected: retryable error, not "Cards coming soon."
- Inspect cards API JSON. Expected: no PAN/CVV/full sensitive authentication data; only `last4`, expiry month/year, status, and controls.

## Remaining Risks / Follow-Up

- Processor integration still needs live issuer contract verification for hosted reveal, webhook schemas, and authorization/clearing settlement behavior.
- PCI scope still depends on the actual issuer-hosted reveal page and logging/observability configuration outside this code path.
- Some CardSubscriptions services still contain TODOs for final ledger posting and processor settlement holds; those are out of this targeted remediation and should be tracked before production card billing/authorization launch.
- Broad backend PHPStan across the full Cards domain/test folder still reports unrelated baseline noise in existing request/resource/test typings; the touched production-file PHPStan slice passes.
