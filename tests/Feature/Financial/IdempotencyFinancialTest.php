<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

#[Large]
class IdempotencyFinancialTest extends ControllerTestCase
{
    private User $sender;

    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            [
                'name'      => 'Swazi Lilangeni',
                'type'      => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );

        $this->sender = User::factory()->create([
            'kyc_status'     => 'approved',
            'kyc_expires_at' => null,
        ]);
        $this->recipient = User::factory()->create([
            'kyc_status'     => 'approved',
            'kyc_expires_at' => null,
        ]);

        Account::factory()->create([
            'user_uuid' => $this->sender->uuid,
            'frozen'    => false,
        ]);

        Account::factory()->create([
            'user_uuid' => $this->recipient->uuid,
            'frozen'    => false,
        ]);
    }

    #[Test]
    public function test_send_money_store_same_idempotency_key_replays_identical_body(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $idem = (string) Str::uuid();
        $payload = [
            'user'              => $this->recipient->email,
            'amount'            => '15.25',
            'verification_type' => 'sms',
        ];

        $first = $this->postJson('/api/send-money/store', $payload, [
            'X-Idempotency-Key' => $idem,
        ]);
        $first->assertOk();

        $second = $this->postJson('/api/send-money/store', $payload, [
            'X-Idempotency-Key' => $idem,
        ]);
        $second->assertOk();

        $this->assertSame($first->json(), $second->json());

        $this->assertSame(
            1,
            AuthorizedTransaction::query()
                ->where('user_id', $this->sender->id)
                ->where('remark', AuthorizedTransaction::REMARK_SEND_MONEY)
                ->count(),
        );

        $this->assertSame('true', $second->headers->get('X-Idempotency-Replayed'));
    }
}
