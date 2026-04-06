# MaphaPay Back Office — Master Roadmap: Phases 4–10

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` or `superpowers:executing-plans` to implement tasks. Steps use checkbox (`- [ ]`) syntax for tracking. Mark tasks `[x]` as you complete them. Do NOT skip ahead — each phase has dependencies on the previous.

> **Prerequisite:** Phases 0–3 of `2026-04-06-maphapay-backoffice-modernization.md` are complete.

**Strategy:** Security → Auditability → Transaction Integrity → Product Parity → Platform Resilience → Regulatory Automation.

**Tech Stack:** PHP 8.4, Laravel 12, Filament v3, Spatie Event Sourcing v7.7+, Spatie Laravel Permission, Pest PHP.

---

## Gap Analysis Reference (What Phases 0–3 Did NOT Cover)

This section is read-only context for agents. Do not modify it.

### Critical Gaps Driving Phase Sequencing

| Gap | Risk Level | Addressed In |
|-----|-----------|-------------|
| PII visible to `support-l1` (phone/email/national_id unmasked) | 🔴 Legal/Security | Phase 4 |
| No Customer 360 tabbed view (Transactions + KYC + Cases + Audit) | 🔴 Operational | Phase 4 |
| No payout/cash-out approval queue (large disbursements auto-approve) | 🔴 Financial Integrity | Phase 5 |
| MtnMomo retry/refund actions missing | 🔴 Operational | Phase 5 |
| `AnomalyDetectionResource` is a passive viewer — no triage workflow | 🟠 Risk | Phase 7 |
| No reconciliation trigger or export | 🟠 Financial Close | Phase 8 |
| `CardIssuance` (MCard), `GroupSavings`, `SocialMoney` have zero admin UI | 🟠 Product Parity | Phase 6 |
| `ProjectorHealthController` exists but no Filament surface | 🟠 Platform Resilience | Phase 9 |
| `RegTech`/`Regulatory` domains exposed nowhere | 🟡 Compliance Obligation | Phase 10 |
| User login history / IP tracking not surfaced | 🟡 Support | Phase 4 |
| System broadcast notifications (Email/SMS/Push) missing | 🟡 Operations | Phase 9 |
| `AdjustmentRequest` approval has no file attachment or Finance notification | 🟡 Auditability | Phase 4 |

### Domains With Zero Filament Resources

`CardIssuance`, `GroupSavings`, `SocialMoney`, `Ramp`/Banners, `Referral`, `Monitoring`, `RegTech`, `Batch`, `X402`/`MachinePay`, `Contact`, `Privacy`

---

## Phase 4 — Customer 360 Completeness & Operator Safety

**Theme:** "Safe Operator Surfaces"
**Why this phase matters:** This is a live legal/security risk. `support-l1` operators currently see raw PII (phone, email, national_id). There is also no structured Customer 360 view, so support agents hunt through multiple resources to help a single customer.

### Task 4.1 — PII Masking for `support-l1` Role

**Files:**
- Create: `app/Filament/Admin/Concerns/MasksPii.php`
- Modify: `app/Filament/Admin/Resources/UserResource.php`
- Modify: `app/Filament/Admin/Resources/GlobalTransactionResource.php`
- Test: `tests/Feature/Filament/PiiMaskingTest.php`

- [ ] **Step 1: Write failing test**

  Create `tests/Feature/Filament/PiiMaskingTest.php`:

  ```php
  <?php

  use App\Models\User;
  use function Pest\Livewire\livewire;

  it('support-l1 cannot see raw phone number', function () {
      $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

      $support = User::factory()->create();
      $support->assignRole('support-l1');
      $this->actingAs($support);

      $customer = User::factory()->create(['phone' => '+26876123456']);

      livewire(\App\Filament\Admin\Resources\UserResource\Pages\ViewUser::class, ['record' => $customer->id])
          ->assertDontSee('+26876123456')
          ->assertSee('+268****456');
  });

  it('operations-l2 with view-pii can see raw phone number', function () {
      $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

      $ops = User::factory()->create();
      $ops->assignRole('operations-l2');
      $ops->givePermissionTo('view-pii');
      $this->actingAs($ops);

      $customer = User::factory()->create(['phone' => '+26876123456']);

      livewire(\App\Filament\Admin\Resources\UserResource\Pages\ViewUser::class, ['record' => $customer->id])
          ->assertSee('+26876123456');
  });
  ```

- [ ] **Step 2: Create `MasksPii` concern**

  Create `app/Filament/Admin/Concerns/MasksPii.php`:

  ```php
  <?php

  declare(strict_types=1);

  namespace App\Filament\Admin\Concerns;

  trait MasksPii
  {
      protected static function maskPhone(?string $value): string
      {
          if (! $value || auth()->user()?->can('view-pii')) {
              return $value ?? '';
          }
          return substr($value, 0, 4) . '****' . substr($value, -3);
      }

      protected static function maskEmail(?string $value): string
      {
          if (! $value || auth()->user()?->can('view-pii')) {
              return $value ?? '';
          }
          [$local, $domain] = explode('@', $value) + ['', ''];
          return substr($local, 0, 2) . '***@' . $domain;
      }

      protected static function maskNationalId(?string $value): string
      {
          if (! $value || auth()->user()?->can('view-pii')) {
              return $value ?? '';
          }
          return '***-****-' . substr($value, -3);
      }
  }
  ```

- [ ] **Step 3: Apply masking in `UserResource` infolist**

  In `UserResource`, on all `TextEntry` components rendering phone, email, national_id:

  ```php
  use App\Filament\Admin\Concerns\MasksPii;

  // In the infolist schema:
  TextEntry::make('phone')->formatStateUsing(fn ($state) => static::maskPhone($state)),
  TextEntry::make('email')->formatStateUsing(fn ($state) => static::maskEmail($state)),
  TextEntry::make('profile.national_id')->formatStateUsing(fn ($state) => static::maskNationalId($state)),
  ```

  Also hide the `view-pii` banner for tables (UserResource list view should mask phone column too).

- [ ] **Step 4: Run tests**

  `./vendor/bin/pest tests/Feature/Filament/PiiMaskingTest.php -v`
  Expected: PASS

- [ ] **Step 5: Commit**

  ```bash
  git commit -m "feat(security): add PII masking for support-l1 role — phone, email, national_id"
  ```

---

### Task 4.2 — Customer 360 Tabbed InfoList Page

**Files:**
- Modify: `app/Filament/Admin/Resources/UserResource/Pages/ViewUser.php`
- Create: `app/Filament/Admin/Resources/UserResource/RelationManagers/UserAuditLogRelationManager.php`
- Create: `app/Filament/Admin/Resources/UserResource/RelationManagers/UserLoginHistoryRelationManager.php`
- Test: `tests/Feature/Filament/Customer360Test.php`

- [ ] **Step 1: Write failing test**

  ```php
  <?php

  use App\Models\User;
  use function Pest\Livewire\livewire;

  it('customer 360 page loads all tabs for operations-l2', function () {
      $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

      $ops = User::factory()->create();
      $ops->assignRole('operations-l2');
      $this->actingAs($ops);

      $customer = User::factory()->create();

      livewire(\App\Filament\Admin\Resources\UserResource\Pages\ViewUser::class, ['record' => $customer->id])
          ->assertSuccessful()
          ->assertSee('Transactions')
          ->assertSee('KYC Documents')
          ->assertSee('Support Cases')
          ->assertSee('Audit Trail');
  });
  ```

- [ ] **Step 2: Restructure `ViewUser` into tabbed InfoList**

  Wrap the existing infolist schema in `Tabs::make()` with sections:
  - `Overview` tab: Balance, KYC tier, account status, freeze state, Auth Reset macros
  - `Transactions` tab: Embed `TransactionsRelationManager` inline
  - `KYC Documents` tab: Embed `KycDocumentResource` scoped to user
  - `Support Cases` tab: `SupportCasesRelationManager`
  - `Audit Trail` tab: `UserAuditLogRelationManager`
  - `Login History` tab: `UserLoginHistoryRelationManager` (if `Activity` domain has a model)

- [ ] **Step 3: Create `UserAuditLogRelationManager`**

  Relates `User` → `AuditLog` entries where `subject_type = User`. Read-only table with columns: event, description, ip_address, created_at.

- [ ] **Step 4: Add Auth Reset header actions to `ViewUser`**

  ```php
  Action::make('reset2fa')
      ->label('Reset 2FA')
      ->icon('heroicon-o-shield-exclamation')
      ->color('warning')
      ->requiresConfirmation()
      ->form([Textarea::make('reason')->required()])
      ->action(fn (User $record, array $data) => dispatch(new \App\Domain\User\Commands\Revoke2FACommand($record->uuid, $data['reason'], auth()->id())))
      ->visible(fn () => auth()->user()->can('reset-user-password')),

  Action::make('forcePasswordReset')
      ->label('Force Password Reset')
      ->icon('heroicon-o-key')
      ->color('warning')
      ->requiresConfirmation()
      ->action(fn (User $record) => \Illuminate\Support\Facades\Password::sendResetLink(['email' => $record->email]))
      ->visible(fn () => auth()->user()->can('reset-user-password')),
  ```

  > **Note:** Verify the `Revoke2FA` command path by running `find app/Domain/User -name "*2FA*" -o -name "*Passkey*"` first. Adjust namespace if needed.

- [ ] **Step 5: Run tests and commit**

  ```bash
  git commit -m "feat(support): build Customer 360 tabbed view with auth reset macros"
  ```

---

### Task 4.3 — Link Support Cases to Transactions

**Files:**
- Create: `database/migrations/YYYY_add_transaction_links_to_support_cases.php`
- Modify: `app/Domain/Support/Models/SupportCase.php`
- Modify: `app/Filament/Admin/Resources/SupportCaseResource.php`

- [ ] **Step 1: Add nullable FK columns to `support_cases`**

  ```php
  Schema::table('support_cases', function (Blueprint $table) {
      $table->nullableMorphs('linked_subject'); // polymorphic: AuthorizedTransaction or PaymentIntent
      $table->string('transaction_reference')->nullable()->index();
  });
  ```

- [ ] **Step 2: Add relation to `SupportCase` model**

  ```php
  public function linkedSubject(): MorphTo
  {
      return $this->morphTo();
  }
  ```

- [ ] **Step 3: Enrich `SupportCaseResource` form**

  Add to the create/edit form:
  ```php
  Select::make('linked_subject_type')
      ->options(['AuthorizedTransaction' => 'Transaction', 'PaymentIntent' => 'Payment Link'])
      ->reactive(),
  TextInput::make('transaction_reference')
      ->label('Transaction Reference / Hash')
      ->helperText('Paste the transaction hash or payment intent ID'),
  ```

- [ ] **Step 4: Commit**

  ```bash
  git commit -m "feat(support): link support cases to transactions and payment intents"
  ```

---

### Task 4.4 — Enrich Adjustment Request with Attachment + Finance Notification

**Files:**
- Modify: `app/Filament/Admin/Resources/AccountResource/Pages/ViewAccount.php`
- Create: `database/migrations/YYYY_add_attachment_to_adjustment_requests.php`

- [ ] **Step 1: Add `attachment_path` column to `adjustment_requests`**

  ```php
  Schema::table('adjustment_requests', function (Blueprint $table) {
      $table->string('attachment_path')->nullable();
  });
  ```

- [ ] **Step 2: Add `FileUpload` to the request adjustment action form**

  ```php
  FileUpload::make('attachment')
      ->label('Supporting Document')
      ->disk('private')
      ->directory('adjustment-attachments')
      ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
      ->nullable(),
  ```

- [ ] **Step 3: Dispatch notification to `finance-lead` users after creating request**

  ```php
  // After AdjustmentTicket::create([...]):
  $financeLeads = \App\Models\User::role('finance-lead')->get();
  foreach ($financeLeads as $lead) {
      \Filament\Notifications\Notification::make()
          ->title('New Adjustment Request Pending')
          ->body("A ledger adjustment for account #{$record->id} requires your approval.")
          ->warning()
          ->actions([
              \Filament\Notifications\Actions\Action::make('review')
                  ->label('Review')
                  ->url(AdjustmentRequestResource::getUrl('index')),
          ])
          ->sendToDatabase($lead);
  }
  ```

- [ ] **Step 4: Commit**

  ```bash
  git commit -m "feat(finance): add attachment support and Finance notification to adjustment requests"
  ```

---

## Phase 5 — Deposit/Withdrawal Approval Queues & Payout Controls

**Theme:** "Financial Operations Command Center"
**Why this phase matters:** Large payouts and top-ups currently bypass any human checkpoint. This replicates the safety of the legacy `CashInController`/`CashOutController` approval queues using maker-checker controls already established in Phase 1.

### Task 5.1 — Payout Approval Queue Page

**Files:**
- Create: `app/Filament/Admin/Pages/PayoutApprovalQueue.php`
- Create: `app/Filament/Admin/Widgets/PendingPayoutsWidget.php`
- Test: `tests/Feature/Filament/PayoutApprovalQueueTest.php`

- [ ] **Step 1: Discover payout/disbursement model**

  Run: `find app/Domain/MobilePayment app/Domain/MtnMomo app/Domain/Payment -name "*.php" | head -30`

  Identify the model representing a pending payout (could be `MobilePayment`, `Payout`, `Disbursement`). Note the model path and `status` field values.

- [ ] **Step 2: Write failing test**

  ```php
  it('payout approval queue is accessible to finance-lead', function () {
      $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
      $finance = User::factory()->create();
      $finance->assignRole('finance-lead');
      $this->actingAs($finance);

      livewire(\App\Filament\Admin\Pages\PayoutApprovalQueue::class)
          ->assertSuccessful();
  });

  it('support-l1 cannot access payout approval queue', function () {
      $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
      $support = User::factory()->create();
      $support->assignRole('support-l1');
      $this->actingAs($support);

      livewire(\App\Filament\Admin\Pages\PayoutApprovalQueue::class)
          ->assertForbidden();
  });
  ```

- [ ] **Step 3: Create `PayoutApprovalQueue` page**

  ```php
  protected static ?string $navigationGroup = 'Finance & Reconciliation';
  protected static ?string $navigationLabel = 'Payout Queue';
  protected static ?string $navigationIcon = 'heroicon-o-queue-list';

  public static function canAccess(): bool
  {
      return auth()->user()->can('approve-adjustments');
  }

  public static function getNavigationBadge(): ?string
  {
      // Query pending high-value payouts — adjust model path after Step 1
      $count = \App\Domain\MtnMomo\Models\MtnMomoTransaction::where('status', 'pending')->count();
      return $count > 0 ? (string) $count : null;
  }
  ```

- [ ] **Step 4: Create `PendingPayoutsWidget` table widget**

  - Query: pending disbursements above a threshold (e.g. > 500 SZL)
  - Columns: reference, amount, user.name, type, created_at, status badge
  - Actions: `Approve` (requires reason, permission: `approve-adjustments`), `Hold`, `Reject`
  - Each action must dispatch the appropriate domain command and record the `reviewer_id`

- [ ] **Step 5: Register widget in page and commit**

  ```bash
  git commit -m "feat(finance): add payout approval queue page with pending payouts widget"
  ```

---

### Task 5.2 — MtnMomoTransactionResource Retry & Refund Actions

**Files:**
- Modify: `app/Filament/Admin/Resources/MtnMomoTransactionResource.php`
- Investigate: `app/Domain/MtnMomo/` for retry/refund commands
- Test: `tests/Feature/Filament/MtnMomoResourceTest.php`

- [ ] **Step 1: Discover MtnMomo domain commands**

  Run: `find app/Domain/MtnMomo -name "*.php" | head -40`

  Look for: `Commands/`, `Actions/`, or `Aggregates/`. Note any existing retry or refund command classes.

- [ ] **Step 2: Write failing test**

  ```php
  it('operations-l2 can see retry action on failed momo transaction', function () {
      $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
      $ops = User::factory()->create();
      $ops->assignRole('operations-l2');
      $this->actingAs($ops);

      // Create a failed momo transaction (adjust factory/model path after Step 1)
      $tx = \App\Domain\MtnMomo\Models\MtnMomoTransaction::factory()->create(['status' => 'failed']);

      livewire(\App\Filament\Admin\Resources\MtnMomoTransactionResource\Pages\ListMtnMomoTransactions::class)
          ->assertTableActionExists('retry', $tx);
  });
  ```

- [ ] **Step 3: Add `Retry` action to `MtnMomoTransactionResource`**

  ```php
  Tables\Actions\Action::make('retry')
      ->label('Retry')
      ->icon('heroicon-o-arrow-path')
      ->color('warning')
      ->requiresConfirmation()
      ->visible(fn ($record) => $record->status === 'failed' && auth()->user()->can('view-transactions'))
      ->action(function ($record) {
          // Dispatch retry command — adjust class path after Step 1
          dispatch(new \App\Domain\MtnMomo\Commands\RetryMtnMomoTransaction($record->uuid));
          Notification::make()->title('Retry queued')->success()->send();
      }),
  ```

- [ ] **Step 4: Add `Mark as Refunded` action**

  ```php
  Tables\Actions\Action::make('markRefunded')
      ->label('Mark Refunded')
      ->icon('heroicon-o-receipt-refund')
      ->color('danger')
      ->requiresConfirmation()
      ->form([Textarea::make('reason')->required()->minLength(10)])
      ->visible(fn ($record) => in_array($record->status, ['failed', 'pending'])
          && auth()->user()->can('approve-adjustments'))
      ->action(function ($record, array $data) {
          $record->update(['status' => 'refunded', 'notes' => $data['reason']]);
          Notification::make()->title('Marked as refunded')->success()->send();
      }),
  ```

- [ ] **Step 5: Run tests and commit**

  ```bash
  git commit -m "feat(momo): add retry and refund actions to MtnMomoTransactionResource"
  ```

---

### Task 5.3 — Linked Wallet Management in AccountResource

**Files:**
- Create: `app/Filament/Admin/Resources/AccountResource/RelationManagers/LinkedWalletsRelationManager.php`
- Modify: `app/Filament/Admin/Resources/AccountResource.php`

- [ ] **Step 1: Discover linked wallet model**

  Run: `find app/Domain/MobilePayment app/Domain/Mobile -name "*.php" | head -30`

  Identify the model representing a linked MoMo/bank wallet (likely `LinkedWallet` or `MobilePaymentProfile`).

- [ ] **Step 2: Create `LinkedWalletsRelationManager`**

  Table columns: provider (MTN/bank), account_number (masked), status, linked_at
  Actions:
  - `Unlink` (requires reason, visible to `compliance-manager` and `operations-l2`)
  - `View Sync Status` (read-only modal showing last sync time, error message if any)

- [ ] **Step 3: Register in `AccountResource::getRelations()`**

- [ ] **Step 4: Commit**

  ```bash
  git commit -m "feat(wallets): add linked wallet management relation manager to AccountResource"
  ```

---

## Phase 6 — MCard, Group Savings & Social Moderation

**Theme:** "Missing Product Verticals"
**Why this phase matters:** Three first-class mobile app features have zero admin surface. Customer support for MCard blocks, stokvel disputes, and social money flags is currently impossible.

### Task 6.1 — CardIssuanceResource (MCard Management)

**Files:**
- Create: `app/Filament/Admin/Resources/CardIssuanceResource.php`
- Investigate: `app/Domain/CardIssuance/` for models
- Test: `tests/Feature/Filament/CardIssuanceResourceTest.php`

- [ ] **Step 1: Discover CardIssuance domain models**

  Run: `find app/Domain/CardIssuance -name "*.php" | head -30`

- [ ] **Step 2: Write failing test**

  ```php
  it('compliance-manager can access card issuance resource', function () {
      $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
      $compliance = User::factory()->create();
      $compliance->assignRole('compliance-manager');
      $this->actingAs($compliance);

      livewire(\App\Filament\Admin\Resources\CardIssuanceResource\Pages\ListCardIssuances::class)
          ->assertSuccessful();
  });
  ```

- [ ] **Step 3: Create `CardIssuanceResource`**

  Navigation group: `Wallets & Ledgers`
  Table columns: masked card number (last 4 digits), status badge, user.name (linked), issued_at, expires_at
  Actions:
  - `Block Card` — requires reason, dispatches `BlockCardCommand`, visible to `compliance-manager`
  - `Re-issue Card` — requires confirmation + reason, dispatches `ReissueCardCommand`, visible to `compliance-manager`
  - `View Transactions` — links to GlobalTransactionResource filtered by card

- [ ] **Step 4: Add `manage-cards` permission to seeder**

  In `RolesAndPermissionsSeeder`, add `'manage-cards'` to permissions list and assign to `compliance-manager` and `operations-l2`.

- [ ] **Step 5: Commit**

  ```bash
  git commit -m "feat(cards): add CardIssuanceResource with block and re-issue actions"
  ```

---

### Task 6.2 — GroupSavingsResource (Stokvel Moderation)

**Files:**
- Create: `app/Filament/Admin/Resources/GroupSavingsResource.php`
- Investigate: `app/Domain/GroupSavings/` for models
- Test: `tests/Feature/Filament/GroupSavingsResourceTest.php`

- [ ] **Step 1: Discover GroupSavings models**

  Run: `find app/Domain/GroupSavings -name "*.php" | head -40`

  Identify: group model, member model, contribution model.

- [ ] **Step 2: Create `GroupSavingsResource`**

  Navigation group: `Wallets & Ledgers`
  Table columns: group name, member count, total balance, status, created_at
  `ViewGroupSavings` InfoList tabs:
  - *Overview*: group details, admin, goal amount
  - *Members*: relation manager with member list, `Remove Member` action (reason required)
  - *Contributions*: read-only contribution history
  Actions:
  - `Freeze Group` — requires reason, dispatches freeze command
  - `Disburse Funds` — maker-checker: Operations L2 requests, Finance Lead approves

- [ ] **Step 3: Add `manage-group-savings` permission to seeder**

- [ ] **Step 4: Commit**

  ```bash
  git commit -m "feat(savings): add GroupSavingsResource with member management and freeze actions"
  ```

---

### Task 6.3 — SocialMoneyResource (Moderation & Audit)

**Files:**
- Create: `app/Filament/Admin/Resources/SocialMoneyResource.php`
- Investigate: `app/Domain/SocialMoney/` for models

- [ ] **Step 1: Discover SocialMoney models**

  Run: `find app/Domain/SocialMoney -name "*.php" | head -30`

- [ ] **Step 2: Create `SocialMoneyResource`** (read-heavy)

  Navigation group: `Support Hub`
  Read-only audit view of social transactions and attached notes/messages
  Table columns: sender.name, recipient.name, amount, message (truncated), created_at, flagged badge
  Actions:
  - `Flag for Compliance Review` — sets a `flagged` boolean, creates an `AnomalyDetection` record
  - `Block Social Profile` — requires reason, dispatches domain command, visible to `compliance-manager`

- [ ] **Step 3: Add `moderate-social` permission to seeder, assign to `compliance-manager`**

- [ ] **Step 4: Commit**

  ```bash
  git commit -m "feat(social): add SocialMoneyResource with flag and block actions for compliance"
  ```

---

### Task 6.4 — Banners & Ramp Resource

**Files:**
- Create: `app/Filament/Admin/Resources/BannerResource.php`
- Investigate: `app/Domain/Ramp/` or `routes/api.php` for banner model

- [ ] **Step 1: Discover banner/ramp models**

  Run: `find app/Domain/Ramp -name "*.php" 2>/dev/null | head -20`
  Run: `grep -r "Banner" app/Domain --include="*.php" -l`

- [ ] **Step 2: Create `BannerResource`** (full CRUD)

  Navigation group: `Growth & Rewards`
  Fields: title, image (FileUpload), link_url, is_active toggle, display_order, valid_from, valid_until
  Table: shows active/inactive badge, sortable by display_order

- [ ] **Step 3: Add `manage-banners` permission to seeder, assign to `operations-l2`**

- [ ] **Step 4: Add `CommerceExceptionWidget` to ExceptionsDashboard**

  Widget shows failed utility/airtime purchases with `Retry` and `Mark Refunded` actions.
  Query the `Commerce` domain for failed orders — run `find app/Domain/Commerce -name "*.php" | head -20` to identify the model.

- [ ] **Step 5: Commit**

  ```bash
  git commit -m "feat(growth): add BannerResource and commerce exception widget"
  ```

---

## Phase 7 — Advanced Risk Engine & Fraud Triage Workflows

**Theme:** "Advanced Risk Engine"
**Why this phase matters:** The risk team is currently flying blind. `AnomalyDetectionResource` is a read-only list. Without triage workflows, anomalies are never resolved, creating audit liability and preventing the risk team from demonstrating due diligence.

### Task 7.1 — Anomaly Triage State Machine

**Files:**
- Create: `database/migrations/YYYY_add_triage_fields_to_anomaly_detections.php`
- Modify: `app/Domain/Fraud/Models/AnomalyDetection.php` (verify path: `find app/Domain/Fraud -name "AnomalyDetection*"`)
- Modify: `app/Filament/Admin/Resources/AnomalyDetectionResource.php`
- Test: `tests/Feature/Filament/AnomalyTriageTest.php`

- [ ] **Step 1: Add triage columns to anomaly_detections**

  ```php
  Schema::table('anomaly_detections', function (Blueprint $table) {
      $table->string('triage_status')->default('detected'); // detected|under_review|escalated|resolved|false_positive
      $table->foreignId('assigned_to')->nullable()->constrained('users');
      $table->foreignId('resolved_by')->nullable()->constrained('users');
      $table->text('resolution_notes')->nullable();
      $table->string('resolution_type')->nullable(); // fraud|false_positive|low_risk
      $table->timestamp('resolved_at')->nullable();
  });
  ```

- [ ] **Step 2: Write failing test**

  ```php
  it('fraud-analyst can assign an anomaly to themselves', function () {
      $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
      $analyst = User::factory()->create();
      $analyst->assignRole('fraud-analyst');
      $this->actingAs($analyst);

      $anomaly = \App\Domain\Fraud\Models\AnomalyDetection::factory()->create(['triage_status' => 'detected']);

      livewire(\App\Filament\Admin\Resources\AnomalyDetectionResource\Pages\ListAnomalyDetections::class)
          ->callTableAction('assign', $anomaly, data: ['assigned_to' => $analyst->id])
          ->assertHasNoTableActionErrors();

      expect($anomaly->fresh()->triage_status)->toBe('under_review');
      expect($anomaly->fresh()->assigned_to)->toBe($analyst->id);
  });
  ```

- [ ] **Step 3: Add triage actions to `AnomalyDetectionResource`**

  - `Assign to Analyst` — Select from users with `fraud-analyst` role; sets status to `under_review`
  - `Escalate` — Creates a linked `SupportCase`; sets status to `escalated`; visible when `triage_status = under_review`
  - `Resolve` — Form: `resolution_type` select + `resolution_notes` textarea; sets `resolved_at`; visible to `fraud-analyst`
  - `Mark False Positive` — Quick resolve with `resolution_type = false_positive`

- [ ] **Step 4: Add `triage_status` filter and badge column to list table**

- [ ] **Step 5: Run tests and commit**

  ```bash
  git commit -m "feat(fraud): implement anomaly triage state machine with assign, escalate, resolve actions"
  ```

---

### Task 7.2 — Risk Scoring & Fraud Dashboard Widgets

**Files:**
- Create: `app/Filament/Admin/Widgets/AnomalyTrendWidget.php`
- Create: `app/Filament/Admin/Widgets/FraudResolutionRateWidget.php`

- [ ] **Create `AnomalyTrendWidget`** — StatsOverview showing:
  - Anomalies opened this week
  - Anomalies resolved this week
  - Currently under review
  - False positive rate (resolved_type = false_positive / total resolved)

- [ ] **Register widgets** in `AnomalyDetectionResource`'s list page or a dedicated Risk dashboard page

- [ ] **Commit**

  ```bash
  git commit -m "feat(fraud): add anomaly trend and resolution rate widgets to Risk group"
  ```

---

## Phase 8 — Treasury, Finance Completeness & Reconciliation

**Theme:** "Treasury Operations & Financial Close"
**Why this phase matters:** Finance Leads cannot perform financial close without reconciliation triggers and export. Exchange rate changes currently bypass the audit trail entirely.

### Task 8.1 — Exchange Rate Management (Event-Sourced)

**Files:**
- Modify: `app/Filament/Admin/Resources/ExchangeRateResource.php`
- Investigate: `app/Domain/Exchange/` for rate update commands

- [ ] **Step 1: Discover exchange rate domain**

  Run: `find app/Domain/Exchange -name "*.php" | head -30`

- [ ] **Step 2: Replace direct edit with action-based rate update**

  Remove or restrict the standard `EditAction`. Add:
  ```php
  Tables\Actions\Action::make('setRate')
      ->label('Set New Rate')
      ->icon('heroicon-o-currency-dollar')
      ->color('warning')
      ->requiresConfirmation()
      ->form([
          TextInput::make('rate')->numeric()->required(),
          Textarea::make('reason')->required()->minLength(10),
      ])
      ->visible(fn () => auth()->user()->can('manage-feature-flags')) // reuse or create 'manage-exchange-rates' permission
      ->action(function ($record, array $data) {
          // Dispatch domain command to set rate via event sourcing
          dispatch(new \App\Domain\Exchange\Commands\UpdateExchangeRate(
              $record->uuid ?? $record->id,
              (float) $data['rate'],
              $data['reason'],
              auth()->id(),
          ));
          Notification::make()->title('Rate update queued')->success()->send();
      }),
  ```

- [ ] **Step 3: Add `ExchangeRateFreshnessWidget`** showing age of each rate pair (warn if > 1 hour old)

- [ ] **Step 4: Commit**

  ```bash
  git commit -m "feat(treasury): replace direct exchange rate edit with audited domain command action"
  ```

---

### Task 8.2 — Reconciliation Trigger & Export

**Files:**
- Modify: `app/Filament/Admin/Resources/ReconciliationReportResource.php`
- Investigate: `app/Domain/` for reconciliation job/command

- [ ] **Step 1: Discover reconciliation domain**

  Run: `grep -r "Reconcil" app/Domain --include="*.php" -l | head -10`

- [ ] **Step 2: Add `Run Reconciliation` header action**

  Dispatches reconciliation job. Requires `finance-lead` role.

- [ ] **Step 3: Add `Export CSV` bulk action**

  Uses Laravel Excel or simple CSV export of the filtered report.

- [ ] **Step 4: Create `ReconciliationDiscrepancyWidget`**

  Query: accounts where `projected_balance != actual_balance` (if such a read model exists).
  Display as a warning stat with a link to the affected accounts.

- [ ] **Step 5: Commit**

  ```bash
  git commit -m "feat(finance): add reconciliation trigger, CSV export, and discrepancy widget"
  ```

---

### Task 8.3 — Projector Recovery Workflow

**Files:**
- Modify: `app/Filament/Admin/Resources/AccountResource/Pages/ViewAccount.php`

- [ ] **Step 1: Verify `ProjectorHealthController` endpoint**

  Run: `grep -r "ProjectorHealth" app/ routes/ --include="*.php" -l`

  Note the exact route or controller method for triggering a replay.

- [ ] **Step 2: Add `Replay Projector` header action to `ViewAccount`**

  ```php
  Action::make('replayProjector')
      ->label('Replay Projector')
      ->icon('heroicon-o-arrow-path')
      ->color('danger')
      ->requiresConfirmation()
      ->modalHeading('Replay Account Projector')
      ->modalDescription('This rebuilds this account\'s projected balance from the event stream. Use only if the balance appears incorrect.')
      ->form([Textarea::make('reason')->required()])
      ->action(function (Account $record, array $data) {
          // Call ProjectorHealthController replay endpoint for this account
          // Adjust based on what you find in Step 1
          Notification::make()->title('Projector replay queued')->warning()->send();
      })
      ->visible(fn () => auth()->user()->hasRole('super-admin')),
  ```

- [ ] **Step 3: Commit**

  ```bash
  git commit -m "feat(platform): add projector replay action to AccountResource for super-admins"
  ```

---

## Phase 9 — Platform Health, Projector Observability & Developer Tools

**Theme:** "Platform Observability & Recovery"
**Why this phase matters:** Spatie projector divergence silently serves wrong balances. Without a health dashboard, this is only discovered when a customer complains or a developer checks the DB. This phase makes the platform self-observable.

### Task 9.1 — Projector Health Dashboard

**Files:**
- Create: `app/Filament/Admin/Pages/ProjectorHealthDashboard.php`
- Create: `app/Filament/Admin/Widgets/ProjectorLagWidget.php`
- Create: `app/Filament/Admin/Widgets/EventStreamDepthWidget.php`
- Test: `tests/Feature/Filament/ProjectorHealthDashboardTest.php`

- [ ] **Step 1: Verify `ProjectorHealthController` capabilities**

  Run: `cat app/Http/Controllers/Admin/ProjectorHealthController.php` or equivalent path.
  Note: what data it returns, what endpoints exist for replay/rebuild.

- [ ] **Step 2: Write failing test**

  ```php
  it('projector health dashboard is only accessible to super-admin', function () {
      $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

      $admin = User::factory()->create();
      $admin->assignRole('super-admin');
      $this->actingAs($admin);

      livewire(\App\Filament\Admin\Pages\ProjectorHealthDashboard::class)
          ->assertSuccessful();
  });

  it('finance-lead cannot access projector health dashboard', function () {
      $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

      $finance = User::factory()->create();
      $finance->assignRole('finance-lead');
      $this->actingAs($finance);

      livewire(\App\Filament\Admin\Pages\ProjectorHealthDashboard::class)
          ->assertForbidden();
  });
  ```

- [ ] **Step 3: Create `ProjectorHealthDashboard` page**

  Navigation group: `Platform`, visible only to `super-admin`
  Header widgets:
  - `ProjectorLagWidget` — calls `ProjectorHealthController` for per-projector lag metrics
  - `EventStreamDepthWidget` — shows event count per aggregate type
  - `FailedProjectionsWidget` — lists failed projection jobs with individual `Retry` actions
  Action: `Rebuild All Projectors` — nuclear option, requires double-confirmation + reason

- [ ] **Step 4: Create widgets using data from `ProjectorHealthController`**

- [ ] **Step 5: Run tests and commit**

  ```bash
  git commit -m "feat(platform): add Projector Health Dashboard for super-admin observability"
  ```

---

### Task 9.2 — Feature Flags Resource

**Files:**
- Create: `app/Filament/Admin/Resources/FeatureFlagResource.php`
- Investigate: `app/Domain/` or config for feature flag model

- [ ] **Step 1: Discover feature flag storage**

  Run: `grep -r "FeatureFlag\|feature_flag" app/ --include="*.php" -l | head -10`
  Run: `grep -r "FeatureFlag\|feature_flag" database/ --include="*.php" -l | head -10`

- [ ] **Step 2: Create `FeatureFlagResource`** (if a DB-backed model exists)

  Navigation group: `Platform`, visible to `super-admin`
  Table: flag name, description, is_enabled toggle, updated_by, updated_at
  Actions: Toggle enable/disable with reason field + audit trail
  Permission: `manage-feature-flags` (already seeded)

- [ ] **Step 3: Commit**

  ```bash
  git commit -m "feat(platform): add FeatureFlagResource for runtime flag management"
  ```

---

### Task 9.3 — System Broadcast Notifications

**Files:**
- Create: `app/Filament/Admin/Pages/BroadcastNotificationPage.php`

- [ ] **Step 1: Create broadcast notification page**

  Navigation group: `Platform` (or `Notifications` if that group exists)
  Form fields:
  - `channel` (multi-select): Email, SMS, Push
  - `audience` (select): Single User (search), Role Group, All Active Users
  - `user_id` (visible when audience = single user)
  - `role` (visible when audience = role group)
  - `subject` (text)
  - `body` (rich text / textarea)

  Permission: `super-admin` only for broadcast-all; `operations-l2` for single-user notification

  Action: `Send` — requires confirmation for broadcast-all; dispatches notification job

- [ ] **Step 2: Commit**

  ```bash
  git commit -m "feat(platform): add broadcast notification page for admin-initiated user communications"
  ```

---

### Task 9.4 — Referral Fraud Detection Surface

**Files:**
- Create: `app/Filament/Admin/Resources/ReferralResource.php`
- Investigate: `app/Domain/Referral/` for models

- [ ] **Step 1: Discover referral models**

  Run: `find app/Domain/Referral -name "*.php" | head -20`

- [ ] **Step 2: Create `ReferralResource`** (read-heavy with fraud flag action)

  Navigation group: `Growth & Rewards`
  Table: referrer.name, referred.name, reward_amount, status, created_at
  Actions:
  - `Flag as Fraudulent` — disables the referral chain, creates an AnomalyDetection record
  - `View Referral Tree` — shows the chain depth

- [ ] **Step 3: Commit**

  ```bash
  git commit -m "feat(growth): add ReferralResource with fraud flag action"
  ```

---

## Phase 10 — Regulatory Reporting Automation & RegTech

**Theme:** "Regulatory Reporting & AML Compliance"
**Why this phase matters:** As a licensed financial services provider, AML/CTF reporting, SARs, and POPIA/GDPR data subject requests are legal obligations. The `RegTech` and `Regulatory` domains exist but are completely unexposed.

### Task 10.1 — RegTech AML Resource

**Files:**
- Create: `app/Filament/Admin/Resources/AmlScreeningResource.php`
- Investigate: `app/Domain/RegTech/` for models

- [ ] **Step 1: Discover RegTech models**

  Run: `find app/Domain/RegTech -name "*.php" | head -30`

- [ ] **Step 2: Create `AmlScreeningResource`**

  Navigation group: `Compliance`, visible to `compliance-manager` only
  Shows: AML screening results, watchlist hits, PEP/sanctions flags
  Actions:
  - `Submit SAR` — form: description, supporting reference, submission channel; creates a filing record
  - `Clear Flag` — requires reason + confirmation; dispatches `ClearAmlFlagCommand`
  - `Escalate to Regulator` — sends notification to designated compliance lead + creates audit record

- [ ] **Step 3: Add `AmlWatchlistHitsWidget`** to Compliance group showing unreviewed hits

- [ ] **Step 4: Commit**

  ```bash
  git commit -m "feat(compliance): add AML screening resource with SAR submission and flag clearance"
  ```

---

### Task 10.2 — Filing Schedule Enrichment

**Files:**
- Modify: `app/Filament/Admin/Resources/FilingScheduleResource.php`

- [ ] **Step 1: Add `Generate Report` action**

  Dispatches a report generation job based on `filing_type`; stores result as downloadable attachment.

- [ ] **Step 2: Add `Mark Submitted` action**

  Form field: `regulator_reference_number` (required); sets `submitted_at` timestamp.

- [ ] **Step 3: Add `FilingDeadlineWidget`** — shows upcoming filing dates within the next 30 days with RAG status (green/amber/red based on days remaining)

- [ ] **Step 4: Commit**

  ```bash
  git commit -m "feat(regulatory): enrich FilingScheduleResource with report generation and submission tracking"
  ```

---

### Task 10.3 — Audit Log Export (Tamper-Evident)

**Files:**
- Modify: `app/Filament/Admin/Resources/AuditLogResource.php`

- [ ] **Add `Export Audit Trail` header action** to `AuditLogResource`

  Produces a CSV with an appended SHA-256 hash of the full payload for regulatory submission.
  Visible to `compliance-manager` and `super-admin` only.

- [ ] **Commit**

  ```bash
  git commit -m "feat(compliance): add tamper-evident audit log export for regulatory submissions"
  ```

---

### Task 10.4 — Privacy / Data Subject Request Resource

**Files:**
- Create: `app/Filament/Admin/Resources/DataSubjectRequestResource.php`
- Investigate: `app/Domain/Privacy/` for models

- [ ] **Step 1: Discover Privacy domain models**

  Run: `find app/Domain/Privacy -name "*.php" | head -20`

- [ ] **Step 2: Create `DataSubjectRequestResource`**

  Navigation group: `Compliance`
  Tracks POPIA/GDPR data deletion and export requests from users
  Workflow: `received` → `in_review` → `fulfilled` / `rejected`
  Actions:
  - `Fulfill Deletion Request` — dispatches anonymization command; requires `compliance-manager` approval
  - `Fulfill Export Request` — generates ZIP of user data; dispatches to user's email
  - `Reject` — requires reason

- [ ] **Step 3: Commit**

  ```bash
  git commit -m "feat(compliance): add DataSubjectRequestResource for POPIA/GDPR request management"
  ```

---

## Observability Widget Deployment Checklist

These widgets are the long-term health indicators for features across all phases:

| Group | Widget | Phase | Status |
|-------|--------|-------|--------|
| Dashboard | `ProjectorLagWidget` | 9 | `[ ]` |
| Dashboard | `SystemHealthWidget` (Redis/DLQ) | 9 | `[ ]` |
| Finance & Reconciliation | `ReconciliationDiscrepancyWidget` | 8 | `[ ]` |
| Finance & Reconciliation | `ExchangeRateFreshnessWidget` | 8 | `[ ]` |
| Finance & Reconciliation | `PendingPayoutsWidget` | 5 | `[ ]` |
| Risk & Fraud | `AnomalyTrendWidget` | 7 | `[ ]` |
| Risk & Fraud | `FraudResolutionRateWidget` | 7 | `[ ]` |
| Compliance | `AmlWatchlistHitsWidget` | 10 | `[ ]` |
| Compliance | `FilingDeadlineWidget` | 10 | `[ ]` |
| Support Hub | `CaseSLAWidget` | 4 | `[ ]` |
| Transactions | `FailedTransferTrendWidget` | 5 | `[ ]` |
| Platform | `ProjectorHealthDashboard` (full page) | 9 | `[ ]` |
| Growth & Rewards | `ReferralFraudWidget` | 9 | `[ ]` |

---

## Master Progress Tracker

| Phase | Task | Status | Notes |
|-------|------|--------|-------|
| **4** | 4.1 PII masking for support-l1 | `[ ]` | |
| **4** | 4.2 Customer 360 tabbed InfoList | `[ ]` | |
| **4** | 4.3 Link support cases to transactions | `[ ]` | |
| **4** | 4.4 Adjustment attachment + Finance notification | `[ ]` | |
| **5** | 5.1 Payout approval queue page | `[ ]` | |
| **5** | 5.2 MtnMomo retry & refund actions | `[ ]` | |
| **5** | 5.3 Linked wallet management | `[ ]` | |
| **6** | 6.1 CardIssuanceResource (MCard) | `[ ]` | |
| **6** | 6.2 GroupSavingsResource (Stokvel) | `[ ]` | |
| **6** | 6.3 SocialMoneyResource (moderation) | `[ ]` | |
| **6** | 6.4 BannerResource + Commerce exception widget | `[ ]` | |
| **7** | 7.1 Anomaly triage state machine | `[ ]` | |
| **7** | 7.2 Risk scoring & fraud dashboard widgets | `[ ]` | |
| **8** | 8.1 Exchange rate management (event-sourced) | `[ ]` | |
| **8** | 8.2 Reconciliation trigger & export | `[ ]` | |
| **8** | 8.3 Projector recovery workflow | `[ ]` | |
| **9** | 9.1 Projector health dashboard | `[ ]` | |
| **9** | 9.2 Feature flags resource | `[ ]` | |
| **9** | 9.3 System broadcast notifications | `[ ]` | |
| **9** | 9.4 Referral fraud detection surface | `[ ]` | |
| **10** | 10.1 RegTech AML resource + SAR submission | `[ ]` | |
| **10** | 10.2 Filing schedule enrichment | `[ ]` | |
| **10** | 10.3 Audit log tamper-evident export | `[ ]` | |
| **10** | 10.4 Privacy / data subject request resource | `[ ]` | |
