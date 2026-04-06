<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Compliance\Events;

use App\Domain\Compliance\Events\KycVerificationCompleted;
use PHPUnit\Framework\Attributes\Test;
use Tests\DomainTestCase;

class KycVerificationCompletedTest extends DomainTestCase
{
    #[Test]
    public function test_creates_event_with_user_uuid_and_level(): void
    {
        $userUuid = 'user-123-uuid';
        $level = 'basic';

        $event = new KycVerificationCompleted($userUuid, $level);

        $this->assertEquals($userUuid, $event->userUuid);
        $this->assertEquals($level, $event->level);
    }

    #[Test]
    public function test_handles_different_verification_levels(): void
    {
        $levels = ['basic', 'enhanced', 'full', 'simplified'];
        $userUuid = 'test-user-uuid';

        foreach ($levels as $level) {
            $event = new KycVerificationCompleted($userUuid, $level);

            $this->assertEquals($level, $event->level);
            $this->assertEquals($userUuid, $event->userUuid);
        }
    }

    #[Test]
    public function test_event_extends_should_be_stored(): void
    {
        $event = new KycVerificationCompleted('user-456', 'enhanced');

        $this->assertInstanceOf(\Spatie\EventSourcing\StoredEvents\ShouldBeStored::class, $event);
    }

    #[Test]
    public function test_event_properties_are_public(): void
    {
        $event = new KycVerificationCompleted('user-789', 'full');

        // Direct property access should work
        $this->assertEquals('user-789', $event->userUuid);
        $this->assertEquals('full', $event->level);
    }
}
