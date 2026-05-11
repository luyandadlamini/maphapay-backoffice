<?php

declare(strict_types=1);

namespace Tests\Feature\Cards\Http;

use App\Domain\Account\Constants\MinorCardConstants;
use PHPUnit\Framework\Attributes\Test;

final class MinorCardRequestControllerTest extends MinorCardRequestHttpTestCase
{
    #[Test]
    public function guardian_can_approve_a_pending_minor_card_request(): void
    {
        $response = $this->actingAsWithScopes($this->guardian)
            ->withHeader('X-Account-Id', $this->minorAccount->uuid)
            ->postJson("/api/v1/minor-card-requests/{$this->minorRequest->id}/approve");

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.request.status', 'approved');

        $this->assertDatabaseHas('minor_card_requests', [
            'id'     => $this->minorRequest->id,
            'status' => MinorCardConstants::STATUS_APPROVED,
        ]);
    }

    #[Test]
    public function guardian_can_deny_a_pending_minor_card_request_with_a_reason(): void
    {
        $response = $this->actingAsWithScopes($this->guardian)
            ->withHeader('X-Account-Id', $this->minorAccount->uuid)
            ->postJson("/api/v1/minor-card-requests/{$this->minorRequest->id}/deny", [
                'denial_reason' => 'Spending limits need adjustment first.',
            ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.request.status', 'denied');

        $this->assertDatabaseHas('minor_card_requests', [
            'id'     => $this->minorRequest->id,
            'status' => MinorCardConstants::STATUS_DENIED,
        ]);
    }

    #[Test]
    public function deny_requires_a_denial_reason(): void
    {
        $response = $this->actingAsWithScopes($this->guardian)
            ->withHeader('X-Account-Id', $this->minorAccount->uuid)
            ->postJson("/api/v1/minor-card-requests/{$this->minorRequest->id}/deny", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['denial_reason']);
    }

    #[Test]
    public function returns_404_for_a_non_existent_request(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAsWithScopes($this->guardian)
            ->withHeader('X-Account-Id', $this->minorAccount->uuid)
            ->postJson("/api/v1/minor-card-requests/{$fakeId}/approve");

        $response->assertStatus(404);
    }

    #[Test]
    public function lists_pending_minor_card_requests_for_a_guardian(): void
    {
        $response = $this->actingAsWithScopes($this->guardian)
            ->withHeader('X-Account-Id', $this->minorAccount->uuid)
            ->getJson('/api/v1/minor-card-requests');

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.requests.0.id', $this->minorRequest->id);
        $response->assertJsonPath('data.requests.0.status', 'pending');
    }
}
