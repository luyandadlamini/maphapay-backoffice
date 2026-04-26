<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account\Services;

use App\Domain\Account\Constants\MinorCardConstants;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorCardRequest;
use App\Domain\Account\Services\MinorAccountAccessService;
use App\Domain\Account\Services\MinorCardRequestService;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\CreatesApplication;

class MinorCardRequestServiceTest extends BaseTestCase
{
    use CreatesApplication;

    private MinorCardRequestService $service;

    private MinorAccountAccessService $accessService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accessService = Mockery::mock(MinorAccountAccessService::class);
        $this->service = new MinorCardRequestService($this->accessService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function create_request_rejects_grow_tier(): void
    {
        $guardian = User::factory()->create();
        $child = User::factory()->create();

        $guardianAccount = Account::factory()->create([
            'user_uuid' => $guardian->uuid,
            'type'      => 'personal',
        ]);

        $minorAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'type'      => 'minor',
            'tier'      => 'grow',
        ]);

        $this->accessService->shouldReceive('hasGuardianAccess')
            ->andReturn(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Virtual cards are only available for Rise tier');

        $this->service->createRequest($guardian, $minorAccount, 'visa', null);
    }

    #[Test]
    public function create_request_throws_for_non_guardian_non_minor(): void
    {
        $nonRelatedUser = User::factory()->create();
        $child = User::factory()->create();

        $minorAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'type'      => 'minor',
            'tier'      => 'rise',
        ]);

        $this->accessService->shouldReceive('hasGuardianAccess')
            ->andReturn(false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only the minor or their guardian can request a card');

        $this->service->createRequest($nonRelatedUser, $minorAccount, 'visa', null);
    }

    #[Test]
    public function create_request_creates_pending_request(): void
    {
        $guardian = User::factory()->create();
        $child = User::factory()->create();

        $minorAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'type'      => 'minor',
            'tier'      => 'rise',
        ]);

        $this->accessService->shouldReceive('hasGuardianAccess')
            ->andReturn(true);

        $cardholderId = Str::uuid()->toString();
        DB::table('cardholders')->insert([
            'id'         => $cardholderId,
            'user_id'    => $child->id,
            'first_name' => 'Test',
            'last_name'  => 'User',
        ]);
        DB::table('cards')->insert([
            'id'                => Str::uuid()->toString(),
            'user_id'           => $child->id,
            'cardholder_id'     => $cardholderId,
            'issuer_card_token' => Str::uuid()->toString(),
            'issuer'            => 'test',
            'last4'             => '1234',
            'network'           => 'visa',
            'status'            => 'active',
            'currency'          => 'USD',
        ]);

        $result = $this->service->createRequest($guardian, $minorAccount, 'visa', [
            'daily' => '500.00',
        ]);

        $this->assertEquals(MinorCardConstants::STATUS_PENDING_APPROVAL, $result->status);
        $this->assertEquals(MinorCardConstants::REQUEST_TYPE_PARENT_INITIATED, $result->request_type);
        $this->assertEquals('visa', $result->requested_network);
        $this->assertEquals($minorAccount->uuid, $result->minor_account_uuid);

        DB::table('cards')->delete();
        DB::table('cardholders')->delete();
        MinorCardRequest::query()->delete();
    }

    #[Test]
    public function approve_updates_status_to_approved(): void
    {
        $guardian = User::factory()->create();
        $child = User::factory()->create();

        $minorAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'type'      => 'minor',
            'tier'      => 'rise',
        ]);

        $request = MinorCardRequest::create([
            'minor_account_uuid'     => $minorAccount->uuid,
            'requested_by_user_uuid' => $child->uuid,
            'request_type'           => MinorCardConstants::REQUEST_TYPE_CHILD_REQUESTED,
            'status'                 => MinorCardConstants::STATUS_PENDING_APPROVAL,
            'requested_network'      => 'visa',
            'expires_at'             => now()->addHours(72),
        ]);

        $result = $this->service->approve($guardian, $request);

        $this->assertEquals(MinorCardConstants::STATUS_APPROVED, $result->status);
        $this->assertEquals($guardian->uuid, $result->approved_by_user_uuid);

        MinorCardRequest::query()->delete();
    }

    #[Test]
    public function deny_updates_status_to_denied_with_reason(): void
    {
        $guardian = User::factory()->create();
        $child = User::factory()->create();

        $minorAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'type'      => 'minor',
            'tier'      => 'rise',
        ]);

        $request = MinorCardRequest::create([
            'minor_account_uuid'     => $minorAccount->uuid,
            'requested_by_user_uuid' => $child->uuid,
            'request_type'           => MinorCardConstants::REQUEST_TYPE_CHILD_REQUESTED,
            'status'                 => MinorCardConstants::STATUS_PENDING_APPROVAL,
            'requested_network'      => 'visa',
            'expires_at'             => now()->addHours(72),
        ]);

        $result = $this->service->deny($guardian, $request, 'Insufficient information provided');

        $this->assertEquals(MinorCardConstants::STATUS_DENIED, $result->status);
        $this->assertEquals('Insufficient information provided', $result->denial_reason);

        MinorCardRequest::query()->delete();
    }

    #[Test]
    public function cannot_approve_expired_request(): void
    {
        $guardian = User::factory()->create();
        $child = User::factory()->create();

        $minorAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'type'      => 'minor',
            'tier'      => 'rise',
        ]);

        $request = MinorCardRequest::create([
            'minor_account_uuid'     => $minorAccount->uuid,
            'requested_by_user_uuid' => $child->uuid,
            'request_type'           => MinorCardConstants::REQUEST_TYPE_CHILD_REQUESTED,
            'status'                 => MinorCardConstants::STATUS_PENDING_APPROVAL,
            'requested_network'      => 'visa',
            'expires_at'             => now()->subHours(1),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Request cannot be approved in its current state');

        $this->service->approve($guardian, $request);
    }

    #[Test]
    public function child_request_detected_when_not_guardian(): void
    {
        $guardian = User::factory()->create();
        $child = User::factory()->create();

        $minorAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'type'      => 'minor',
            'tier'      => 'rise',
        ]);

        $this->accessService->shouldReceive('hasGuardianAccess')
            ->andReturn(false);

        $cardholderId = Str::uuid()->toString();
        DB::table('cardholders')->insert([
            'id'         => $cardholderId,
            'user_id'    => $child->id,
            'first_name' => 'Test',
            'last_name'  => 'User',
        ]);
        DB::table('cards')->insert([
            'id'                => Str::uuid()->toString(),
            'user_id'           => $child->id,
            'cardholder_id'     => $cardholderId,
            'issuer_card_token' => Str::uuid()->toString(),
            'issuer'            => 'test',
            'last4'             => '1234',
            'network'           => 'visa',
            'status'            => 'active',
            'currency'          => 'USD',
        ]);

        $result = $this->service->createRequest($child, $minorAccount, 'visa', null);

        $this->assertEquals(MinorCardConstants::REQUEST_TYPE_CHILD_REQUESTED, $result->request_type);

        DB::table('cards')->delete();
        DB::table('cardholders')->delete();
        MinorCardRequest::query()->delete();
    }
}
