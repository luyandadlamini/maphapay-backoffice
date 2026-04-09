<?php

declare(strict_types=1);

namespace Tests\Feature\Custodian;

use App\Domain\Custodian\Models\CustodianWebhook;
use App\Domain\Custodian\Services\CustodianAccountService;
use App\Domain\Custodian\Services\CustodianRegistry;
use App\Domain\Custodian\Services\WebhookProcessorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

final class ProviderOperationWebhookProcessingTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('provider_operations')) {
            Schema::create('provider_operations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('provider_family');
                $table->string('provider_name');
                $table->string('operation_type');
                $table->string('operation_key')->unique();
                $table->string('normalized_event_type')->nullable();
                $table->string('provider_reference')->nullable();
                $table->string('internal_reference')->nullable();
                $table->string('finality_status')->default('pending');
                $table->string('settlement_status')->default('pending');
                $table->string('reconciliation_status')->default('pending');
                $table->string('settlement_reference')->nullable();
                $table->string('reconciliation_reference')->nullable();
                $table->string('ledger_posting_reference')->nullable();
                $table->unsignedBigInteger('latest_webhook_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['provider_family', 'provider_name'], 'provider_operations_family_name_idx');
                $table->index(['provider_name', 'provider_reference'], 'provider_operations_name_reference_idx');
                $table->index(['operation_type', 'finality_status'], 'provider_operations_type_finality_idx');
                $table->index(['settlement_status', 'reconciliation_status'], 'provider_operations_settlement_reconciliation_idx');
            });
        }

        if (! Schema::hasColumn('custodian_webhooks', 'provider_operation_id')) {
            Schema::table('custodian_webhooks', function (Blueprint $table): void {
                $table->uuid('provider_operation_id')->nullable()->after('ledger_posting_reference');
                $table->index('provider_operation_id');
            });
        }

        \DB::table('provider_operations')->truncate();
        \DB::table('custodian_webhooks')->truncate();
    }

    public function test_it_creates_a_canonical_provider_operation_from_a_processed_payment_webhook(): void
    {
        Event::fake();

        $service = new WebhookProcessorService(
            Mockery::mock(CustodianAccountService::class),
            Mockery::mock(CustodianRegistry::class),
        );

        $webhook = CustodianWebhook::create([
            'custodian_name' => 'mock',
            'event_type' => 'transaction.completed',
            'normalized_event_type' => 'payment_succeeded',
            'event_id' => 'evt-provider-op-001',
            'provider_reference' => 'txn-provider-op-001',
            'headers' => [],
            'payload' => [
                'transaction_id' => 'txn-provider-op-001',
                'amount' => 1500,
                'currency' => 'USD',
            ],
            'payload_hash' => hash('sha256', json_encode(['transaction_id' => 'txn-provider-op-001', 'amount' => 1500, 'currency' => 'USD'], JSON_THROW_ON_ERROR)),
            'dedupe_key' => 'mock:event:evt-provider-op-001',
            'status' => 'pending',
            'finality_status' => 'pending',
            'settlement_status' => 'pending',
            'reconciliation_status' => 'pending',
        ]);

        $webhook->refresh();
        $service->process($webhook);

        $webhook->refresh();

        $this->assertNotNull($webhook->provider_operation_id);

        $this->assertDatabaseHas('provider_operations', [
            'id' => $webhook->provider_operation_id,
            'provider_family' => 'custodian',
            'provider_name' => 'mock',
            'operation_type' => 'transfer',
            'provider_reference' => 'txn-provider-op-001',
            'internal_reference' => 'txn-provider-op-001',
            'finality_status' => 'succeeded',
            'settlement_status' => 'pending',
            'reconciliation_status' => 'pending',
            'ledger_posting_reference' => null,
        ]);
    }

    public function test_it_updates_the_existing_provider_operation_for_later_webhook_status_changes(): void
    {
        Event::fake();

        $service = new WebhookProcessorService(
            Mockery::mock(CustodianAccountService::class),
            Mockery::mock(CustodianRegistry::class),
        );

        $completedWebhook = CustodianWebhook::create([
            'custodian_name' => 'mock',
            'event_type' => 'transaction.completed',
            'normalized_event_type' => 'payment_succeeded',
            'event_id' => 'evt-provider-op-002-completed',
            'provider_reference' => 'txn-provider-op-002',
            'headers' => [],
            'payload' => [
                'transaction_id' => 'txn-provider-op-002',
            ],
            'payload_hash' => hash('sha256', json_encode(['transaction_id' => 'txn-provider-op-002'], JSON_THROW_ON_ERROR)),
            'dedupe_key' => 'mock:event:evt-provider-op-002-completed',
            'status' => 'pending',
            'finality_status' => 'pending',
            'settlement_status' => 'pending',
            'reconciliation_status' => 'pending',
        ]);

        $failedWebhook = CustodianWebhook::create([
            'custodian_name' => 'mock',
            'event_type' => 'transaction.failed',
            'normalized_event_type' => 'payment_failed',
            'event_id' => 'evt-provider-op-002-failed',
            'provider_reference' => 'txn-provider-op-002',
            'headers' => [],
            'payload' => [
                'transaction_id' => 'txn-provider-op-002',
                'reason' => 'insufficient_funds',
            ],
            'payload_hash' => hash('sha256', json_encode(['transaction_id' => 'txn-provider-op-002', 'reason' => 'insufficient_funds'], JSON_THROW_ON_ERROR)),
            'dedupe_key' => 'mock:event:evt-provider-op-002-failed',
            'status' => 'pending',
            'finality_status' => 'pending',
            'settlement_status' => 'pending',
            'reconciliation_status' => 'pending',
        ]);

        $completedWebhook->refresh();
        $service->process($completedWebhook);
        $initialOperationId = $completedWebhook->fresh()->provider_operation_id;

        $failedWebhook->refresh();
        $service->process($failedWebhook);

        $failedWebhook->refresh();

        $this->assertSame($initialOperationId, $failedWebhook->provider_operation_id);
        $this->assertDatabaseCount('provider_operations', 1);
        $this->assertDatabaseHas('provider_operations', [
            'id' => $initialOperationId,
            'provider_family' => 'custodian',
            'provider_name' => 'mock',
            'operation_type' => 'transfer',
            'provider_reference' => 'txn-provider-op-002',
            'finality_status' => 'failed',
            'settlement_status' => 'pending',
            'reconciliation_status' => 'pending',
        ]);
    }
}
