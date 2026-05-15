<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\WalletLinking;

use App\Domain\Wallet\Models\WalletLinking;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class WalletLinkingIndexControllerTest extends TestCase
{
    public function test_returns_only_the_authenticated_users_links(): void
    {
        $me = User::factory()->create();
        $someone = User::factory()->create();

        $mine = WalletLinking::factory()->for($me)->create(['provider' => 'mtn_momo']);
        WalletLinking::factory()->for($someone)->create(['provider' => 'mtn_momo']);

        Sanctum::actingAs($me, ['read', 'write', 'delete']);

        $this->getJson('/api/wallet-linking')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.wallets.0.id', $mine->id)
            ->assertJsonCount(1, 'data.wallets');
    }

    public function test_filters_by_provider_query_param(): void
    {
        $me = User::factory()->create();
        WalletLinking::factory()->for($me)->create(['provider' => 'mtn_momo']);
        WalletLinking::factory()->for($me)->create(['provider' => 'fnb_ewallet']);

        Sanctum::actingAs($me, ['read', 'write', 'delete']);

        $this->getJson('/api/wallet-linking?provider=mtn_momo')
            ->assertOk()
            ->assertJsonCount(1, 'data.wallets')
            ->assertJsonPath('data.wallets.0.provider', 'mtn_momo');
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/wallet-linking')->assertUnauthorized();
    }
}
