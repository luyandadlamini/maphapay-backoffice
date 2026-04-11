<?php

declare(strict_types=1);

use App\Domain\Compliance\Models\AuditLog;
use App\Filament\Admin\Resources\UserResource;
use App\Filament\Admin\Resources\UserResource\Pages\ListUsers;
use App\Filament\Admin\Resources\UserResource\Pages\ViewUser;
use App\Models\AdminActionApprovalRequest;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel->boot();
});

it('limits user-admin visibility to support-oriented roles', function (): void {
    $support = User::factory()->create();
    $support->assignRole('support-l1');
    $this->actingAs($support);

    expect(UserResource::canViewAny())->toBeTrue();
    livewire(ListUsers::class)->assertSuccessful();

    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    expect(UserResource::canViewAny())->toBeFalse();
});

it('submits user freeze requests for approval instead of mutating immediately', function (): void {
    $compliance = User::factory()->create();
    $compliance->assignRole('compliance-manager');
    $this->actingAs($compliance);

    $customer = User::factory()->create([
        'email' => 'frozen-candidate@example.test',
    ]);

    livewire(ListUsers::class)
        ->callTableAction('freeze', $customer, data: [
            'reason' => 'Escalate this customer account freeze after suspicious identity mismatch review.',
        ])
        ->assertHasNoTableActionErrors();

    expect($customer->fresh()?->frozen_at)->toBeNull();

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.users.freeze')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->workspace)->toBe('support')
        ->and($request->status)->toBe('pending')
        ->and($request->requester_id)->toBe($compliance->id)
        ->and($request->reason)->toBe('Escalate this customer account freeze after suspicious identity mismatch review.')
        ->and($request->target_type)->toBe(User::class)
        ->and($request->target_identifier)->toBe($customer->uuid)
        ->and($request->payload['user_uuid'] ?? null)->toBe($customer->uuid)
        ->and($request->payload['user_email'] ?? null)->toBe('frozen-candidate@example.test')
        ->and($request->payload['current_state'] ?? null)->toBe('active')
        ->and($request->payload['requested_state'] ?? null)->toBe('frozen');
});

it('records governed audit metadata when resetting 2fa from the user view', function (): void {
    $operations = User::factory()->create();
    $operations->assignRole('operations-l2');
    $this->actingAs($operations);

    $customer = User::factory()->create([
        'email' => 'two-factor@example.test',
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);

    livewire(ViewUser::class, ['record' => $customer->uuid])
        ->callAction('reset2fa', data: [
            'reason' => 'Customer failed device recovery and support is clearing 2FA for account access restoration.',
        ])
        ->assertHasNoActionErrors();

    $customer->refresh();

    expect($customer->two_factor_secret)->toBeNull()
        ->and($customer->two_factor_recovery_codes)->toBeNull()
        ->and($customer->two_factor_confirmed_at)->toBeNull();

    $auditLog = AuditLog::query()
        ->where('action', 'backoffice.users.2fa_reset')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->auditable_type)->toBe(User::class)
        ->and($auditLog->auditable_id)->toBe((string) $customer->getKey())
        ->and($auditLog->metadata['workspace'] ?? null)->toBe('support')
        ->and($auditLog->metadata['mode'] ?? null)->toBe('direct_elevated')
        ->and($auditLog->metadata['reason'] ?? null)->toBe('Customer failed device recovery and support is clearing 2FA for account access restoration.')
        ->and($auditLog->metadata['user_uuid'] ?? null)->toBe($customer->uuid);
});

it('records governed audit metadata when forcing a password reset from the user view', function (): void {
    Notification::fake();

    $operations = User::factory()->create();
    $operations->assignRole('operations-l2');
    $this->actingAs($operations);

    $customer = User::factory()->create([
        'email' => 'password-reset@example.test',
    ]);

    livewire(ViewUser::class, ['record' => $customer->uuid])
        ->callAction('resetPassword', data: [
            'reason' => 'Customer requested an assisted password recovery after verified support authentication.',
        ])
        ->assertHasNoActionErrors();

    Notification::assertSentTo($customer, ResetPassword::class);

    $auditLog = AuditLog::query()
        ->where('action', 'backoffice.users.password_reset_forced')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->auditable_type)->toBe(User::class)
        ->and($auditLog->auditable_id)->toBe((string) $customer->getKey())
        ->and($auditLog->metadata['workspace'] ?? null)->toBe('support')
        ->and($auditLog->metadata['mode'] ?? null)->toBe('direct_elevated')
        ->and($auditLog->metadata['reason'] ?? null)->toBe('Customer requested an assisted password recovery after verified support authentication.')
        ->and($auditLog->metadata['user_uuid'] ?? null)->toBe($customer->uuid);
});

it('submits bulk kyc rejection requests for approval with evidence', function (): void {
    $compliance = User::factory()->create();
    $compliance->assignRole('compliance-manager');
    $this->actingAs($compliance);

    $customers = User::factory()->count(2)->create([
        'kyc_status' => 'pending',
    ]);

    livewire(ListUsers::class)
        ->callTableBulkAction('rejectKyc', $customers, data: [
            'reason' => 'Reject these KYC submissions pending refreshed identity documents and sanctions clarification.',
        ])
        ->assertHasNoTableActionErrors();

    expect($customers->map(fn (User $customer): ?string => $customer->fresh()?->kyc_status)->all())
        ->toBe(['pending', 'pending']);

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.users.bulk_kyc_reject')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->workspace)->toBe('support')
        ->and($request->status)->toBe('pending')
        ->and($request->requester_id)->toBe($compliance->id)
        ->and($request->payload['requested_state'] ?? null)->toBe('rejected')
        ->and($request->payload['record_count'] ?? null)->toBe(2)
        ->and($request->payload['user_uuids'] ?? null)->toHaveCount(2)
        ->and($request->payload['reason'] ?? null)->toBe('Reject these KYC submissions pending refreshed identity documents and sanctions clarification.');
});

it('submits user deletion from the view page for approval with evidence', function (): void {
    $platform = User::factory()->create();
    $platform->assignRole('super-admin');
    $this->actingAs($platform);

    $customer = User::factory()->create([
        'email' => 'delete-request@example.test',
    ]);

    livewire(ViewUser::class, ['record' => $customer->uuid])
        ->callAction('delete', data: [
            'reason' => 'Remove this duplicate customer record after validated merger into the canonical profile.',
        ])
        ->assertHasNoActionErrors();

    expect($customer->fresh())->not->toBeNull();

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.users.delete')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->workspace)->toBe('support')
        ->and($request->status)->toBe('pending')
        ->and($request->requester_id)->toBe($platform->id)
        ->and($request->reason)->toBe('Remove this duplicate customer record after validated merger into the canonical profile.')
        ->and($request->target_type)->toBe(User::class)
        ->and($request->target_identifier)->toBe($customer->uuid)
        ->and($request->payload['requested_state'] ?? null)->toBe('deleted')
        ->and($request->payload['user_uuid'] ?? null)->toBe($customer->uuid)
        ->and($request->payload['user_email'] ?? null)->toBe('delete-request@example.test');
});

it('enforces action-level roles across support user administration actions', function (): void {
    $customer = User::factory()->create([
        'frozen_at' => null,
    ]);

    $support = User::factory()->create();
    $support->assignRole('support-l1');
    $this->actingAs($support);

    livewire(ListUsers::class)
        ->assertTableActionHidden('freeze', $customer)
        ->assertTableBulkActionHidden('approveKyc')
        ->assertTableBulkActionHidden('rejectKyc')
        ->assertTableBulkActionHidden('requestDelete');

    livewire(ViewUser::class, ['record' => $customer->uuid])
        ->assertActionHidden('reset2fa')
        ->assertActionHidden('resetPassword')
        ->assertActionHidden('delete');

    $operations = User::factory()->create();
    $operations->assignRole('operations-l2');
    $this->actingAs($operations);

    livewire(ViewUser::class, ['record' => $customer->uuid])
        ->assertActionVisible('reset2fa')
        ->assertActionVisible('resetPassword')
        ->assertActionHidden('delete');

    $compliance = User::factory()->create();
    $compliance->assignRole('compliance-manager');
    $this->actingAs($compliance);

    livewire(ListUsers::class)
        ->assertTableActionVisible('freeze', $customer)
        ->assertTableBulkActionVisible('approveKyc')
        ->assertTableBulkActionVisible('rejectKyc')
        ->assertTableBulkActionHidden('requestDelete');
});

it('closes direct create edit and delete bypasses where policy blocks them', function (): void {
    $platform = User::factory()->create();
    $platform->assignRole('super-admin');
    $this->actingAs($platform);

    $customer = User::factory()->create();

    expect(UserResource::canCreate())->toBeFalse()
        ->and(UserResource::canEdit($customer))->toBeFalse()
        ->and(UserResource::canDelete($customer))->toBeFalse()
        ->and(UserResource::canDeleteAny())->toBeFalse();

    livewire(ListUsers::class)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableBulkActionDoesNotExist('delete');

    livewire(ViewUser::class, ['record' => $customer->uuid])
        ->assertActionHidden('edit');
});
