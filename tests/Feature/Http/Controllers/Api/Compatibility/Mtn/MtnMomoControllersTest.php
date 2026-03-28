<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\Mtn;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Models\Asset;
use App\Models\MtnMomoTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class MtnMomoControllersTest extends ControllerTestCase
{
    private User $payer;

    private Account $payerAccount;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();

        $this->payer = User::factory()->create();

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            [
                'name'      => 'Swazi Lilangeni',
                'type'      => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );

        $this->payerAccount = Account::factory()->create([
            'uuid'      => (string) Str::uuid(),
            'user_uuid' => $this->payer->uuid,
            'frozen'    => false,
        ]);

        AccountBalance::factory()
            ->forAccount($this->payerAccount)
            ->forAsset('SZL')
            ->withBalance(1_000_000)
            ->create();
    }

    protected function tearDown(): void
    {
        Http::preventStrayRequests(false);

        parent::tearDown();
    }

    private function fakeMtnHttp(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            if (str_contains($url, '/collection/token/') || str_contains($url, '/disbursement/token/')) {
                return Http::response([
                    'access_token' => 'test-access-token',
                    'token_type'   => 'Bearer',
                    'expires_in'   => 3600,
                ], 200);
            }

            if (str_contains($url, '/collection/v1_0/requesttopay')) {
                if ($request->method() === 'POST') {
                    return Http::response('', 202);
                }

                return Http::response([
                    'status'                 => 'SUCCESSFUL',
                    'financialTransactionId' => 'fin-rtp-1',
                ], 200);
            }

            if (str_contains($url, '/disbursement/v1_0/transfer')) {
                if ($request->method() === 'POST') {
                    return Http::response('', 202);
                }

                return Http::response([
                    'status'                 => 'SUCCESSFUL',
                    'financialTransactionId' => 'fin-tr-1',
                ], 200);
            }

            return Http::response('unexpected MTN URL: ' . $url, 500);
        });
    }

    #[Test]
    public function test_request_to_pay_returns_404_when_migration_disabled(): void
    {
        config([
            'maphapay_migration.enable_mtn_momo' => false,
        ]);

        Sanctum::actingAs($this->payer, ['read', 'write', 'delete']);

        $this->postJson('/api/mtn/request-to-pay', [
            'idempotency_key' => 'idem-1',
            'amount'          => '10.00',
            'payer_msisdn'    => '26876123456',
        ])->assertNotFound();
    }

    #[Test]
    public function test_request_to_pay_success_envelope_and_persists_row(): void
    {
        config([
            'maphapay_migration.enable_mtn_momo' => true,
            'mtn_momo.base_url'                  => 'https://sandbox.momodeveloper.test',
            'mtn_momo.subscription_key'          => 'sub-key',
            'mtn_momo.api_user'                  => 'user',
            'mtn_momo.api_key'                   => 'secret',
            'mtn_momo.target_environment'        => 'sandbox',
            'mtn_momo.currency'                  => 'SZL',
        ]);

        $this->fakeMtnHttp();

        Sanctum::actingAs($this->payer, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/mtn/request-to-pay', [
            'idempotency_key' => 'idem-rtp-1',
            'amount'          => '10.50',
            'payer_msisdn'    => '26876123456',
            'note'            => 'Top up',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('remark', 'mtn_request_to_pay')
            ->assertJsonPath('data.transaction.amount', '10.50')
            ->assertJsonPath('data.transaction.currency', 'SZL')
            ->assertJsonPath('data.transaction.status', MtnMomoTransaction::STATUS_PENDING);

        $ref = $response->json('data.transaction.mtn_reference_id');
        $this->assertIsString($ref);

        $this->assertDatabaseHas('mtn_momo_transactions', [
            'user_id'          => $this->payer->id,
            'idempotency_key'  => 'idem-rtp-1',
            'type'             => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
            'amount'           => '10.50',
            'status'           => MtnMomoTransaction::STATUS_PENDING,
            'mtn_reference_id' => $ref,
        ]);
    }

    #[Test]
    public function test_request_to_pay_idempotent_second_call_skips_extra_mtn_posts(): void
    {
        config([
            'maphapay_migration.enable_mtn_momo' => true,
            'mtn_momo.base_url'                  => 'https://sandbox.momodeveloper.test',
            'mtn_momo.subscription_key'          => 'sub-key',
            'mtn_momo.api_user'                  => 'user',
            'mtn_momo.api_key'                   => 'secret',
            'mtn_momo.target_environment'        => 'sandbox',
        ]);

        $this->fakeMtnHttp();

        Sanctum::actingAs($this->payer, ['read', 'write', 'delete']);

        $first = $this->postJson('/api/mtn/request-to-pay', [
            'idempotency_key' => 'idem-same',
            'amount'          => '5.00',
            'payer_msisdn'    => '26876123456',
        ]);
        $first->assertOk();
        $recordedAfterFirst = count(Http::recorded());

        $second = $this->postJson('/api/mtn/request-to-pay', [
            'idempotency_key' => 'idem-same',
            'amount'          => '5.00',
            'payer_msisdn'    => '26876123456',
        ]);
        $second->assertOk();
        $recordedAfterSecond = count(Http::recorded());

        $this->assertSame($recordedAfterFirst, $recordedAfterSecond);
        $this->assertSame($first->json('data.transaction.id'), $second->json('data.transaction.id'));
    }

    #[Test]
    public function test_disbursement_success_reserves_funds_and_calls_mtn(): void
    {
        config([
            'maphapay_migration.enable_mtn_momo' => true,
            'mtn_momo.base_url'                  => 'https://sandbox.momodeveloper.test',
            'mtn_momo.subscription_key'          => 'sub-key',
            'mtn_momo.api_user'                  => 'user',
            'mtn_momo.api_key'                   => 'secret',
            'mtn_momo.target_environment'        => 'sandbox',
        ]);

        $this->fakeMtnHttp();

        Sanctum::actingAs($this->payer, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/mtn/disbursement', [
            'idempotency_key' => 'idem-out-1',
            'amount'          => '25.00',
            'payee_msisdn'    => '26876999888',
        ]);

        $response->assertOk()
            ->assertJsonPath('remark', 'mtn_disbursement')
            ->assertJsonPath('data.transaction.amount', '25.00');

        $this->assertDatabaseHas('mtn_momo_transactions', [
            'user_id'         => $this->payer->id,
            'idempotency_key' => 'idem-out-1',
            'type'            => MtnMomoTransaction::TYPE_DISBURSEMENT,
        ]);
    }

    #[Test]
    public function test_transaction_status_updates_from_mtn_payload(): void
    {
        config([
            'maphapay_migration.enable_mtn_momo' => true,
            'mtn_momo.base_url'                  => 'https://sandbox.momodeveloper.test',
            'mtn_momo.subscription_key'          => 'sub-key',
            'mtn_momo.api_user'                  => 'user',
            'mtn_momo.api_key'                   => 'secret',
            'mtn_momo.target_environment'        => 'sandbox',
        ]);

        $this->fakeMtnHttp();

        $ref = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        MtnMomoTransaction::query()->create([
            'id'               => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'user_id'          => $this->payer->id,
            'idempotency_key'  => 'pre-seeded',
            'type'             => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
            'amount'           => '10.00',
            'currency'         => 'SZL',
            'status'           => MtnMomoTransaction::STATUS_PENDING,
            'party_msisdn'     => '26876123456',
            'mtn_reference_id' => $ref,
        ]);

        Sanctum::actingAs($this->payer, ['read', 'write', 'delete']);

        $this->getJson('/api/mtn/transaction/' . $ref . '/status')
            ->assertOk()
            ->assertJsonPath('data.transaction.status', MtnMomoTransaction::STATUS_SUCCESSFUL)
            ->assertJsonPath('data.transaction.mtn_financial_transaction_id', 'fin-rtp-1');
    }

    #[Test]
    public function test_callback_rejects_invalid_token_when_verification_enabled(): void
    {
        config([
            'maphapay_migration.enable_mtn_momo' => true,
            'mtn_momo.verify_callback_token'     => true,
            'mtn_momo.callback_token'            => 'expected-secret',
        ]);

        $this->postJson('/api/mtn/callback', [], [
            'X-Callback-Token' => 'wrong',
            'X-Reference-Id'   => 'any',
        ])->assertUnauthorized();
    }

    #[Test]
    public function test_callback_updates_transaction_without_sanctum(): void
    {
        config([
            'maphapay_migration.enable_mtn_momo' => true,
            'mtn_momo.verify_callback_token'     => false,
        ]);

        $ref = 'cccccccc-cccc-cccc-cccc-cccccccccccc';

        MtnMomoTransaction::query()->create([
            'id'               => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
            'user_id'          => $this->payer->id,
            'idempotency_key'  => 'cb-1',
            'type'             => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
            'amount'           => '3.00',
            'currency'         => 'SZL',
            'status'           => MtnMomoTransaction::STATUS_PENDING,
            'party_msisdn'     => '26876123456',
            'mtn_reference_id' => $ref,
        ]);

        $this->postJson('/api/mtn/callback', [
            'status'                 => 'SUCCESSFUL',
            'financialTransactionId' => 'fin-cb-1',
        ], [
            'X-Reference-Id' => $ref,
        ])->assertOk();

        $this->assertDatabaseHas('mtn_momo_transactions', [
            'mtn_reference_id'             => $ref,
            'status'                       => MtnMomoTransaction::STATUS_SUCCESSFUL,
            'mtn_financial_transaction_id' => 'fin-cb-1',
        ]);
    }
}
