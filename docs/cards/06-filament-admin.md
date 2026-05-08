# 06 — Filament Admin

Filament v3 resources, pages, and actions for the cards domain. All admin actions write to `card_audit_logs` via `CardAuditService::recordAdminAction()`.

Resources live in `app/Filament/Admin/Resources/Cards/`. They follow the existing project conventions (workspace-gating with `HasBackofficeWorkspace`, `AdminActionGovernance::auditDirectAction()` for governance audit, Filament Shield for permissions).

---

## 1. Resource catalogue

| Resource | Purpose | Read-only? | Restricted to |
|---|---|---|---|
| `CardPlanResource` | Plan list + edit fees/limits | No (super_admin only) | super_admin |
| `CardSubscriptionResource` | All subscriptions, filter by status / minor | Edit limited (status changes via actions) | admin_manager+ |
| `MinorCardSubscriptionResource` | Filtered subset: `is_minor_subscription = true` with approval queue | Same | admin_manager+ |
| `CardResource` | Card list (extends or replaces existing) | Edit via actions only | admin_manager+ |
| `CardTransactionResource` | Transactions, search, filter | Read-only | support_agent+ |
| `PhysicalCardOrderResource` | Order pipeline view | Edit via actions only | admin_manager+ |
| `CardRiskEventResource` | Risk queue | Edit via actions only | fraud_analyst+ |
| `CardDisputeResource` | Dispute queue | Edit via actions only | fraud_analyst+ |
| `CardAuditLogResource` | Audit logs | Read-only (no edit, no delete) | compliance_officer+ |

Roles align with the existing project's role taxonomy:

```
support_agent < fraud_analyst < compliance_officer < admin_manager < super_admin
```

---

## 2. CardPlanResource

```php
namespace App\Filament\Admin\Resources\Cards;

use App\Domain\CardSubscriptions\Models\CardPlan;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Forms;

class CardPlanResource extends Resource
{
    protected static ?string $model = CardPlan::class;
    protected static ?string $navigationGroup = 'Cards';
    protected static ?int $navigationSort = 10;

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->sortable()->searchable()->copyable(),
                Tables\Columns\TextColumn::make('name')->sortable(),
                Tables\Columns\TextColumn::make('monthly_fee')->money('SZL')->sortable(),
                Tables\Columns\TextColumn::make('eligibility')->badge(),
                Tables\Columns\IconColumn::make('atm_enabled')->boolean(),
                Tables\Columns\TextColumn::make('fx_markup_bps')->label('FX bps'),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('eligibility')->options(['adult' => 'Adult', 'minor' => 'Minor']),
                Tables\Filters\TernaryFilter::make('active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn () => auth()->user()?->hasRole('super_admin')),
            ])
            ->defaultSort('monthly_fee');
    }

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(64),
            Forms\Components\TextInput::make('monthly_fee')->numeric()->step('0.01')->required(),
            Forms\Components\TextInput::make('max_virtual_cards')->numeric()->required()->minValue(0),
            // ... full schema for every plan column ...
            Forms\Components\Toggle::make('active'),
        ]);
    }

    public static function canCreate(): bool { return false; }      // Plans created via seeder only.
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }
}
```

**Edit action audit:** override `EditAction::after()` to call `CardAuditService::recordAdminAction()` with before/after states. Plans are pricing — every change is high-impact and must be auditable.

---

## 3. CardSubscriptionResource

Columns: `subscriber.full_name`, `subscriber.phone_number`, `payer.full_name` (only different from subscriber for minor subs), `plan.code`, `status` (badge with colours: green=active, yellow=past_due, red=suspended, grey=cancelled), `current_period_end`, `next_billing_date`, `failed_payment_count`.

Filters:
- Status (multi-select)
- Plan code
- "Is minor subscription" boolean
- Date range on `created_at`

Row actions:

| Action | Visible when | Effect | Audit action key |
|---|---|---|---|
| Retry payment | `status === 'past_due' OR 'suspended'` | `CardBillingService::retryFailedPayment()` | `subscription.payment_retry` |
| Suspend (admin) | `status === 'active'` | `subscription.suspend()` + suspend cards | `subscription.admin_suspended` |
| Reactivate | `status === 'suspended'` | `subscription.restore()` (retries payment) | `subscription.admin_reactivated` |
| Force cancel | any non-terminal status | `subscription.cancel()` + cancel cards immediately | `subscription.admin_cancelled` |
| Change plan | `status === 'active' OR 'past_due'` | Modal with reason → upgrade/downgrade | `subscription.admin_plan_change` |
| Waive next month | active subscriptions | Mark next billing as `waived` (creates `card_fees` row with `waived_at`) | `subscription.admin_waived` |

Every action requires a free-text reason captured in `card_audit_logs.metadata.admin_reason`.

Available bulk actions: NONE (subscription mutations must be deliberate).

---

## 4. MinorCardSubscriptionResource

A pre-filtered view of `CardSubscriptionResource` where `is_minor_subscription = true`. Adds a relationship manager for `minor_card_requests` so admins can see the request that originated each subscription.

Additional actions:

- **Override approve** — for cases where the guardian is unreachable and compliance has decided to approve manually. Requires `super_admin` role and a mandatory reason of ≥ 30 chars. Audited as `minor_request.admin_override_approved`.

This resource is the operational view for the customer support team handling minor accounts.

---

## 5. CardResource

Columns: `last4`, `cardholder.full_name`, `card_type`, `tier`, `status` (badge), `subscription.plan.code`, `created_at`.

Filters: status, card_type, plan_code, date range.

Row actions:

| Action | Visible when | Effect | Audit action key |
|---|---|---|---|
| Freeze (admin) | `status === 'active'` | `CardLifecycleService::adminFreeze()` | `card.admin_frozen` |
| Unfreeze (admin) | `status === 'frozen_by_admin'` | `CardLifecycleService::adminUnfreeze()` | `card.admin_unfrozen` |
| Mark lost/stolen | `status ∈ {active, frozen_by_user}` | `card.lost_stolen`, dispatch replacement request | `card.lost_stolen_marked` |
| Cancel (admin) | non-terminal | `CardLifecycleService::cancelCard()` | `card.admin_cancelled` |
| View transactions | always | navigate to filtered `CardTransactionResource` | — |
| View audit trail | always | navigate to filtered `CardAuditLogResource` (entity_id = card.id) | — |

Reveal: NEVER. Filament admins do NOT see PAN/CVV. The reveal endpoint is mobile-only with mandatory step-up. If support needs to verify a card, they ask the user to read out the last4.

---

## 6. CardTransactionResource

Columns: `merchant_name`, `merchant_country`, `amount`, `currency`, `billing_amount`, `billing_currency`, `fx_fee`, `mapha_fee`, `status` (badge), `authorised_at`, `settled_at`.

Filters: card.user_id (search), date range, status, currency.

No write actions (transactions are immutable). One read-only navigation:

- **Open dispute** — opens `CardDisputeResource::create()` pre-filled. Available only to support_agent+ when status `settled`.

Bulk export: CSV (with `AdminActionGovernance` reason gate per existing `AuditLogResource` pattern).

---

## 7. PhysicalCardOrderResource

Pipeline view: a Kanban-style table grouped by `order_status` showing how many orders are in each state.

Columns: `user.full_name`, `delivery_method`, `order_status`, `tracking_reference`, `requested_at`, `dispatched_at`.

Row actions per status:

| Status | Available actions |
|---|---|
| `requested` | Approve, Reject |
| `paid` | Approve (advance to `approved`), Refund + cancel |
| `approved` | Send to production, Cancel |
| `production` | Mark dispatched (with tracking ref), Mark ready for collection |
| `dispatched` | Mark delivered |
| `ready_for_collection` | Mark delivered |
| `delivered` | (waits for user activation in mobile app) |
| `activated` | (terminal) |
| `cancelled` | (terminal) |

Each transition opens a modal:
- "Mark dispatched" → tracking reference input, courier dropdown.
- "Mark delivered" → free-text confirmation.
- "Cancel" → reason input (refund decision: yes/no).

All transitions audited.

---

## 8. CardRiskEventResource

Columns: `severity` (badge: critical=red, high=orange, medium=yellow, low=grey), `event_type`, `user.full_name`, `card.last4`, `status`, `assigned_to_admin.name`, `created_at`.

Filters: severity, status, event_type, assigned to me, unassigned.

Row actions:

| Action | Effect |
|---|---|
| Assign to me | Set `assigned_to_admin_id`. |
| Mark in review | `status='in_review'`. |
| Resolve | Modal with `resolution_notes` (mandatory). `status='resolved'`. |
| Dismiss | Modal with reason. `status='dismissed'`. |
| Freeze related card | If `card_id` set, calls `CardLifecycleService::adminFreeze()`. |

Default sort: severity DESC, then `created_at` DESC.

---

## 9. CardDisputeResource

Columns: `user.full_name`, `card_transaction.merchant_name`, `disputed_amount`, `currency`, `reason`, `status`, `submitted_at`, `processor_dispute_id`.

Row actions:

| Action | Effect |
|---|---|
| Mark in review | `status='in_review'`. |
| Request evidence | `status='evidence_required'`. Push notification to user with what is needed. |
| Mark won | `status='won'`. Refund disputed amount via `CardFeeService::refundFee()`. |
| Mark lost | `status='lost'`. No refund. If pattern of frivolous disputes, also call `CardFeeService::chargeChargebackAbuseFee()`. |
| Mark withdrawn | `status='withdrawn'`. |

Always require `resolution_notes` for terminal transitions.

---

## 10. CardAuditLogResource

The compliance view. **Read-only. No edit. No delete. No bulk delete.**

Columns: `created_at`, `actor_type` (badge), `actor.name`, `action`, `entity_type`, `entity_id`, `ip_address`, `device_id`.

Filters:
- Date range
- Actor type (user / admin / system / processor)
- Action (search-as-you-type)
- Entity type
- Specific entity ID

Detail page: side-by-side `before_state` / `after_state` JSON viewer with diff highlighting (use Filament's existing JSON viewer or `diff-viewer-php`).

Bulk export: CSV with `AdminActionGovernance::auditDirectAction()` requiring a reason (existing pattern from `AuditLogResource.php`). Restricted to `compliance_officer+`.

---

## 11. Navigation

All resources go in a "Cards" navigation group, sorted:

```
1. Subscriptions
2. Cards
3. Transactions
4. Disputes
5. Risk events
6. Physical orders
7. Plans
8. Minor subscriptions          ← under "Compliance" sub-group, role-gated
9. Audit logs                   ← under "Compliance" sub-group, role-gated
```

---

## 12. Filament action governance pattern

Reuse the existing `AdminActionGovernance` helper (per `app/Filament/Admin/Resources/AuditLogResource.php`). Wrap every write action like:

```php
Tables\Actions\Action::make('admin_freeze_card')
    ->label('Freeze card')
    ->color('danger')
    ->requiresConfirmation()
    ->form([
        Forms\Components\Textarea::make('reason')->required()->minLength(10)->maxLength(500),
    ])
    ->action(function (Card $record, array $data) {
        AdminActionGovernance::guardAndExecute(
            actor: auth()->user(),
            actionKey: 'card.admin_frozen',
            entityType: 'card',
            entityId: $record->id,
            reason: $data['reason'],
            execute: fn () => app(CardLifecycleService::class)->adminFreeze(auth()->user(), $record, $data['reason']),
        );
    });
```

`guardAndExecute` checks role permissions, captures the reason, calls the service, and writes the audit log. No service call to `recordAdminAction` is needed inside the service when called via Filament — the governance helper does it.

---

## 13. Permissions (Filament Shield)

Define policies in `app/Policies/Cards/` for each resource:

```php
class CardSubscriptionPolicy {
    public function viewAny(User $user): bool { return $user->hasAnyRole(['support_agent','fraud_analyst','compliance_officer','admin_manager','super_admin']); }
    public function view(User $user, CardSubscription $sub): bool { return $this->viewAny($user); }
    public function update(User $user, CardSubscription $sub): bool { return $user->hasAnyRole(['admin_manager','super_admin']); }
    public function suspend(User $user, CardSubscription $sub): bool { return $user->hasAnyRole(['fraud_analyst','admin_manager','super_admin']); }
    public function forceCancel(User $user, CardSubscription $sub): bool { return $user->hasAnyRole(['admin_manager','super_admin']); }
    public function waive(User $user, CardSubscription $sub): bool { return $user->hasAnyRole(['admin_manager','super_admin']); }
    // ...
}
```

Run `php artisan shield:generate --resource=CardSubscriptionResource` to scaffold permissions, then migrate via `php artisan shield:install --tenant`.

---

## 14. Dashboard widgets

Add a "Cards Operations" dashboard at `/admin/cards-dashboard` with widgets:

- **Active subscriptions by plan** (donut chart)
- **MRR** (sum of `card_fees` where `fee_type='subscription'` and `status='charged'` in last 30 days)
- **Past-due count** (number, with delta from yesterday)
- **Open risk events** by severity (stacked bar)
- **Open disputes** count
- **Physical orders pipeline** (count per status)

All widgets read from materialised views or cached aggregates (see [`07-jobs-and-events.md`](./07-jobs-and-events.md) §5) — never run unindexed COUNT queries on raw tables.

---

## 15. Testing

Filament resources are testable via Pest with `Filament\Testing\TestsActions`. Required tests:

```
tests/Feature/Filament/Cards/CardSubscriptionResourceTest.php
tests/Feature/Filament/Cards/CardSubscriptionAdminActionsTest.php
tests/Feature/Filament/Cards/CardAuditLogReadOnlyTest.php
tests/Feature/Filament/Cards/CardDisputeResourceTest.php
tests/Feature/Filament/Cards/MinorCardSubscriptionApprovalOverrideTest.php
```

Each test signs in as a Filament admin via `$this->actingAs($admin)` (with the right role), invokes the action, and asserts:
1. Side effects occurred (DB state).
2. Audit log row was created.
3. Push notifications dispatched (use `Notification::fake()`).
4. The action is denied for users without the required role.
