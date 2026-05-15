<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\WalletLinking;

use App\Domain\Wallet\Models\WalletLinking;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class WalletLinkingStoreControllerTest extends TestCase
{
    public function test_creates_a_link_for_the_authenticated_user(): void
    {
        $me = User::factory()->create();
        Sanctum::actingAs($me, ['read', 'write', 'delete']);

        $this->postJson('/api/wallet-linking', [
            'provider'    => 'mtn_momo',
            'account_ref' => '46733123453',
            'currency'    => 'SZL',
            'link_status' => 'active',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.linking.provider', 'mtn_momo');

        $this->assertDatabaseHas('wallet_linkings', [
            'user_id'     => $me->id,
            'provider'    => 'mtn_momo',
            'account_ref' => '46733123453',
            'link_status' => 'active',
        ]);
    }

    public function test_upserts_on_user_provider_account_ref_conflict(): void
    {
        $me = User::factory()->create();
        WalletLinking::factory()->for($me)->create([
            'provider'    => 'mtn_momo',
            'account_ref' => '46733123453',
            'link_status' => 'pending',
        ]);
        Sanctum::actingAs($me, ['read', 'write', 'delete']);

        $this->postJson('/api/wallet-linking', [
            'provider'    => 'mtn_momo',
            'account_ref' => '46733123453',
            'currency'    => 'SZL',
            'link_status' => 'active',
        ])->assertOk();

        $this->assertDatabaseCount('wallet_linkings', 1);
        $this->assertDatabaseHas('wallet_linkings', [
            'user_id'     => $me->id,
            'provider'    => 'mtn_momo',
            'account_ref' => '46733123453',
            'link_status' => 'active',
        ]);
    }

    public function test_rejects_unknown_provider(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['read', 'write', 'delete']);

        $this->postJson('/api/wallet-linking', [
            'provider'    => 'not_a_real_provider',
            'account_ref' => '46733123453',
            'currency'    => 'SZL',
            'link_status' => 'active',
        ])->assertStatus(422);
    }

    public function test_requires_auth(): void
    {
        $this->postJson('/api/wallet-linking', [])->assertUnauthorized();
    }
}
