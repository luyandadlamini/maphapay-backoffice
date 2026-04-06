# MaphaPay Back Office Modernization — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Elevate the MaphaPay Back Office from a developer toolkit into a production-ready, secure, maker-checker workflow-driven operations center — without rewriting the backend.

**Architecture:** All changes are pure Filament Admin layer additions/refinements on top of the existing Laravel 12 / Spatie Event Sourcing foundation. No domain logic is changed in Phase 1. Phases 2–3 add thin domain commands and projectors only where required. Every sensitive action must log an audit trail via the existing event stream.

**Tech Stack:** PHP 8.4, Laravel 12, Filament v3, Spatie Event Sourcing, Spatie Laravel Permission (confirmed installed), Pest PHP.

---

## Current State Audit (Verified in Code)

Before executing any task, understand what **already exists**:

| Item | Status | Notes |
|---|---|---|
| `GlobalTransactionResource` | ✅ Exists | Missing: type filter, date range filter, amount filter, infolist timeline |
| `AdjustmentRequestResource` | ✅ Exists | Approve action has `// TODO: Dispatch domain event` — **not wired to event sourcing** |
| `SupportCaseResource` | ✅ Exists | Missing: relation managers (notes, attachments), escalation |
| `UserResource` | ✅ Excellent | Has freeze/unfreeze, KYC, OTP resend, Customer 360 infolist |
| Navigation groups in `AdminPanelProvider` | ✅ Registered | But most resources still use **old group names** and won't appear correctly |
| `AdjustmentRequest` model | ✅ Exists | In `app/Domain/Account/Models/AdjustmentRequest.php` |
| `SupportCase` model | ✅ Exists | In `app/Domain/Support/Models/SupportCase.php` |
| Spatie Permission | ✅ Installed | No role/permission seeder exists yet |
| Roles seeder | ❌ Missing | No `RolesAndPermissionsSeeder` found |
| Domain command: `ProcessManualLedgerAdjustment` | ❓ Unverified | AdjustmentRequestResource references it but TODO suggests it's not wired |
| MoMo exception dashboard | ❌ Missing | No observability UI for failed MoMo/utility transactions |

### Navigation Group Mismatch (Must Fix First)

These resources are in **wrong groups** relative to the registered navigation structure:

| Resource | Current Group | Target Group |
|---|---|---|
| `UserResource` | `System` | `Customers` |
| `AccountResource` | `Banking` | `Wallets & Ledgers` |
| `PocketResource` | `Mobile Features` | `Wallets & Ledgers` |
| `MultiSigWalletResource` | `Wallets` | `Wallets & Ledgers` |
| `KycDocumentResource` | `TrustCert` | `Compliance` |
| `AnomalyDetectionResource` | `Fraud Detection` | `Risk & Fraud` |
| `MerchantResource` | `Commerce` | `Merchants & Orgs` |
| `PaymentIntentResource` | `Mobile Payments` | `Transactions` |
| `MtnMomoTransactionResource` | `Transactions` | `Transactions` ✅ |
| `ReconciliationReportResource` | `Banking` | `Finance & Reconciliation` |
| `AuditLogResource` | `System` | `Platform` |
| `ApiKeyResource` | `System` | `Platform` |
| `WebhookResource` | `System` | `Platform` |

---

## Phase 0: Navigation Realignment (Day 1 — ~2 hours)

> **Why first:** Nothing else matters if operators can't find the tools. This is zero-risk, zero-domain-change work.

### Task 0.1 — Fix All Resource Navigation Groups

**Files to modify:**
- `app/Filament/Admin/Resources/UserResource.php:45`
- `app/Filament/Admin/Resources/AccountResource.php:33`
- `app/Filament/Admin/Resources/PocketResource.php:21`
- `app/Filament/Admin/Resources/MultiSigWalletResource.php:23`
- `app/Filament/Admin/Resources/KycDocumentResource.php:20`
- `app/Filament/Admin/Resources/AnomalyDetectionResource.php:23`
- `app/Filament/Admin/Resources/MerchantResource.php:24`
- `app/Filament/Admin/Resources/PaymentIntentResource.php:24`
- `app/Filament/Admin/Resources/ReconciliationReportResource.php:21`
- `app/Filament/Admin/Resources/AuditLogResource.php:20`
- `app/Filament/Admin/Resources/ApiKeyResource.php:22`
- `app/Filament/Admin/Resources/WebhookResource.php:24`

- [x] **Step 1: Update navigation groups for core operational resources**

  In each file, change the `$navigationGroup` property to match the target group:

  ```php
  // UserResource.php
  protected static ?string $navigationGroup = 'Customers';

  // AccountResource.php
  protected static ?string $navigationGroup = 'Wallets & Ledgers';

  // PocketResource.php
  protected static ?string $navigationGroup = 'Wallets & Ledgers';

  // MultiSigWalletResource.php
  protected static ?string $navigationGroup = 'Wallets & Ledgers';

  // KycDocumentResource.php
  protected static ?string $navigationGroup = 'Compliance';

  // AnomalyDetectionResource.php
  protected static ?string $navigationGroup = 'Risk & Fraud';

  // MerchantResource.php
  protected static ?string $navigationGroup = 'Merchants & Orgs';

  // PaymentIntentResource.php
  protected static ?string $navigationGroup = 'Transactions';

  // ReconciliationReportResource.php
  protected static ?string $navigationGroup = 'Finance & Reconciliation';

  // AuditLogResource.php
  protected static ?string $navigationGroup = 'Platform';

  // ApiKeyResource.php
  protected static ?string $navigationGroup = 'Platform';

  // WebhookResource.php
  protected static ?string $navigationGroup = 'Platform';
  ```

- [x] **Step 2: Expand navigation groups in AdminPanelProvider to catch stragglers**

  Open `app/Providers/Filament/AdminPanelProvider.php`. The `navigationGroups()` array currently has 10 entries. Expand to the full target structure:

  ```php
  ->navigationGroups([
      'Dashboard',
      'Customers',
      'Merchants & Orgs',
      'Wallets & Ledgers',
      'Transactions',
      'Compliance',
      'Risk & Fraud',
      'Support Hub',
      'Finance & Reconciliation',
      'Growth & Rewards',
      'Notifications',
      'Configuration',
      'Platform',
  ])
  ```

- [x] **Step 3: Boot the application and verify sidebar structure**

  Run: `php artisan serve` and navigate to `/admin`
  Expected: All nav groups visible, resources appear under correct groups.

- [x] **Step 4: Commit**

  ```bash
  git add app/Filament/Admin/Resources/*.php app/Providers/Filament/AdminPanelProvider.php
  git commit -m "feat(admin): align all resources to MaphaPay-first navigation groups"
  ```

---

## Phase 1: Launch-Critical Completions (W1 — ~3 days)

> These are **blockers**. Production cannot go live without them.

### Task 1.1 — Enrich Global Transaction Resource

**Context:** `GlobalTransactionResource` exists but is missing filters operators actually need.

**Files:**
- Modify: `app/Filament/Admin/Resources/GlobalTransactionResource.php`
- Modify: `app/Filament/Admin/Resources/GlobalTransactionResource/Pages/ViewGlobalTransaction.php`
- Test: `tests/Filament/Admin/Resources/GlobalTransactionResourceTest.php` *(create)*

- [x] **Step 1: Write failing test**

  Create `tests/Filament/Admin/Resources/GlobalTransactionResourceTest.php`:

  ```php
  <?php

  use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
  use App\Models\User;

  use function Pest\Laravel\actingAs;
  use function Pest\Livewire\livewire;

  it('can filter global transactions by status', function () {
      $admin = User::factory()->create();
      $admin->assignRole('super-admin');

      AuthorizedTransaction::factory()->create(['status' => 'completed']);
      AuthorizedTransaction::factory()->create(['status' => 'failed']);

      actingAs($admin);

      livewire(\App\Filament\Admin\Resources\GlobalTransactionResource\Pages\ListGlobalTransactions::class)
          ->filterTable('status', 'completed')
          ->assertCanSeeTableRecords(
              AuthorizedTransaction::where('status', 'completed')->get()
          )
          ->assertCanNotSeeTableRecords(
              AuthorizedTransaction::where('status', 'failed')->get()
          );
  });

  it('can filter global transactions by date range', function () {
      $admin = User::factory()->create();
      $admin->assignRole('super-admin');
      actingAs($admin);

      livewire(\App\Filament\Admin\Resources\GlobalTransactionResource\Pages\ListGlobalTransactions::class)
          ->assertSuccessful();
  });
  ```

- [x] **Step 2: Run test to confirm failure**

  Run: `./vendor/bin/pest tests/Filament/Admin/Resources/GlobalTransactionResourceTest.php -v`
  Expected: FAIL — role 'super-admin' not found (confirms permissions seeder is needed)

- [x] **Step 3: Add missing table filters to `GlobalTransactionResource`**

  In `table()`, replace the current filters array with:

  ```php
  ->filters([
      Tables\Filters\SelectFilter::make('status')
          ->options([
              'completed' => 'Completed',
              'pending'   => 'Pending',
              'failed'    => 'Failed',
              'cancelled' => 'Cancelled',
              'expired'   => 'Expired',
          ]),
      Tables\Filters\SelectFilter::make('remark')
          ->label('Transaction Type')
          ->options(fn () => \App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction::query()
              ->distinct()
              ->pluck('remark', 'remark')
              ->toArray()),
      Tables\Filters\Filter::make('created_at')
          ->label('Date Range')
          ->form([
              \Filament\Forms\Components\DatePicker::make('from')->label('From'),
              \Filament\Forms\Components\DatePicker::make('until')->label('Until'),
          ])
          ->query(function (Builder $query, array $data): Builder {
              return $query
                  ->when($data['from'], fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                  ->when($data['until'], fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
          }),
  ])
  ```

  Add the `use Illuminate\Database\Eloquent\Builder;` import at the top.

- [x] **Step 4: Enrich the `ViewGlobalTransaction` infolist with lifecycle timeline**

  Open `app/Filament/Admin/Resources/GlobalTransactionResource/Pages/ViewGlobalTransaction.php` and add an infolist:

  ```php
  public function infolist(Infolist $infolist): Infolist
  {
      return $infolist->schema([
          \Filament\Infolists\Components\Section::make('Transaction Details')->schema([
              \Filament\Infolists\Components\Grid::make(3)->schema([
                  \Filament\Infolists\Components\TextEntry::make('trx')->label('Tx Hash')->copyable(),
                  \Filament\Infolists\Components\TextEntry::make('status')->badge()
                      ->color(fn (string $state): string => match ($state) {
                          'completed' => 'success',
                          'pending'   => 'warning',
                          'failed'    => 'danger',
                          default     => 'secondary',
                      }),
                  \Filament\Infolists\Components\TextEntry::make('remark')->label('Type'),
                  \Filament\Infolists\Components\TextEntry::make('user.name')->label('Customer')
                      ->url(fn ($record) => $record->user_id
                          ? \App\Filament\Admin\Resources\UserResource::getUrl('view', ['record' => $record->user_id])
                          : null),
                  \Filament\Infolists\Components\TextEntry::make('created_at')->label('Initiated At')->dateTime(),
                  \Filament\Infolists\Components\TextEntry::make('updated_at')->label('Last Updated')->dateTime(),
              ]),
          ]),
          \Filament\Infolists\Components\Section::make('Payload & Result')->schema([
              \Filament\Infolists\Components\KeyValueEntry::make('payload')->label('Payload'),
              \Filament\Infolists\Components\KeyValueEntry::make('result')->label('Result'),
          ]),
      ]);
  }
  ```

- [x] **Step 5: Run tests again**

  Run: `./vendor/bin/pest tests/Filament/Admin/Resources/GlobalTransactionResourceTest.php -v`
  Expected: FAIL only on role assignment (will pass after Task 1.2)

- [x] **Step 6: Commit**

  ```bash
  git add app/Filament/Admin/Resources/GlobalTransactionResource.php \
          app/Filament/Admin/Resources/GlobalTransactionResource/ \
          tests/Filament/Admin/Resources/GlobalTransactionResourceTest.php
  git commit -m "feat(admin): enrich global transaction resource with filters and timeline view"
  ```

---

### Task 1.2 — Roles & Permissions Foundation

**Context:** `spatie/laravel-permission` is confirmed installed. No seeder exists yet. This unblocks all permission-guarded features.

**Files:**
- Create: `database/seeders/RolesAndPermissionsSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Create: `tests/Feature/RolesAndPermissionsTest.php`

- [x] **Step 1: Write failing test**

  Create `tests/Feature/RolesAndPermissionsTest.php`:

  ```php
  <?php

  use Spatie\Permission\Models\Role;

  it('creates required back-office roles', function () {
      $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

      expect(Role::where('name', 'super-admin')->exists())->toBeTrue();
      expect(Role::where('name', 'compliance-manager')->exists())->toBeTrue();
      expect(Role::where('name', 'finance-lead')->exists())->toBeTrue();
      expect(Role::where('name', 'operations-l2')->exists())->toBeTrue();
      expect(Role::where('name', 'support-l1')->exists())->toBeTrue();
  });

  it('finance-lead can approve adjustments', function () {
      $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

      $user = \App\Models\User::factory()->create();
      $user->assignRole('finance-lead');

      expect($user->can('approve-adjustments'))->toBeTrue();
      expect($user->can('request-adjustments'))->toBeFalse();
  });

  it('operations-l2 can request but not approve adjustments', function () {
      $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

      $user = \App\Models\User::factory()->create();
      $user->assignRole('operations-l2');

      expect($user->can('request-adjustments'))->toBeTrue();
      expect($user->can('approve-adjustments'))->toBeFalse();
  });
  ```

- [x] **Step 2: Run to confirm failure**

  Run: `./vendor/bin/pest tests/Feature/RolesAndPermissionsTest.php -v`
  Expected: FAIL — seeder class not found

- [x] **Step 3: Create the seeder**

  Create `database/seeders/RolesAndPermissionsSeeder.php`:

  ```php
  <?php

  namespace Database\Seeders;

  use Illuminate\Database\Seeder;
  use Spatie\Permission\Models\Permission;
  use Spatie\Permission\Models\Role;

  class RolesAndPermissionsSeeder extends Seeder
  {
      public function run(): void
      {
          app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

          // Define all permissions
          $permissions = [
              // Adjustment workflow (maker-checker)
              'request-adjustments',
              'approve-adjustments',
              'reject-adjustments',
              'view-adjustments',

              // Account controls
              'freeze-accounts',
              'unfreeze-accounts',
              'view-accounts',
              'view-balances',

              // KYC / Compliance
              'approve-kyc',
              'reject-kyc',
              'view-kyc-documents',

              // Fraud / Risk
              'view-anomalies',
              'resolve-anomalies',
              'flag-fraud',

              // Support
              'create-support-cases',
              'assign-support-cases',
              'resolve-support-cases',
              'view-support-cases',

              // Users
              'view-users',
              'freeze-users',
              'resend-otp',
              'reset-user-password',
              'view-pii',

              // Transactions
              'view-transactions',
              'view-transaction-payload',

              // Platform
              'view-audit-logs',
              'manage-webhooks',
              'manage-api-keys',
              'manage-feature-flags',
          ];

          foreach ($permissions as $permission) {
              Permission::firstOrCreate(['name' => $permission]);
          }

          // Roles + permission assignments
          $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
          $superAdmin->syncPermissions(Permission::all());

          $complianceManager = Role::firstOrCreate(['name' => 'compliance-manager']);
          $complianceManager->syncPermissions([
              'approve-kyc', 'reject-kyc', 'view-kyc-documents',
              'freeze-accounts', 'unfreeze-accounts', 'view-accounts',
              'view-users', 'freeze-users', 'view-pii',
              'view-transactions', 'view-audit-logs',
          ]);

          $financeLead = Role::firstOrCreate(['name' => 'finance-lead']);
          $financeLead->syncPermissions([
              'approve-adjustments', 'reject-adjustments', 'view-adjustments',
              'view-accounts', 'view-balances', 'view-transactions',
              'view-transaction-payload', 'view-audit-logs',
          ]);

          $opsL2 = Role::firstOrCreate(['name' => 'operations-l2']);
          $opsL2->syncPermissions([
              'request-adjustments', 'view-adjustments',
              'view-accounts', 'view-balances',
              'view-users', 'resend-otp', 'reset-user-password',
              'view-transactions', 'view-kyc-documents',
              'create-support-cases', 'view-support-cases',
          ]);

          $supportL1 = Role::firstOrCreate(['name' => 'support-l1']);
          $supportL1->syncPermissions([
              'view-users', 'view-transactions', 'view-accounts',
              'view-support-cases', 'create-support-cases',
              // NOTE: support-l1 does NOT get view-pii or view-transaction-payload
          ]);

          $fraudAnalyst = Role::firstOrCreate(['name' => 'fraud-analyst']);
          $fraudAnalyst->syncPermissions([
              'view-anomalies', 'resolve-anomalies', 'flag-fraud',
              'view-transactions', 'view-transaction-payload',
              'view-users', 'view-accounts', 'view-audit-logs',
          ]);
      }
  }
  ```

- [x] **Step 4: Register seeder in DatabaseSeeder**

  In `database/seeders/DatabaseSeeder.php`, add:
  ```php
  $this->call(RolesAndPermissionsSeeder::class);
  ```

- [x] **Step 5: Run the test**

  Run: `./vendor/bin/pest tests/Feature/RolesAndPermissionsTest.php -v`
  Expected: All 3 tests PASS

- [x] **Step 6: Run full test suite to check for regressions**

  Run: `./vendor/bin/pest --parallel`
  Expected: No new failures

- [x] **Step 7: Commit**

  ```bash
  git add database/seeders/RolesAndPermissionsSeeder.php \
          database/seeders/DatabaseSeeder.php \
          tests/Feature/RolesAndPermissionsTest.php
  git commit -m "feat(auth): add roles and permissions seeder for back-office operator roles"
  ```

---

### Task 1.3 — Wire Adjustment Approval to Event Sourcing

**Context:** `AdjustmentRequestResource` exists with an approve action that has a `// TODO: Dispatch domain event`. This is the single most critical safety gap — approvals currently only update an Eloquent record, not the actual ledger.

**Files:**
- Investigate: `app/Domain/Account/` — look for existing commands
- Create (if missing): `app/Domain/Account/Commands/ProcessManualLedgerAdjustment.php`
- Modify: `app/Filament/Admin/Resources/AdjustmentRequestResource.php`
- Test: `tests/Filament/Admin/Resources/AdjustmentRequestResourceTest.php` *(create)*

- [x] **Step 1: Discover existing Account domain commands**

  Run: `find app/Domain/Account -name "*.php" | head -40`

  Look for: existing `Commands/`, `Aggregates/`, `Actions/` or `Handlers/` directories.
  This determines whether you create a new command or hook into an existing one.

- [x] **Step 2: Write a failing test for the approval flow**

  Create `tests/Filament/Admin/Resources/AdjustmentRequestResourceTest.php`:

  ```php
  <?php

  use App\Domain\Account\Models\AdjustmentRequest;
  use App\Models\User;

  use function Pest\Laravel\actingAs;
  use function Pest\Livewire\livewire;

  it('finance-lead can approve a pending adjustment and it changes status', function () {
      $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

      $financeLead = User::factory()->create();
      $financeLead->assignRole('finance-lead');

      $adjustment = AdjustmentRequest::factory()->create(['status' => 'pending']);

      actingAs($financeLead);

      livewire(\App\Filament\Admin\Resources\AdjustmentRequestResource\Pages\ListAdjustmentRequests::class)
          ->callTableAction('approve', $adjustment)
          ->assertHasNoTableActionErrors();

      expect($adjustment->fresh()->status)->toBe('approved');
      expect($adjustment->fresh()->reviewer_id)->toBe($financeLead->id);
  });

  it('operations-l2 cannot approve adjustments', function () {
      $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

      $opsUser = User::factory()->create();
      $opsUser->assignRole('operations-l2');

      actingAs($opsUser);

      livewire(\App\Filament\Admin\Resources\AdjustmentRequestResource\Pages\ListAdjustmentRequests::class)
          ->assertSuccessful();

      // Ops L2 should not see the approve action at all
      // (enforced by policy/permission in the action's visible() callback)
  });
  ```

- [x] **Step 3: Run test to confirm failure**

  Run: `./vendor/bin/pest tests/Filament/Admin/Resources/AdjustmentRequestResourceTest.php -v`
  Expected: FAIL

- [x] **Step 4: Check if `ProcessManualLedgerAdjustment` command exists**

  Run: `find app/Domain/Account -name "ProcessManualLedgerAdjustment*"`

  **If it exists:** Note the namespace and constructor signature and use it in Step 5.
  **If it does not exist:** Create a minimal command stub:

  ```php
  // app/Domain/Account/Commands/ProcessManualLedgerAdjustment.php
  <?php

  namespace App\Domain\Account\Commands;

  class ProcessManualLedgerAdjustment
  {
      public function __construct(
          public readonly string $accountUuid,
          public readonly float $amount,
          public readonly string $type, // 'credit' | 'debit'
          public readonly string $reason,
          public readonly int $approverId,
          public readonly int $adjustmentRequestId,
      ) {}
  }
  ```

- [x] **Step 5: Update the approve action to dispatch the domain command**

  In `app/Filament/Admin/Resources/AdjustmentRequestResource.php`, replace the approve action:

  ```php
  Tables\Actions\Action::make('approve')
      ->label('Approve')
      ->icon('heroicon-o-check')
      ->color('success')
      ->requiresConfirmation()
      ->modalHeading('Approve Ledger Adjustment')
      ->modalDescription('This will immediately credit or debit the account. This action is irreversible.')
      ->visible(fn (AdjustmentRequest $record) => $record->status === 'pending'
          && auth()->user()->can('approve-adjustments'))
      ->action(function (AdjustmentRequest $record) {
          DB::transaction(function () use ($record) {
              $record->update([
                  'status'      => 'approved',
                  'reviewer_id' => auth()->id(),
                  'reviewed_at' => now(),
              ]);

              // Dispatch the domain command to update the actual ledger via event sourcing
              // The aggregate/handler for this command should emit AccountCredited/AccountDebited
              // and the projector will update the account balance.
              dispatch(new \App\Domain\Account\Commands\ProcessManualLedgerAdjustment(
                  accountUuid: $record->account->uuid,
                  amount: (float) $record->amount,
                  type: $record->type,
                  reason: $record->reason,
                  approverId: auth()->id(),
                  adjustmentRequestId: $record->id,
              ));
          });

          \Filament\Notifications\Notification::make()
              ->title('Adjustment Approved')
              ->body('The ledger adjustment has been approved and queued for processing.')
              ->success()
              ->send();
      }),
  ```

  Add at top of file: `use Illuminate\Support\Facades\DB;`

- [x] **Step 6: Add `visible()` permission guard to reject action too**

  ```php
  ->visible(fn (AdjustmentRequest $record) => $record->status === 'pending'
      && auth()->user()->can('approve-adjustments'))
  ```

- [x] **Step 7: Run tests**

  Run: `./vendor/bin/pest tests/Filament/Admin/Resources/AdjustmentRequestResourceTest.php -v`
  Expected: PASS

- [x] **Step 8: Commit**

  ```bash
  git add app/Filament/Admin/Resources/AdjustmentRequestResource.php \
          app/Domain/Account/Commands/ProcessManualLedgerAdjustment.php \
          tests/Filament/Admin/Resources/AdjustmentRequestResourceTest.php
  git commit -m "feat(finance): wire adjustment approval to domain event dispatch (maker-checker complete)"
  ```

---

## Phase 2: Operational Maturity (W2–W3)

> Support team enablement, case management enrichment, and external integration observability.

### Task 2.1 — Enrich Support Case Resource

**Context:** `SupportCaseResource` and `SupportCase` model exist but have no relation managers for notes or escalation workflow.

**Files:**
- Create: `app/Filament/Admin/Resources/SupportCaseResource/RelationManagers/NotesRelationManager.php`
- Create: `database/migrations/YYYY_create_support_case_notes_table.php`
- Modify: `app/Domain/Support/Models/SupportCase.php`
- Modify: `app/Filament/Admin/Resources/SupportCaseResource.php`
- Test: `tests/Filament/Admin/Resources/SupportCaseResourceTest.php` *(create)*

- [x] **Step 1: Write failing test**

  Create `tests/Filament/Admin/Resources/SupportCaseResourceTest.php`:

  ```php
  <?php

  use App\Domain\Support\Models\SupportCase;
  use App\Models\User;

  use function Pest\Laravel\actingAs;
  use function Pest\Livewire\livewire;

  it('support-l1 can create a support case', function () {
      $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

      $support = User::factory()->create();
      $support->assignRole('support-l1');

      $customer = User::factory()->create();

      actingAs($support);

      livewire(\App\Filament\Admin\Resources\SupportCaseResource\Pages\CreateSupportCase::class)
          ->fillForm([
              'user_id'     => $customer->id,
              'subject'     => 'Failed transfer investigation',
              'description' => 'Customer reports funds deducted but not received.',
              'priority'    => 'high',
              'status'      => 'open',
          ])
          ->call('create')
          ->assertHasNoFormErrors();

      expect(SupportCase::where('subject', 'Failed transfer investigation')->exists())->toBeTrue();
  });

  it('support hub shows open case count badge', function () {
      $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

      SupportCase::factory()->count(3)->create(['status' => 'open']);

      $admin = User::factory()->create();
      $admin->assignRole('super-admin');
      actingAs($admin);

      livewire(\App\Filament\Admin\Resources\SupportCaseResource\Pages\ListSupportCases::class)
          ->assertSuccessful();
  });
  ```

- [x] **Step 2: Run to confirm failure**

  Run: `./vendor/bin/pest tests/Filament/Admin/Resources/SupportCaseResourceTest.php -v`
  Expected: FAIL — factory not found (SupportCase factory likely doesn't exist)

- [x] **Step 3: Create SupportCase factory if missing**

  Run: `php artisan make:factory SupportCaseFactory --model="App\\Domain\\Support\\Models\\SupportCase"`

  Configure it:
  ```php
  public function definition(): array
  {
      return [
          'user_id'     => \App\Models\User::factory(),
          'assigned_to' => null,
          'subject'     => fake()->sentence(),
          'description' => fake()->paragraph(),
          'status'      => fake()->randomElement(['open', 'in_progress', 'resolved']),
          'priority'    => fake()->randomElement(['low', 'medium', 'high', 'urgent']),
      ];
  }
  ```

- [x] **Step 4: Create support case notes migration**

  Run: `php artisan make:migration create_support_case_notes_table`

  ```php
  Schema::create('support_case_notes', function (Blueprint $table) {
      $table->id();
      $table->foreignId('support_case_id')->constrained()->cascadeOnDelete();
      $table->foreignId('author_id')->constrained('users');
      $table->text('body');
      $table->string('visibility')->default('internal'); // 'internal' | 'customer-facing'
      $table->timestamps();
  });
  ```

- [x] **Step 5: Run migration**

  Run: `php artisan migrate`

- [x] **Step 6: Create SupportCaseNote model**

  Create `app/Domain/Support/Models/SupportCaseNote.php`:

  ```php
  <?php

  namespace App\Domain\Support\Models;

  use App\Models\User;
  use Illuminate\Database\Eloquent\Model;
  use Illuminate\Database\Eloquent\Relations\BelongsTo;

  class SupportCaseNote extends Model
  {
      protected $fillable = ['support_case_id', 'author_id', 'body', 'visibility'];

      public function author(): BelongsTo
      {
          return $this->belongsTo(User::class, 'author_id');
      }

      public function supportCase(): BelongsTo
      {
          return $this->belongsTo(SupportCase::class);
      }
  }
  ```

- [x] **Step 7: Add `notes()` relation to SupportCase model**

  In `app/Domain/Support/Models/SupportCase.php`, add:

  ```php
  use Illuminate\Database\Eloquent\Relations\HasMany;

  public function notes(): HasMany
  {
      return $this->hasMany(SupportCaseNote::class);
  }
  ```

- [x] **Step 8: Create NotesRelationManager**

  Run: `php artisan make:filament-relation-manager SupportCaseResource notes body --panel=admin`

  Update the generated class to `app/Filament/Admin/Resources/SupportCaseResource/RelationManagers/NotesRelationManager.php`:

  ```php
  public function form(Form $form): Form
  {
      return $form->schema([
          \Filament\Forms\Components\Textarea::make('body')
              ->label('Note')
              ->required()
              ->columnSpanFull(),
          \Filament\Forms\Components\Select::make('visibility')
              ->options(['internal' => 'Internal Only', 'customer-facing' => 'Customer Facing'])
              ->default('internal')
              ->required(),
      ]);
  }

  public function table(Table $table): Table
  {
      return $table->columns([
          \Filament\Tables\Columns\TextColumn::make('author.name')->label('Author'),
          \Filament\Tables\Columns\TextColumn::make('body')->limit(80),
          \Filament\Tables\Columns\BadgeColumn::make('visibility')
              ->colors(['warning' => 'internal', 'success' => 'customer-facing']),
          \Filament\Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
      ])->headerActions([
          \Filament\Tables\Actions\CreateAction::make()
              ->mutateFormDataUsing(fn (array $data) => array_merge($data, [
                  'author_id' => auth()->id(),
              ])),
      ])->actions([
          \Filament\Tables\Actions\DeleteAction::make(),
      ]);
  }
  ```

- [x] **Step 9: Register NotesRelationManager in SupportCaseResource**

  In `app/Filament/Admin/Resources/SupportCaseResource.php`:

  ```php
  public static function getRelations(): array
  {
      return [
          \App\Filament\Admin\Resources\SupportCaseResource\RelationManagers\NotesRelationManager::class,
      ];
  }
  ```

- [x] **Step 10: Run tests**

  Run: `./vendor/bin/pest tests/Filament/Admin/Resources/SupportCaseResourceTest.php -v`
  Expected: PASS

- [x] **Step 11: Commit**

  ```bash
  git add app/Domain/Support/ \
          app/Filament/Admin/Resources/SupportCaseResource/ \
          database/migrations/*support_case_notes* \
          tests/Filament/Admin/Resources/SupportCaseResourceTest.php
  git commit -m "feat(support): add case notes relation manager and SupportCaseNote model"
  ```

---

### Task 2.2 — KYC Resource Proper Compliance Grouping & Enrichment

**Context:** `KycDocumentResource` is currently in `TrustCert` group. It needs to be in `Compliance` and enriched with workflow actions.

**Files:**
- Modify: `app/Filament/Admin/Resources/KycDocumentResource.php`
- Test: `tests/Filament/Admin/Resources/KycDocumentResourceTest.php` *(create)*

- [x] **Step 1: Write failing test**

  Create `tests/Filament/Admin/Resources/KycDocumentResourceTest.php`:

  ```php
  <?php

  use App\Models\User;

  use function Pest\Laravel\actingAs;
  use function Pest\Livewire\livewire;

  it('compliance-manager can access kyc documents in Compliance group', function () {
      $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

      $compliance = User::factory()->create();
      $compliance->assignRole('compliance-manager');
      actingAs($compliance);

      livewire(\App\Filament\Admin\Resources\KycDocumentResource\Pages\ListKycDocuments::class)
          ->assertSuccessful();
  });
  ```

- [x] **Step 2: Run to confirm failure**

  Run: `./vendor/bin/pest tests/Filament/Admin/Resources/KycDocumentResourceTest.php -v`

- [x] **Step 3: Update KycDocumentResource navigation group**

  In `app/Filament/Admin/Resources/KycDocumentResource.php`:
  ```php
  protected static ?string $navigationGroup = 'Compliance';
  ```

- [x] **Step 4: View the current KycDocumentResource fully**

  Run the test again and inspect the resource. If it has no approve/reject actions, add them using the same pattern as `UserResource`'s bulk KYC actions.

- [x] **Step 5: Run tests**

  Run: `./vendor/bin/pest tests/Filament/Admin/Resources/KycDocumentResourceTest.php -v`
  Expected: PASS

- [x] **Step 6: Commit**

  ```bash
  git add app/Filament/Admin/Resources/KycDocumentResource.php \
          tests/Filament/Admin/Resources/KycDocumentResourceTest.php
  git commit -m "feat(compliance): move KYC documents to Compliance nav group and enrich"
  ```

---

### Task 2.3 — MoMo & External Integration Exception Dashboard

**Context:** MTN MoMo `MtnMomoTransactionResource` exists but there's no dashboard surfacing failed/stuck transactions to ops. This is a P2 launch item but a real operational blocker.

**Files:**
- Create: `app/Filament/Admin/Pages/ExceptionsDashboard.php`
- Create: `app/Filament/Admin/Widgets/FailedMomoTransactionsWidget.php`
- Create: `app/Filament/Admin/Widgets/PendingAdjustmentsWidget.php`
- Test: `tests/Filament/Admin/Pages/ExceptionsDashboardTest.php` *(create)*

- [x] **Step 1: Write failing test**

  Create `tests/Filament/Admin/Pages/ExceptionsDashboardTest.php`:

  ```php
  <?php

  use App\Models\User;

  use function Pest\Laravel\actingAs;
  use function Pest\Livewire\livewire;

  it('exceptions dashboard is accessible to operations-l2', function () {
      $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

      $ops = User::factory()->create();
      $ops->assignRole('operations-l2');
      actingAs($ops);

      livewire(\App\Filament\Admin\Pages\ExceptionsDashboard::class)
          ->assertSuccessful();
  });
  ```

- [x] **Step 2: Run to confirm failure**

  Run: `./vendor/bin/pest tests/Filament/Admin/Pages/ExceptionsDashboardTest.php -v`
  Expected: FAIL — class not found

- [x] **Step 3: Create ExceptionsDashboard page**

  Run: `php artisan make:filament-page ExceptionsDashboard --panel=admin`

  Update `app/Filament/Admin/Pages/ExceptionsDashboard.php`:

  ```php
  <?php

  namespace App\Filament\Admin\Pages;

  use Filament\Pages\Page;

  class ExceptionsDashboard extends Page
  {
      protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';
      protected static ?string $navigationLabel = 'Exception Queue';
      protected static ?string $navigationGroup = 'Finance & Reconciliation';
      protected static ?int $navigationSort = 10;
      protected static string $view = 'filament.admin.pages.exceptions-dashboard';

      public static function getNavigationBadge(): ?string
      {
          $failedMomo = \App\Domain\MtnMomo\Models\MtnMomoTransaction::where('status', 'failed')->count();
          $pendingAdj = \App\Domain\Account\Models\AdjustmentRequest::where('status', 'pending')->count();
          $total = $failedMomo + $pendingAdj;
          return $total > 0 ? (string) $total : null;
      }

      public static function getNavigationBadgeColor(): ?string
      {
          return 'danger';
      }

      protected function getHeaderWidgets(): array
      {
          return [
              \App\Filament\Admin\Widgets\FailedMomoTransactionsWidget::class,
              \App\Filament\Admin\Widgets\PendingAdjustmentsWidget::class,
          ];
      }
  }
  ```

- [x] **Step 4: Create the Blade view**

  Create `resources/views/filament/admin/pages/exceptions-dashboard.blade.php`:

  ```blade
  <x-filament-panels::page>
      <x-filament-widgets::widgets
          :columns="$this->getColumns()"
          :widgets="$this->getHeaderWidgets()"
      />
  </x-filament-panels::page>
  ```

- [x] **Step 5: Create FailedMomoTransactionsWidget**

  Run: `php artisan make:filament-widget FailedMomoTransactionsWidget --panel=admin --table`

  In `app/Filament/Admin/Widgets/FailedMomoTransactionsWidget.php`:

  ```php
  protected static ?string $heading = 'Failed MTN MoMo Transactions';

  protected function getTableQuery(): Builder
  {
      // Adjust model path based on what the MtnMomo domain actually has
      return \App\Domain\MtnMomo\Models\MtnMomoTransaction::query()
          ->where('status', 'failed')
          ->latest();
  }

  protected function getTableColumns(): array
  {
      return [
          \Filament\Tables\Columns\TextColumn::make('reference')->copyable(),
          \Filament\Tables\Columns\TextColumn::make('amount')->money('SZL'),
          \Filament\Tables\Columns\TextColumn::make('status')->badge(),
          \Filament\Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
      ];
  }
  ```

  > **Note:** Verify the actual MtnMomoTransaction model path by running:
  > `find app/Domain/MtnMomo -name "*.php"`
  > Adjust the model import accordingly.

- [x] **Step 6: Create PendingAdjustmentsWidget**

  Run: `php artisan make:filament-widget PendingAdjustmentsWidget --panel=admin --table`

  Configure it to query `AdjustmentRequest::where('status', 'pending')`.

- [x] **Step 7: Run tests**

  Run: `./vendor/bin/pest tests/Filament/Admin/Pages/ExceptionsDashboardTest.php -v`
  Expected: PASS

- [x] **Step 8: Commit**

  ```bash
  git add app/Filament/Admin/Pages/ExceptionsDashboard.php \
          app/Filament/Admin/Widgets/ \
          resources/views/filament/admin/pages/ \
          tests/Filament/Admin/Pages/ExceptionsDashboardTest.php
  git commit -m "feat(admin): add exceptions dashboard with MoMo and pending adjustment widgets"
  ```

---

## Phase 3: Expansion & Future Modules (W4+)

> These are valuable but not launch-blocking. Execute after Phase 1 & 2 are stable.

### Task 3.1 — Account Freeze Action from AccountResource

**Context:** `UserResource` has freeze/unfreeze on the *user*, but `AccountResource` should also allow freezing individual *wallet accounts* — important for compliance holds on specific accounts while leaving others active.

**Files:**
- Modify: `app/Filament/Admin/Resources/AccountResource/Pages/ViewAccount.php`
- Test: `tests/Filament/Admin/Resources/AccountResourceTest.php`

- [x] **Add `Freeze Wallet` and `Unfreeze Wallet` header actions**

  These should use a `reason` form field and dispatch a domain event (similar to User freeze pattern already in `UserResource`).

- [x] **Commit** after tests pass

---

### Task 3.2 — Payment Intent (Request Money) Enrichment

**Context:** `PaymentIntentResource` exists in wrong group and has no lifecycle actions.

**Files:**
- Modify: `app/Filament/Admin/Resources/PaymentIntentResource.php`

- [x] **Fix navigation group to `Transactions`** (already done in Phase 0)
- [x] **Add `Cancel Payment Link` action** — for expired/disputed payment links
- [x] **Commit** after tests pass

---

### Task 3.3 — Dashboard KPI Widgets

**Context:** No operational dashboard widgets exist beyond the default Filament dashboard.

**Files:**
- Create: `app/Filament/Admin/Widgets/TransactionVolumeWidget.php`
- Create: `app/Filament/Admin/Widgets/OpenCasesWidget.php`
- Create: `app/Filament/Admin/Widgets/PendingKycWidget.php`

- [x] **Create StatsOverviewWidget** showing:
  - Total transactions today
  - Failed transactions today
  - Open support cases
  - KYC pending reviews
  - Pending adjustments

- [x] **Register widgets in AdminPanelProvider or Dashboard page**

- [x] **Commit** after tests pass

---

### Task 3.4 — Reward & Growth Resources Regrouping

**Context:** `RewardProfileResource`, `RewardQuestResource`, `RewardShopItemResource` are in `Banking` group. They should be in `Growth & Rewards`.

**Files:**
- Modify: `app/Filament/Admin/Resources/RewardProfileResource.php`
- Modify: `app/Filament/Admin/Resources/RewardQuestResource.php`
- Modify: `app/Filament/Admin/Resources/RewardShopItemResource.php`

- [x] **Change `$navigationGroup` to `'Growth & Rewards'`** in each file
- [x] **Commit**

---

## Testing Runbook

After completing each phase, run these verification commands:

```bash
# Full test suite
./vendor/bin/pest --parallel

# Static analysis
XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=2G

# Code style
./vendor/bin/php-cs-fixer fix

# Manual smoke tests
# 1. Visit /admin and verify all nav groups show correct items
# 2. Create an AdjustmentRequest as ops-l2, approve as finance-lead
# 3. Create a SupportCase, add a note, verify note appears in relation manager
# 4. Verify GlobalTransactionResource date filter returns correct results
# 5. Verify ExceptionsDashboard badge count reflects actual failed records
```

---

## Progress Tracker

Use this to track phase completion across sessions:

| Phase | Task | Status | Notes |
|---|---|---|---|
| **0** | 0.1 Fix navigation groups | `✅` | Completed |
| **1** | 1.1 Enrich GlobalTransactionResource | `✅` | Completed |
| **1** | 1.2 Roles & Permissions seeder | `✅` | Completed |
| **1** | 1.3 Wire adjustment approval to event sourcing | `✅` | Completed |
| **2** | 2.1 SupportCase notes relation manager | `✅` | Completed |
| **2** | 2.2 KYC resource enrichment | `✅` | Completed |
| **2** | 2.3 Exceptions Dashboard | `✅` | Completed |
| **3** | 3.1 Account freeze from AccountResource | `✅` | Completed |
| **3** | 3.2 PaymentIntent enrichment | `✅` | Completed |
| **3** | 3.3 Dashboard KPI widgets | `✅` | Completed |
| **3** | 3.4 Reward resources regrouping | `✅` | Completed |
