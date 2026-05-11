<?php

declare(strict_types=1);

use App\Domain\Account\Constants\MinorCardConstants;
use App\Domain\Account\Models\MinorCardRequest;
use App\Models\User;

beforeEach(function () {
    $this->guardian = $this->user;
    $this->guardian->update([
        'kyc_status'      => 'approved',
        'kyc_approved_at' => now(),
    ]);

    // Bypass KYC middleware
    $this->app->instance(\App\Http\Middleware\CheckKycApproved::class, new class {
        public function handle($request, $next) { return $next($request); }
    });

    // Stub account context
    $this->app->instance(\App\Http\Middleware\ResolveAccountContext::class, new class($this->account) {
        public function __construct(private $acc) {}
        public function handle($request, $next) {
            $request->attributes->set('account_uuid', $this->acc->uuid);
            return $next($request);
        }
    });

    $this->minorRequest = MinorCardRequest::create([
        'minor_account_uuid'    => $this->account->uuid,
        'requested_by_user_uuid' => $this->guardian->id,
        'request_type'          => MinorCardConstants::REQUEST_TYPE_PARENT_INITIATED,
        'status'                => MinorCardConstants::STATUS_PENDING_APPROVAL,
        'requested_network'     => 'visa',
    ]);
});

it('guardian can approve a pending minor card request', function () {
    $response = $this->actingAsWithScopes($this->guardian)
        ->withHeader('X-Account-Id', $this->account->uuid)
        ->postJson("/api/v1/minor-card-requests/{$this->minorRequest->id}/approve");

    $response->assertOk();
    $response->assertJsonFragment(['message' => 'Minor card request approved.']);

    $this->assertDatabaseHas('minor_card_requests', [
        'id'     => $this->minorRequest->id,
        'status' => MinorCardConstants::STATUS_APPROVED,
    ]);
});

it('guardian can deny a pending minor card request with a reason', function () {
    $response = $this->actingAsWithScopes($this->guardian)
        ->withHeader('X-Account-Id', $this->account->uuid)
        ->postJson("/api/v1/minor-card-requests/{$this->minorRequest->id}/deny", [
            'denial_reason' => 'Spending limits need adjustment first.',
        ]);

    $response->assertOk();
    $response->assertJsonFragment(['message' => 'Minor card request denied.']);

    $this->assertDatabaseHas('minor_card_requests', [
        'id'     => $this->minorRequest->id,
        'status' => MinorCardConstants::STATUS_DENIED,
    ]);
});

it('deny requires a denial_reason', function () {
    $response = $this->actingAsWithScopes($this->guardian)
        ->withHeader('X-Account-Id', $this->account->uuid)
        ->postJson("/api/v1/minor-card-requests/{$this->minorRequest->id}/deny", []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['denial_reason']);
});

it('returns 404 for a non-existent request', function () {
    $fakeId = '00000000-0000-0000-0000-000000000000';

    $response = $this->actingAsWithScopes($this->guardian)
        ->withHeader('X-Account-Id', $this->account->uuid)
        ->postJson("/api/v1/minor-card-requests/{$fakeId}/approve");

    $response->assertStatus(404);
});
