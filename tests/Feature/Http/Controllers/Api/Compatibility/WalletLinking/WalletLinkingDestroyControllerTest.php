<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\WalletLinking;

use App\Domain\Wallet\Models\WalletLinking;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class WalletLinkingDestroyControllerTest extends TestCase
{
    public function test_soft_deletes_my_link_and_writes_audit(): void
    {
        $me = User::factory()->create();
        $link = WalletLinking::factory()->for($me)->create();
        Sanctum::actingAs($me, ['read', 'write', 'delete']);

        $this->deleteJson("/api/wallet-linking/{$link->id}")
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSoftDeleted($link);
        $this->assertSame('disabled', $link->fresh()->link_status);
        $this->assertDatabaseHas('security_audit_logs', [
            'event_type' => 'wallet.linking_disabled',
            'user_id'    => $me->id,
            'severity'   => 'high',
        ]);
    }

    public function test_404_when_link_belongs_to_someone_else(): void
    {
        $me = User::factory()->create();
        $someone = User::factory()->create();
        $theirLink = WalletLinking::factory()->for($someone)->create();
        Sanctum::actingAs($me, ['read', 'write', 'delete']);

        $this->deleteJson("/api/wallet-linking/{$theirLink->id}")->assertNotFound();
    }

    public function test_requires_auth(): void
    {
        $link = WalletLinking::factory()->create();
        $this->deleteJson("/api/wallet-linking/{$link->id}")->assertUnauthorized();
    }
}
