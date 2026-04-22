<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Jobs\ProcessCustodianWebhook;
use DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CustodianWebhookControllerTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        Config::set('custodians.connectors.paysera.webhook_secret', 'paysera_test_secret');
        Config::set('custodians.connectors.santander.webhook_secret', 'santander_test_secret');

        if (! Schema::hasTable('custodian_webhooks')) {
            Schema::create('custodian_webhooks', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('custodian_name');
                $table->string('event_type');
                $table->string('normalized_event_type')->nullable();
                $table->string('event_id')->nullable();
                $table->string('provider_reference')->nullable();
                $table->json('headers')->nullable();
                $table->json('payload');
                $table->string('payload_hash', 64)->nullable();
                $table->string('dedupe_key')->nullable()->unique();
                $table->string('signature')->nullable();
                $table->enum('status', ['pending', 'processing', 'processed', 'failed', 'ignored']);
                $table->string('finality_status')->default('pending');
                $table->string('settlement_status')->default('pending');
                $table->string('reconciliation_status')->default('pending');
                $table->integer('attempts')->default(0);
                $table->dateTime('processed_at')->nullable();
                $table->text('error_message')->nullable();
                $table->uuid('custodian_account_id')->nullable();
                $table->string('transaction_id')->nullable();
                $table->string('settlement_reference')->nullable();
                $table->string('reconciliation_reference')->nullable();
                $table->string('ledger_posting_reference')->nullable();
                $table->timestamps();
            });
        }

        // Fresh schema now links provider_operations.latest_webhook_id -> custodian_webhooks.id.
        // Use delete instead of truncate so FK cleanup stays valid on the reusable MySQL harness.
        DB::table('provider_operations')->delete();
        DB::table('custodian_webhooks')->delete();
    }

    public function test_it_persists_normalized_webhook_identity_metadata_for_paysera_callbacks(): void
    {
        $payload = [
            'event'      => 'payment.completed',
            'event_id'   => 'evt_123',
            'payment_id' => 'pay_456',
        ];

        $signature = hash_hmac('sha256', json_encode($payload), 'paysera_test_secret');

        $this->postJson('/api/webhooks/custodian/paysera', $payload, [
            'X-Paysera-Signature' => $signature,
        ])->assertStatus(202)
            ->assertJson(['status' => 'accepted']);

        $this->assertDatabaseHas('custodian_webhooks', [
            'custodian_name'           => 'paysera',
            'event_type'               => 'payment.completed',
            'event_id'                 => 'evt_123',
            'normalized_event_type'    => 'payment_succeeded',
            'provider_reference'       => 'pay_456',
            'dedupe_key'               => 'paysera:event:evt_123',
            'payload_hash'             => hash('sha256', json_encode($payload)),
            'finality_status'          => 'succeeded',
            'settlement_status'        => 'pending',
            'reconciliation_status'    => 'pending',
            'ledger_posting_reference' => null,
        ]);

        Queue::assertPushed(ProcessCustodianWebhook::class);
    }

    public function test_it_dedupes_callbacks_without_event_ids_using_provider_reference_plus_normalized_event_and_payload_hash(): void
    {
        $payload = [
            'type'           => 'transaction.completed',
            'transaction_id' => 'txn_001',
            'amount'         => 1500,
            'currency'       => 'USD',
        ];

        $this->postJson('/api/webhooks/custodian/mock', $payload)
            ->assertStatus(202)
            ->assertJson(['status' => 'accepted']);

        $this->postJson('/api/webhooks/custodian/mock', $payload)
            ->assertStatus(202)
            ->assertJson([
                'status'    => 'accepted',
                'duplicate' => true,
            ]);

        $this->assertSame(1, \App\Domain\Custodian\Models\CustodianWebhook::query()->count());

        $this->assertDatabaseHas('custodian_webhooks', [
            'custodian_name'        => 'mock',
            'event_id'              => null,
            'normalized_event_type' => 'payment_succeeded',
            'provider_reference'    => 'txn_001',
            'dedupe_key'            => 'mock:fallback:txn_001:payment_succeeded:' . hash('sha256', json_encode($payload)),
        ]);
    }
}
