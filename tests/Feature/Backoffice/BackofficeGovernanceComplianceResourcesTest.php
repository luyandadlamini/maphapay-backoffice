<?php

declare(strict_types=1);

use App\Domain\Compliance\Models\AmlScreening;
use App\Domain\Compliance\Models\AuditLog;
use App\Filament\Admin\Resources\AmlScreeningResource;
use App\Filament\Admin\Resources\AmlScreeningResource\Pages\ListAmlScreenings;
use App\Filament\Admin\Resources\DataSubjectRequestResource;
use App\Filament\Admin\Resources\DataSubjectRequestResource\Pages\ListDataSubjectRequests;
use App\Models\AdminActionApprovalRequest;
use App\Models\DataSubjectRequest;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @param  array<string, mixed>  $overrides
 */
function createAmlScreeningRecord(array $overrides = []): AmlScreening
{
    $subject = User::factory()->create();

    return AmlScreening::query()->create(array_merge([
        'entity_id'          => $subject->uuid,
        'entity_type'        => User::class,
        'screening_number'   => 'AML-' . now()->format('Y') . '-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'type'               => AmlScreening::TYPE_SANCTIONS,
        'status'             => AmlScreening::STATUS_COMPLETED,
        'provider'           => 'manual',
        'provider_reference' => (string) str()->uuid(),
        'search_parameters'  => ['name' => $subject->name],
        'screening_config'   => [],
        'fuzzy_matching'     => true,
        'match_threshold'    => 85,
        'total_matches'      => 2,
        'confirmed_matches'  => 1,
        'false_positives'    => 0,
        'overall_risk'       => AmlScreening::RISK_HIGH,
    ], $overrides));
}

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel->boot();
});

it('limits compliance resources to the compliance workspace', function (): void {
    $support = User::factory()->create();
    $support->assignRole('support-l1');
    $this->actingAs($support);

    expect(AmlScreeningResource::canViewAny())->toBeFalse()
        ->and(DataSubjectRequestResource::canViewAny())->toBeFalse();

    $this->get(AmlScreeningResource::getUrl())->assertForbidden();
    $this->get(DataSubjectRequestResource::getUrl())->assertForbidden();

    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    expect(AmlScreeningResource::canViewAny())->toBeFalse()
        ->and(DataSubjectRequestResource::canViewAny())->toBeFalse();

    $this->get(AmlScreeningResource::getUrl())->assertForbidden();
    $this->get(DataSubjectRequestResource::getUrl())->assertForbidden();

    $compliance = User::factory()->create();
    $compliance->assignRole('compliance-manager');
    $this->actingAs($compliance);

    expect(AmlScreeningResource::canViewAny())->toBeTrue()
        ->and(DataSubjectRequestResource::canViewAny())->toBeTrue();
});

it('submits aml sar actions for approval with captured evidence', function (): void {
    $compliance = User::factory()->create();
    $compliance->assignRole('compliance-manager');
    $this->actingAs($compliance);

    $screening = createAmlScreeningRecord([
        'total_matches'     => 3,
        'confirmed_matches' => 2,
        'overall_risk'      => AmlScreening::RISK_CRITICAL,
        'reviewed_by'       => null,
        'reviewed_at'       => null,
        'review_decision'   => null,
        'review_notes'      => null,
    ]);

    livewire(ListAmlScreenings::class)
        ->callTableAction('submitSar', $screening, data: [
            'description' => 'Escalate this critical sanctions match into a formal suspicious activity report filing.',
            'reference'   => 'SAR-CASE-2026-0007',
        ])
        ->assertHasNoTableActionErrors();

    expect($screening->fresh()?->review_decision)->toBeNull();

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.aml_screenings.submit_sar')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull();

    /** @var AdminActionApprovalRequest $request */
    expect($request)
        ->and($request->workspace)->toBe('compliance')
        ->and($request->status)->toBe('pending')
        ->and($request->requester_id)->toBe($compliance->id)
        ->and($request->reason)->toBe('Escalate this critical sanctions match into a formal suspicious activity report filing.')
        ->and($request->target_type)->toBe(AmlScreening::class)
        ->and($request->target_identifier)->toBe((string) $screening->getKey())
        ->and($request->payload['screening_number'] ?? null)->toBe($screening->screening_number)
        ->and($request->payload['overall_risk'] ?? null)->toBe(AmlScreening::RISK_CRITICAL)
        ->and($request->payload['sar_reference'] ?? null)->toBe('SAR-CASE-2026-0007')
        ->and($request->payload['evidence']['description'] ?? null)->toBe('Escalate this critical sanctions match into a formal suspicious activity report filing.')
        ->and($request->payload['evidence']['reference'] ?? null)->toBe('SAR-CASE-2026-0007');
});

it('records governed audit metadata when clearing an aml flag directly', function (): void {
    $compliance = User::factory()->create();
    $compliance->assignRole('compliance-manager');
    $this->actingAs($compliance);

    $screening = createAmlScreeningRecord([
        'reviewed_by'     => null,
        'reviewed_at'     => null,
        'review_decision' => null,
        'review_notes'    => null,
    ]);

    livewire(ListAmlScreenings::class)
        ->callTableAction('clearFlag', $screening, data: [
            'reason' => 'Confirmed documentary evidence resolved the alert as a false positive screening match.',
        ])
        ->assertHasNoTableActionErrors();

    $screening->refresh();

    expect($screening->review_decision)->toBe(AmlScreening::DECISION_CLEAR)
        ->and($screening->review_notes)->toBe('Confirmed documentary evidence resolved the alert as a false positive screening match.')
        ->and($screening->reviewed_by)->toBe($compliance->id)
        ->and($screening->reviewed_at)->not->toBeNull();

    $auditLog = AuditLog::query()
        ->where('action', 'backoffice.aml_screenings.flag_cleared')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->auditable_type)->toBe(AmlScreening::class)
        ->and($auditLog->auditable_id)->toBe((string) $screening->getKey())
        ->and($auditLog->user_uuid)->toBe($compliance->uuid)
        ->and($auditLog->metadata['workspace'] ?? null)->toBe('compliance')
        ->and($auditLog->metadata['mode'] ?? null)->toBe('direct_elevated')
        ->and($auditLog->metadata['reason'] ?? null)->toBe('Confirmed documentary evidence resolved the alert as a false positive screening match.')
        ->and($auditLog->metadata['screening_number'] ?? null)->toBe($screening->screening_number)
        ->and($auditLog->metadata['decision'] ?? null)->toBe(AmlScreening::DECISION_CLEAR);
});

it('records governed audit metadata when escalating an aml screening directly', function (): void {
    $compliance = User::factory()->create();
    $compliance->assignRole('compliance-manager');
    $this->actingAs($compliance);

    $screening = createAmlScreeningRecord([
        'reviewed_by'     => null,
        'reviewed_at'     => null,
        'review_decision' => null,
        'review_notes'    => null,
    ]);

    livewire(ListAmlScreenings::class)
        ->callTableAction('escalate', $screening, data: [
            'reason' => 'Escalate this screening to senior compliance due to repeated corroborated high-risk matches.',
        ])
        ->assertHasNoTableActionErrors();

    $screening->refresh();

    expect($screening->review_decision)->toBe(AmlScreening::DECISION_ESCALATE)
        ->and($screening->review_notes)->toBe('Escalate this screening to senior compliance due to repeated corroborated high-risk matches.')
        ->and($screening->reviewed_by)->toBe($compliance->id)
        ->and($screening->reviewed_at)->not->toBeNull();

    $auditLog = AuditLog::query()
        ->where('action', 'backoffice.aml_screenings.escalated')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->metadata['workspace'] ?? null)->toBe('compliance')
        ->and($auditLog->metadata['mode'] ?? null)->toBe('direct_elevated')
        ->and($auditLog->metadata['reason'] ?? null)->toBe('Escalate this screening to senior compliance due to repeated corroborated high-risk matches.')
        ->and($auditLog->metadata['decision'] ?? null)->toBe(AmlScreening::DECISION_ESCALATE);
});

it('submits data deletion fulfillment for approval with evidence capture', function (): void {
    $compliance = User::factory()->create();
    $compliance->assignRole('compliance-manager');
    $subject = User::factory()->create();
    $this->actingAs($compliance);

    $requestRecord = DataSubjectRequest::query()->create([
        'user_id' => $subject->id,
        'type'    => DataSubjectRequest::TYPE_DELETION,
        'status'  => DataSubjectRequest::STATUS_IN_REVIEW,
        'reason'  => 'Customer requested account erasure after relationship closure.',
    ]);

    livewire(ListDataSubjectRequests::class)
        ->callTableAction('fulfillDeletion', $requestRecord, data: [
            'reason' => 'Deletion evidence packet reviewed and queued for maker-checker approval before erasure.',
        ])
        ->assertHasNoTableActionErrors();

    $requestRecord->refresh();

    expect($requestRecord->status)->toBe(DataSubjectRequest::STATUS_IN_REVIEW)
        ->and($requestRecord->reviewed_by)->toBeNull()
        ->and($requestRecord->fulfilled_at)->toBeNull();

    $approval = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.data_subject_requests.fulfill_deletion')
        ->latest('id')
        ->first();

    expect($approval)->not->toBeNull();

    /** @var AdminActionApprovalRequest $approval */
    expect($approval)
        ->and($approval->workspace)->toBe('compliance')
        ->and($approval->status)->toBe('pending')
        ->and($approval->requester_id)->toBe($compliance->id)
        ->and($approval->reason)->toBe('Deletion evidence packet reviewed and queued for maker-checker approval before erasure.')
        ->and($approval->target_type)->toBe(DataSubjectRequest::class)
        ->and($approval->target_identifier)->toBe((string) $requestRecord->getKey())
        ->and($approval->payload['request_type'] ?? null)->toBe(DataSubjectRequest::TYPE_DELETION)
        ->and($approval->payload['requested_state'] ?? null)->toBe(DataSubjectRequest::STATUS_FULFILLED)
        ->and($approval->payload['evidence']['reason'] ?? null)->toBe('Deletion evidence packet reviewed and queued for maker-checker approval before erasure.');
});

it('records governed audit metadata when fulfilling a data export directly', function (): void {
    $compliance = User::factory()->create();
    $compliance->assignRole('compliance-manager');
    $subject = User::factory()->create();
    $this->actingAs($compliance);

    $requestRecord = DataSubjectRequest::query()->create([
        'user_id' => $subject->id,
        'type'    => DataSubjectRequest::TYPE_EXPORT,
        'status'  => DataSubjectRequest::STATUS_RECEIVED,
        'reason'  => 'Customer requested a portable export of retained personal data.',
    ]);

    livewire(ListDataSubjectRequests::class)
        ->callTableAction('fulfillExport', $requestRecord, data: [
            'reason' => 'Verified the export package scope and delivery path before releasing the GDPR data bundle.',
        ])
        ->assertHasNoTableActionErrors();

    $requestRecord->refresh();

    expect($requestRecord->status)->toBe(DataSubjectRequest::STATUS_FULFILLED)
        ->and($requestRecord->reviewed_by)->toBe($compliance->id)
        ->and($requestRecord->review_notes)->toBe('Verified the export package scope and delivery path before releasing the GDPR data bundle.')
        ->and($requestRecord->fulfilled_at)->not->toBeNull();

    $auditLog = AuditLog::query()
        ->where('action', 'backoffice.data_subject_requests.export_fulfilled')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->auditable_type)->toBe(DataSubjectRequest::class)
        ->and($auditLog->auditable_id)->toBe((string) $requestRecord->getKey())
        ->and($auditLog->user_uuid)->toBe($compliance->uuid)
        ->and($auditLog->metadata['workspace'] ?? null)->toBe('compliance')
        ->and($auditLog->metadata['mode'] ?? null)->toBe('direct_elevated')
        ->and($auditLog->metadata['reason'] ?? null)->toBe('Verified the export package scope and delivery path before releasing the GDPR data bundle.')
        ->and($auditLog->metadata['request_type'] ?? null)->toBe(DataSubjectRequest::TYPE_EXPORT);
});

it('records governed audit metadata when rejecting a data subject request directly', function (): void {
    $compliance = User::factory()->create();
    $compliance->assignRole('compliance-manager');
    $subject = User::factory()->create();
    $this->actingAs($compliance);

    $requestRecord = DataSubjectRequest::query()->create([
        'user_id' => $subject->id,
        'type'    => DataSubjectRequest::TYPE_ACCESS,
        'status'  => DataSubjectRequest::STATUS_IN_REVIEW,
        'reason'  => 'Customer requested access outside the supported statutory window.',
    ]);

    livewire(ListDataSubjectRequests::class)
        ->callTableAction('reject', $requestRecord, data: [
            'reason' => 'Reject because the request lacks the identity evidence needed to disclose regulated customer data.',
        ])
        ->assertHasNoTableActionErrors();

    $requestRecord->refresh();

    expect($requestRecord->status)->toBe(DataSubjectRequest::STATUS_REJECTED)
        ->and($requestRecord->reviewed_by)->toBe($compliance->id)
        ->and($requestRecord->review_notes)->toBe('Reject because the request lacks the identity evidence needed to disclose regulated customer data.')
        ->and($requestRecord->fulfilled_at)->toBeNull();

    $auditLog = AuditLog::query()
        ->where('action', 'backoffice.data_subject_requests.rejected')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->metadata['workspace'] ?? null)->toBe('compliance')
        ->and($auditLog->metadata['mode'] ?? null)->toBe('direct_elevated')
        ->and($auditLog->metadata['reason'] ?? null)->toBe('Reject because the request lacks the identity evidence needed to disclose regulated customer data.')
        ->and($auditLog->metadata['request_type'] ?? null)->toBe(DataSubjectRequest::TYPE_ACCESS);
});

it('enforces compliance-only action helpers and closes direct mutation bypasses', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $subject = User::factory()->create();
    $this->actingAs($finance);

    $screening = createAmlScreeningRecord();

    $requestRecord = DataSubjectRequest::query()->create([
        'user_id' => $subject->id,
        'type'    => DataSubjectRequest::TYPE_EXPORT,
        'status'  => DataSubjectRequest::STATUS_RECEIVED,
    ]);

    expect(AmlScreeningResource::canCreate())->toBeFalse()
        ->and(AmlScreeningResource::canEdit($screening))->toBeFalse()
        ->and(AmlScreeningResource::canDelete($screening))->toBeFalse()
        ->and(DataSubjectRequestResource::canCreate())->toBeFalse()
        ->and(DataSubjectRequestResource::canEdit($requestRecord))->toBeFalse()
        ->and(DataSubjectRequestResource::canDelete($requestRecord))->toBeFalse();

    livewire(ListAmlScreenings::class)
        ->assertForbidden();

    livewire(ListDataSubjectRequests::class)
        ->assertForbidden();

    expect(fn () => AmlScreeningResource::requestSarApproval($screening, [
        'description' => 'Unauthorized SAR request attempt from finance workspace.',
        'reference'   => 'SAR-UNAUTH-1',
    ]))->toThrow(HttpException::class, 'This action is outside your workspace.');

    expect(fn () => DataSubjectRequestResource::fulfillExportRequest(
        $requestRecord,
        'Unauthorized export fulfillment attempt from finance workspace.'
    ))->toThrow(HttpException::class, 'This action is outside your workspace.');
});
