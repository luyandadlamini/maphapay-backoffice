<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account\Services;

use App\Domain\Account\Constants\MinorCardConstants;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorCardLimit;
use App\Domain\Account\Models\MinorCardRequest;
use App\Domain\Account\Services\MinorAccountAccessService;
use App\Domain\Account\Services\MinorCardService;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\Services\CardProvisioningService;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\CreatesApplication;

class MinorCardServiceTest extends BaseTestCase
{
    use CreatesApplication;

    private MinorCardService $service;

    private CardProvisioningService $cardProvisioning;

    private MinorAccountAccessService $accessService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cardProvisioning = Mockery::mock(CardProvisioningService::class);
        $this->accessService = Mockery::mock(MinorAccountAccessService::class);
        $this->service = new MinorCardService($this->cardProvisioning, $this->accessService);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function freeze_card_requires_guardian(): void
    {
        $guardian = User::factory()->create();
        $child = User::factory()->create();

        $minorAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'type'      => 'minor',
            'tier'      => 'rise',
        ]);

        $card = Card::factory()->create([
            'minor_account_uuid' => $minorAccount->uuid,
            'status'             => 'active',
            'issuer_card_token'  => Str::uuid()->toString(),
        ]);

        $this->accessService->shouldReceive('hasGuardianAccess')
            ->with($guardian, Mockery::any())
            ->andReturn(false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only guardians can freeze a minor card');

        $this->service->freezeCard($guardian, $card);

        Card::query()->delete();
    }

    #[Test]
    public function unfreeze_card_requires_guardian(): void
    {
        $guardian = User::factory()->create();
        $child = User::factory()->create();

        $minorAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'type'      => 'minor',
            'tier'      => 'rise',
        ]);

        $card = Card::factory()->create([
            'minor_account_uuid' => $minorAccount->uuid,
            'status'             => 'frozen',
            'issuer_card_token'  => Str::uuid()->toString(),
        ]);

        $this->accessService->shouldReceive('hasGuardianAccess')
            ->with($guardian, Mockery::any())
            ->andReturn(false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only guardians can unfreeze a minor card');

        $this->service->unfreezeCard($guardian, $card);

        Card::query()->delete();
    }

    #[Test]
    public function freeze_card_uses_issuer_card_token(): void
    {
        $guardian = User::factory()->create();
        $child = User::factory()->create();

        $minorAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'type'      => 'minor',
            'tier'      => 'rise',
        ]);

        $card = Card::factory()->create([
            'minor_account_uuid' => $minorAccount->uuid,
            'status'             => 'active',
            'issuer_card_token'  => $token = Str::uuid()->toString(),
        ]);

        $this->accessService->shouldReceive('hasGuardianAccess')
            ->with($guardian, Mockery::any())
            ->andReturn(true);

        $this->cardProvisioning->shouldReceive('freezeCard')
            ->with($token)
            ->once()
            ->andReturn(true);

        $result = $this->service->freezeCard($guardian, $card);

        $this->assertNotNull($result);

        Card::query()->delete();
    }

    #[Test]
    public function resolve_limits_uses_minor_card_limits_when_present(): void
    {
        $child = User::factory()->create();

        $minorAccount = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'type'      => 'minor',
            'tier'      => 'rise',
        ]);

        MinorCardLimit::create([
            'minor_account_uuid'       => $minorAccount->uuid,
            'daily_limit'              => '3000.00',
            'monthly_limit'            => '15000.00',
            'single_transaction_limit' => '2000.00',
            'is_active'                => true,
        ]);

        $request = MinorCardRequest::create([
            'minor_account_uuid'      => $minorAccount->uuid,
            'requested_by_user_uuid'  => $child->uuid,
            'request_type'            => MinorCardConstants::REQUEST_TYPE_CHILD_REQUESTED,
            'status'                  => MinorCardConstants::STATUS_PENDING_APPROVAL,
            'requested_network'       => 'visa',
            'requested_daily_limit'   => '4000.00',
            'requested_monthly_limit' => '20000.00',
            'requested_single_limit'  => '2500.00',
        ]);

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('resolveLimits');
        $method->setAccessible(true);

        $limits = $method->invoke($this->service, $request, $minorAccount);

        $this->assertEquals(3000.00, $limits['daily']);
        $this->assertEquals(15000.00, $limits['monthly']);
        $this->assertEquals(2000.00, $limits['single_transaction']);

        MinorCardLimit::query()->delete();
        MinorCardRequest::query()->delete();
    }

    #[Test]
    public function resolve_limits_uses_defaults_when_no_limits(): void
    {
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
        ]);

        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('resolveLimits');
        $method->setAccessible(true);

        $limits = $method->invoke($this->service, $request, $minorAccount);

        $this->assertEquals(2000.00, $limits['daily']);
        $this->assertEquals(10000.00, $limits['monthly']);
        $this->assertEquals(1500.00, $limits['single_transaction']);

        MinorCardRequest::query()->delete();
    }
}
