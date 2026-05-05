<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\VirtualCard;

use App\Domain\CardIssuance\Enums\CardNetwork;
use App\Domain\CardIssuance\Enums\CardStatus;
use App\Domain\CardIssuance\Services\CardProvisioningService;
use App\Domain\CardIssuance\ValueObjects\VirtualCard;
use App\Models\User;
use DateTimeImmutable;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class VirtualCardFreezeControllerTest extends ControllerTestCase
{
    protected User $virtualCardUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->virtualCardUser = User::factory()->create();
    }

    private function makeVirtualCard(string $cardToken, CardStatus $status): VirtualCard
    {
        return new VirtualCard(
            cardToken: $cardToken,
            last4: '4242',
            network: CardNetwork::VISA,
            status: $status,
            cardholderName: $this->virtualCardUser->name ?? 'Card Holder',
            expiresAt: new DateTimeImmutable('+3 years'),
            metadata: [
                'user_id' => $this->virtualCardUser->uuid,
            ],
        );
    }

    #[Test]
    public function test_virtual_card_freeze_freezes_owned_card(): void
    {
        Sanctum::actingAs($this->virtualCardUser, ['read', 'write']);

        $cardToken = 'card-test-freeze';

        $cardService = Mockery::mock(CardProvisioningService::class);
        $cardService->shouldReceive('getCard')
            ->once()
            ->with($cardToken)
            ->andReturn($this->makeVirtualCard($cardToken, CardStatus::ACTIVE));
        $cardService->shouldReceive('freezeCard')
            ->once()
            ->with($cardToken)
            ->andReturn(true);
        $cardService->shouldReceive('getCard')
            ->once()
            ->with($cardToken)
            ->andReturn($this->makeVirtualCard($cardToken, CardStatus::FROZEN));
        app()->instance(CardProvisioningService::class, $cardService);

        $response = $this->postJson("/api/virtual-card/freeze/{$cardToken}");

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.card.card_id', $cardToken)
            ->assertJsonPath('data.card.status', 'frozen');
    }

    #[Test]
    public function test_virtual_card_unfreeze_unfreezes_owned_card(): void
    {
        Sanctum::actingAs($this->virtualCardUser, ['read', 'write']);

        $cardToken = 'card-test-unfreeze';

        $cardService = Mockery::mock(CardProvisioningService::class);
        $cardService->shouldReceive('getCard')
            ->once()
            ->with($cardToken)
            ->andReturn($this->makeVirtualCard($cardToken, CardStatus::FROZEN));
        $cardService->shouldReceive('unfreezeCard')
            ->once()
            ->with($cardToken)
            ->andReturn(true);
        $cardService->shouldReceive('getCard')
            ->once()
            ->with($cardToken)
            ->andReturn($this->makeVirtualCard($cardToken, CardStatus::ACTIVE));
        app()->instance(CardProvisioningService::class, $cardService);

        $response = $this->postJson("/api/virtual-card/unfreeze/{$cardToken}");

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.card.card_id', $cardToken)
            ->assertJsonPath('data.card.status', 'active');
    }
}
