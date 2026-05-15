<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Wallet\Models;

use App\Domain\Wallet\Models\WalletLinking;
use App\Models\User;
use Tests\TestCase;

final class WalletLinkingTest extends TestCase
{

    public function test_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $link = WalletLinking::factory()->for($user)->create();

        $this->assertTrue($link->user->is($user));
    }

    public function test_casts_metadata_as_array_and_timestamps(): void
    {
        $link = WalletLinking::factory()->create([
            'metadata' => ['raw' => 'payload'],
        ]);

        $this->assertSame(['raw' => 'payload'], $link->metadata);
        $this->assertNotNull($link->linked_at);
    }

    public function test_soft_deletes(): void
    {
        $link = WalletLinking::factory()->create();
        $link->delete();

        $this->assertSoftDeleted($link);
    }

    public function test_unique_user_provider_account_ref(): void
    {
        $user = User::factory()->create();
        WalletLinking::factory()->for($user)->create([
            'provider'    => 'mtn_momo',
            'account_ref' => '46733123453',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        WalletLinking::factory()->for($user)->create([
            'provider'    => 'mtn_momo',
            'account_ref' => '46733123453',
        ]);
    }

    public function test_status_constants_exist(): void
    {
        $this->assertSame('active', WalletLinking::STATUS_ACTIVE);
        $this->assertSame('pending', WalletLinking::STATUS_PENDING);
        $this->assertSame('failed', WalletLinking::STATUS_FAILED);
        $this->assertSame('disabled', WalletLinking::STATUS_DISABLED);
    }

    public function test_provider_constants_exist(): void
    {
        $this->assertSame('mtn_momo', WalletLinking::PROVIDER_MTN_MOMO);
        $this->assertSame('emali_eswatini_mobile', WalletLinking::PROVIDER_EMALI);
        $this->assertSame('fnb_ewallet', WalletLinking::PROVIDER_FNB);
        $this->assertSame('standard_unayo', WalletLinking::PROVIDER_STANDARD_UNAYO);
        $this->assertSame('nedbank_send_money', WalletLinking::PROVIDER_NEDBANK);
    }
}
