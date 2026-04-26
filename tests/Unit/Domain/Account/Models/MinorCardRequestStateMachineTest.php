<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account\Models;

use App\Domain\Account\Constants\MinorCardConstants;
use App\Domain\Account\Models\MinorCardRequest;
use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Tests\CreatesApplication;

class MinorCardRequestStateMachineTest extends TestCase
{
    use CreatesApplication;

    #[Test]
    public function can_transition_from_pending_to_approved(): void
    {
        $request = new MinorCardRequest(['status' => MinorCardConstants::STATUS_PENDING_APPROVAL]);

        expect($request->canTransitionTo(MinorCardConstants::STATUS_APPROVED))->toBeTrue();
    }

    #[Test]
    public function can_transition_from_pending_to_denied(): void
    {
        $request = new MinorCardRequest(['status' => MinorCardConstants::STATUS_PENDING_APPROVAL]);

        expect($request->canTransitionTo(MinorCardConstants::STATUS_DENIED))->toBeTrue();
    }

    #[Test]
    public function can_transition_from_pending_to_cancelled(): void
    {
        $request = new MinorCardRequest(['status' => MinorCardConstants::STATUS_PENDING_APPROVAL]);

        expect($request->canTransitionTo(MinorCardConstants::STATUS_CANCELLED))->toBeTrue();
    }

    #[Test]
    public function cannot_transition_from_pending_to_active(): void
    {
        $request = new MinorCardRequest(['status' => MinorCardConstants::STATUS_PENDING_APPROVAL]);

        expect($request->canTransitionTo(MinorCardConstants::STATUS_ACTIVE))->toBeFalse();
    }

    #[Test]
    public function cannot_transition_from_denied_to_approved(): void
    {
        $request = new MinorCardRequest(['status' => MinorCardConstants::STATUS_DENIED]);

        expect($request->canTransitionTo(MinorCardConstants::STATUS_APPROVED))->toBeFalse();
    }

    #[Test]
    public function can_resubmit_denied_request(): void
    {
        $request = new MinorCardRequest(['status' => MinorCardConstants::STATUS_DENIED]);

        expect($request->canTransitionTo(MinorCardConstants::STATUS_PENDING_APPROVAL))->toBeTrue();
    }

    #[Test]
    public function cannot_transition_from_cancelled(): void
    {
        $request = new MinorCardRequest(['status' => MinorCardConstants::STATUS_CANCELLED]);

        expect($request->canTransitionTo(MinorCardConstants::STATUS_PENDING_APPROVAL))->toBeFalse();
        expect($request->canTransitionTo(MinorCardConstants::STATUS_APPROVED))->toBeFalse();
        expect($request->canTransitionTo(MinorCardConstants::STATUS_ACTIVE))->toBeFalse();
    }

    #[Test]
    public function can_transition_from_approved_to_card_created(): void
    {
        $request = new MinorCardRequest(['status' => MinorCardConstants::STATUS_APPROVED]);

        expect($request->canTransitionTo(MinorCardConstants::STATUS_CARD_CREATED))->toBeTrue();
    }

    #[Test]
    public function can_transition_from_active_to_frozen(): void
    {
        $request = new MinorCardRequest(['status' => MinorCardConstants::STATUS_ACTIVE]);

        expect($request->canTransitionTo(MinorCardConstants::STATUS_FROZEN))->toBeTrue();
    }

    #[Test]
    public function can_transition_from_active_to_revoked(): void
    {
        $request = new MinorCardRequest(['status' => MinorCardConstants::STATUS_ACTIVE]);

        expect($request->canTransitionTo(MinorCardConstants::STATUS_REVOKED))->toBeTrue();
    }
}
