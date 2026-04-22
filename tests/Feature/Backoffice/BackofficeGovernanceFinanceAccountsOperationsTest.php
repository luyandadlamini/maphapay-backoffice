<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\Compliance\Models\AuditLog;
use App\Filament\Admin\Pages\FundManagement\FundAccountPage;
use App\Filament\Admin\Resources\AccountResource;
use App\Filament\Admin\Resources\AccountResource\Pages\ListAccounts;
use App\Filament\Admin\Resources\AccountResource\Pages\ViewAccount;
use App\Filament\Admin\Resources\ReconciliationReportResource;
use App\Models\AdminActionApprovalRequest;
use App\Models\User;
use App\Support\Reconciliation\ReconciliationReportRecord;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel->boot();
});

it('limits finance account, reconciliation, and funding visibility to the finance workspace', function (): void {
    $support = User::factory()->create();
    $support->assignRole('support-l1');
    $this->actingAs($support);

    expect(AccountResource::canViewAny())->toBeFalse()
        ->and(ReconciliationReportResource::canViewAny())->toBeFalse()
        ->and(FundAccountPage::canAccess())->toBeFalse();

    $this->get(AccountResource::getUrl())->assertForbidden();
    $this->get(ReconciliationReportResource::getUrl())->assertForbidden();
    $this->get(FundAccountPage::getUrl())->assertForbidden();

    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    expect(AccountResource::canViewAny())->toBeTrue()
        ->and(ReconciliationReportResource::canViewAny())->toBeTrue()
        ->and(FundAccountPage::canAccess())->toBeTrue();
});

it('submits account deposit requests for approval instead of mutating the balance immediately', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    Asset::query()->updateOrCreate(['code' => 'USD'], [
        'name'      => 'US Dollar',
        'type'      => 'fiat',
        'precision' => 2,
        'is_active' => true,
    ]);

    $account = Account::factory()->zeroBalance()->create();

    livewire(ListAccounts::class)
        ->callTableAction('deposit', $account, data: [
            'amount' => '125.50',
            'reason' => 'Treasury requested a controlled manual credit after settlement review.',
        ])
        ->assertHasNoTableActionErrors();

    expect($account->fresh()?->getBalance('USD'))->toBe(0);

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.accounts.deposit')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->workspace)->toBe('finance')
        ->and($request->status)->toBe('pending')
        ->and($request->requester_id)->toBe($finance->getKey())
        ->and($request->reviewer_id)->toBeNull()
        ->and($request->reason)->toBe('Treasury requested a controlled manual credit after settlement review.')
        ->and($request->payload['operation'] ?? null)->toBe('deposit')
        ->and($request->payload['asset_code'] ?? null)->toBe('USD')
        ->and($request->payload['amount_minor'] ?? null)->toBe(12550)
        ->and($request->payload['account_uuid'] ?? null)->toBe($account->uuid)
        ->and($request->metadata['mode'] ?? null)->toBe('request_approve')
        ->and($request->metadata['requester_email'] ?? null)->toBe($finance->email);
});

it('submits account withdrawal requests for approval instead of mutating the balance immediately', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    Asset::query()->updateOrCreate(['code' => 'USD'], [
        'name'      => 'US Dollar',
        'type'      => 'fiat',
        'precision' => 2,
        'is_active' => true,
    ]);

    $account = Account::factory()->withBalance(30000)->create();

    livewire(ListAccounts::class)
        ->callTableAction('withdraw', $account, data: [
            'amount' => '80.00',
            'reason' => 'Treasury requested a controlled debit correction after reconciliation.',
        ])
        ->assertHasNoTableActionErrors();

    expect($account->fresh()?->getBalance('USD'))->toBe(30000);

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.accounts.withdraw')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->workspace)->toBe('finance')
        ->and($request->status)->toBe('pending')
        ->and($request->reason)->toBe('Treasury requested a controlled debit correction after reconciliation.')
        ->and($request->payload['operation'] ?? null)->toBe('withdraw')
        ->and($request->payload['amount_minor'] ?? null)->toBe(8000)
        ->and($request->payload['current_balance_minor'] ?? null)->toBe(30000)
        ->and($request->metadata['mode'] ?? null)->toBe('request_approve');
});

it('records governed audit metadata when freezing an account from the resource table', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    $account = Account::factory()->create(['frozen' => false]);

    livewire(ListAccounts::class)
        ->callTableAction('freeze', $account, data: [
            'reason' => 'Freeze the wallet while finance investigates a settlement discrepancy.',
        ])
        ->assertHasNoTableActionErrors();

    expect($account->fresh()?->frozen)->toBeTrue();

    $auditLog = AuditLog::query()
        ->where('action', 'backoffice.accounts.frozen')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->metadata['workspace'] ?? null)->toBe('finance')
        ->and($auditLog->metadata['mode'] ?? null)->toBe('direct_elevated')
        ->and($auditLog->metadata['reason'] ?? null)->toBe('Freeze the wallet while finance investigates a settlement discrepancy.')
        ->and($auditLog->metadata['account_uuid'] ?? null)->toBe($account->uuid)
        ->and($auditLog->metadata['context'] ?? null)->toBe('account_resource')
        ->and($auditLog->new_values['frozen'] ?? null)->toBeTrue();
});

it('submits bulk account freezes for approval with captured evidence', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    $accounts = Account::factory()->count(2)->create(['frozen' => false]);

    livewire(ListAccounts::class)
        ->callTableBulkAction('freeze', $accounts, data: [
            'reason' => 'Treasury requested a batch freeze while reconciling high-risk balances.',
        ])
        ->assertHasNoTableActionErrors();

    expect($accounts->map(fn (Account $account): bool => (bool) $account->fresh()?->frozen)->all())->toBe([false, false]);

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.accounts.bulk_freeze')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->workspace)->toBe('finance')
        ->and($request->status)->toBe('pending')
        ->and($request->payload['requested_state'] ?? null)->toBe('frozen')
        ->and($request->payload['record_count'] ?? null)->toBe(2)
        ->and($request->payload['account_uuids'] ?? null)->toHaveCount(2)
        ->and($request->metadata['mode'] ?? null)->toBe('request_approve');
});

it('closes direct account create edit and delete bypasses for finance operators', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    $account = Account::factory()->create();

    expect(AccountResource::canCreate())->toBeFalse()
        ->and(AccountResource::canEdit($account))->toBeFalse()
        ->and(AccountResource::canDelete($account))->toBeFalse()
        ->and(AccountResource::canDeleteAny())->toBeFalse();

    $this->get(AccountResource::getUrl('create'))->assertForbidden();
    $this->get(AccountResource::getUrl('edit', ['record' => $account]))->assertForbidden();

    livewire(ListAccounts::class)
        ->assertSuccessful()
        ->assertActionHidden('create')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');
});

it('records governed audit metadata when freezing and unfreezing from the account view page', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    $account = Account::factory()->create(['frozen' => false]);

    livewire(ViewAccount::class, ['record' => $account->getKey()])
        ->callAction('freeze', data: [
            'reason' => 'Freeze this wallet while a finance exception is reviewed.',
        ])
        ->assertHasNoActionErrors();

    expect($account->fresh()?->frozen)->toBeTrue();

    livewire(ViewAccount::class, ['record' => $account->getKey()])
        ->callAction('unfreeze', data: [
            'reason' => 'Restore wallet activity after the finance exception was cleared.',
        ])
        ->assertHasNoActionErrors();

    expect($account->fresh()?->frozen)->toBeFalse();

    $freezeLog = AuditLog::query()
        ->where('action', 'backoffice.accounts.frozen')
        ->latest('id')
        ->first();

    $unfreezeLog = AuditLog::query()
        ->where('action', 'backoffice.accounts.unfrozen')
        ->latest('id')
        ->first();

    expect($freezeLog)->not->toBeNull()
        ->and($freezeLog->metadata['context'] ?? null)->toBe('account_view')
        ->and($unfreezeLog)->not->toBeNull()
        ->and($unfreezeLog->metadata['workspace'] ?? null)->toBe('finance')
        ->and($unfreezeLog->metadata['mode'] ?? null)->toBe('direct_elevated')
        ->and($unfreezeLog->metadata['reason'] ?? null)->toBe('Restore wallet activity after the finance exception was cleared.')
        ->and($unfreezeLog->metadata['context'] ?? null)->toBe('account_view');
});

it('submits account adjustment requests for approval with captured evidence from the account view page', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    $account = Account::factory()->create();

    livewire(ViewAccount::class, ['record' => $account->getKey()])
        ->callAction('requestAdjustment', data: [
            'type'       => 'credit',
            'amount'     => '75.25',
            'reason'     => 'Controlled finance adjustment request after reconciliation evidence review.',
            'attachment' => null,
        ])
        ->assertHasNoActionErrors();

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.accounts.request_adjustment')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->workspace)->toBe('finance')
        ->and($request->status)->toBe('pending')
        ->and($request->reason)->toBe('Controlled finance adjustment request after reconciliation evidence review.')
        ->and($request->payload['account_uuid'] ?? null)->toBe($account->uuid)
        ->and($request->payload['adjustment_type'] ?? null)->toBe('credit')
        ->and($request->payload['amount_minor'] ?? null)->toBe(7525)
        ->and($request->payload['attachment_path'] ?? null)->toBeNull()
        ->and($request->payload['context'] ?? null)->toBe('account_view')
        ->and($request->metadata['mode'] ?? null)->toBe('request_approve');
});

it('submits projector replay requests for approval from the account view page', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    $account = Account::factory()->create();

    livewire(ViewAccount::class, ['record' => $account->getKey()])
        ->callAction('replayProjector', data: [
            'reason' => 'Replay the wallet projectors after finance detected a projection mismatch.',
        ])
        ->assertHasNoActionErrors();

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.accounts.replay_projector')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->workspace)->toBe('finance')
        ->and($request->status)->toBe('pending')
        ->and($request->reason)->toBe('Replay the wallet projectors after finance detected a projection mismatch.')
        ->and($request->payload['account_uuid'] ?? null)->toBe($account->uuid)
        ->and($request->payload['replay_scope'] ?? null)->toBe('account_projectors')
        ->and($request->payload['context'] ?? null)->toBe('account_view')
        ->and($request->metadata['mode'] ?? null)->toBe('request_approve');
});

it('records governed reconciliation run and export audits inside the finance workspace', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    $report = ReconciliationReportRecord::fromArray([
        'date'                     => now()->toDateString(),
        'accounts_checked'         => 12,
        'discrepancies_found'      => 1,
        'total_discrepancy_amount' => 5200,
        'status'                   => 'completed',
        'duration_minutes'         => 4,
    ]);

    ReconciliationReportResource::runReconciliation('Trigger a governed reconciliation rerun after treasury review.');
    ReconciliationReportResource::downloadReport($report, 'Download the governed reconciliation report for finance evidence.');
    ReconciliationReportResource::exportReportsAsCsv(collect([$report]), 'Export the governed reconciliation CSV for treasury review.');

    $runLog = AuditLog::query()->where('action', 'backoffice.reconciliation.run')->latest('id')->first();
    $downloadLog = AuditLog::query()->where('action', 'backoffice.reconciliation.downloaded')->latest('id')->first();
    $exportLog = AuditLog::query()->where('action', 'backoffice.reconciliation.exported_csv')->latest('id')->first();

    expect($runLog)->not->toBeNull()
        ->and($runLog->metadata['workspace'] ?? null)->toBe('finance')
        ->and($runLog->metadata['mode'] ?? null)->toBe('direct_elevated')
        ->and($downloadLog)->not->toBeNull()
        ->and($downloadLog->metadata['report_date'] ?? null)->toBe($report->date)
        ->and($downloadLog->metadata['format'] ?? null)->toBe('json')
        ->and($exportLog)->not->toBeNull()
        ->and($exportLog->metadata['report_dates'] ?? null)->toBe([$report->date])
        ->and($exportLog->metadata['format'] ?? null)->toBe('csv');
});

it('submits account funding requests for approval instead of executing immediately', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    $asset = Asset::query()->updateOrCreate(['code' => 'USD'], [
        'name'      => 'US Dollar',
        'type'      => 'fiat',
        'precision' => 2,
        'is_active' => true,
    ]);

    $account = Account::factory()->zeroBalance()->create();

    $page = app(FundAccountPage::class);
    $page->mount();
    $page->selectedAccount = $account->fresh(['user']);
    $page->requestFundingApproval([
        'account_uuid' => $account->uuid,
        'asset_code'   => $asset->code,
        'amount'       => '210.45',
        'reason'       => 'refund',
        'notes'        => 'Controlled treasury funding request after exception handling review.',
    ]);

    expect($account->fresh()?->getBalance('USD'))->toBe(0);

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.fund_accounts.fund')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->workspace)->toBe('finance')
        ->and($request->status)->toBe('pending')
        ->and($request->reason)->toBe('refund')
        ->and($request->payload['account_uuid'] ?? null)->toBe($account->uuid)
        ->and($request->payload['asset_code'] ?? null)->toBe('USD')
        ->and($request->payload['amount_minor'] ?? null)->toBe(21045)
        ->and($request->payload['notes'] ?? null)->toBe('Controlled treasury funding request after exception handling review.')
        ->and($request->metadata['mode'] ?? null)->toBe('request_approve');
});

it('enforces finance workspace authorization on direct finance high-risk action helpers', function (): void {
    $support = User::factory()->create();
    $support->assignRole('support-l1');
    $this->actingAs($support);

    Asset::query()->updateOrCreate(['code' => 'USD'], [
        'name'      => 'US Dollar',
        'type'      => 'fiat',
        'precision' => 2,
        'is_active' => true,
    ]);

    $account = Account::factory()->create();
    $report = ReconciliationReportRecord::fromArray([
        'date'                     => now()->toDateString(),
        'accounts_checked'         => 4,
        'discrepancies_found'      => 0,
        'total_discrepancy_amount' => 0,
        'status'                   => 'completed',
        'duration_minutes'         => 2,
    ]);

    $page = app(FundAccountPage::class);
    $page->mount();
    $page->selectedAccount = $account->fresh(['user']);

    expect(fn () => AccountResource::freezeAccount($account, 'Support cannot freeze finance-owned accounts.'))->toThrow(HttpException::class);
    expect(fn () => ReconciliationReportResource::downloadReport($report, 'Support cannot export governed reconciliation reports.'))->toThrow(HttpException::class);
    expect(fn () => $page->requestFundingApproval([
        'account_uuid' => $account->uuid,
        'asset_code'   => 'USD',
        'amount'       => '10.00',
        'reason'       => 'testing',
        'notes'        => 'Blocked support invocation.',
    ]))->toThrow(HttpException::class);
});
