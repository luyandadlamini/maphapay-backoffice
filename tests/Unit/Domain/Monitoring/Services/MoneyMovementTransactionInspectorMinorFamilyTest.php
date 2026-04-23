<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Monitoring\Services;

use App\Domain\Account\Models\MinorFamilyFundingAttempt;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Domain\Account\Models\MinorFamilySupportTransfer;
use App\Domain\Monitoring\Services\MoneyMovementTransactionInspector;
use App\Models\MtnMomoTransaction;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\DomainTestCase;

class MoneyMovementTransactionInspectorMinorFamilyTest extends DomainTestCase
{
    #[Test]
    public function it_exposes_minor_family_funding_attempt_context_and_uncredited_risk_warning(): void
    {
        $user = User::factory()->create();
        $reference = 'PHASE9A-FUNDING-REF-001';

        $link = MinorFamilyFundingLink::query()->create([
            'tenant_id' => 'tenant-phase9a',
            'minor_account_uuid' => 'minor-account-uuid',
            'created_by_user_uuid' => $user->uuid,
            'created_by_account_uuid' => 'guardian-account-uuid',
            'title' => 'Family support link',
            'note' => 'Support for school trip',
            'token' => 'support-token-001',
            'status' => MinorFamilyFundingLink::STATUS_ACTIVE,
            'amount_mode' => MinorFamilyFundingLink::AMOUNT_MODE_CAPPED,
            'target_amount' => '500.00',
            'collected_amount' => '100.00',
            'asset_code' => 'SZL',
            'provider_options' => ['mtn_momo'],
            'expires_at' => now()->addDay(),
        ]);

        $attempt = MinorFamilyFundingAttempt::query()->create([
            'tenant_id' => 'tenant-phase9a',
            'funding_link_uuid' => $link->id,
            'minor_account_uuid' => 'minor-account-uuid',
            'status' => MinorFamilyFundingAttempt::STATUS_SUCCESSFUL_UNCREDITED,
            'sponsor_name' => 'Auntie Thandi',
            'sponsor_msisdn' => '26876123456',
            'amount' => '150.00',
            'asset_code' => 'SZL',
            'provider_name' => 'mtn_momo',
            'provider_reference_id' => $reference,
            'failed_reason' => 'wallet_credit_failed',
            'dedupe_hash' => hash('sha256', 'attempt-1'),
        ]);

        $transaction = MtnMomoTransaction::query()->create([
            'id' => $attempt->id,
            'user_id' => $user->id,
            'idempotency_key' => hash('sha256', 'idempotency-1'),
            'type' => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
            'amount' => '150.00',
            'currency' => 'SZL',
            'status' => MtnMomoTransaction::STATUS_SUCCESSFUL,
            'party_msisdn' => '26876123456',
            'mtn_reference_id' => $reference,
            'wallet_credited_at' => null,
        ]);
        $transaction->forceFill([
            'context_type' => MinorFamilyFundingAttempt::class,
            'context_uuid' => $attempt->id,
        ])->save();

        $result = app(MoneyMovementTransactionInspector::class)->inspect(reference: $reference);

        $this->assertSame(MinorFamilyFundingAttempt::class, $result['minor_family_context']['context_type']);
        $this->assertSame($attempt->id, $result['minor_family_context']['context_uuid']);
        $this->assertSame($reference, $result['minor_family_context']['provider_reference_id']);
        $this->assertSame(MinorFamilyFundingAttempt::STATUS_SUCCESSFUL_UNCREDITED, $result['minor_family_context']['funding_attempt']['status']);
        $this->assertSame($link->id, $result['minor_family_context']['funding_link']['id']);
        $this->assertContains(
            'Minor family funding attempt is successful at provider level but still uncredited. Wallet credit reconciliation is required.',
            $result['warnings'],
        );
    }

    #[Test]
    public function it_exposes_minor_family_support_transfer_context_and_refund_risk_warning(): void
    {
        $user = User::factory()->create();
        $reference = 'PHASE9A-SUPPORT-REF-001';

        $transfer = MinorFamilySupportTransfer::query()->create([
            'tenant_id' => 'tenant-phase9a',
            'minor_account_uuid' => 'minor-account-uuid',
            'actor_user_uuid' => $user->uuid,
            'source_account_uuid' => 'guardian-source-account-uuid',
            'status' => MinorFamilySupportTransfer::STATUS_FAILED_UNRECONCILED,
            'provider_name' => 'mtn_momo',
            'recipient_name' => 'Gogo Dlamini',
            'recipient_msisdn' => '26876123456',
            'amount' => '250.00',
            'asset_code' => 'SZL',
            'note' => 'School support',
            'provider_reference_id' => $reference,
            'failed_reason' => 'wallet_refund_failed',
            'idempotency_key' => hash('sha256', 'support-idempotency-1'),
        ]);

        $transaction = MtnMomoTransaction::query()->create([
            'id' => $transfer->id,
            'user_id' => $user->id,
            'idempotency_key' => hash('sha256', 'idempotency-2'),
            'type' => MtnMomoTransaction::TYPE_DISBURSEMENT,
            'amount' => '250.00',
            'currency' => 'SZL',
            'status' => MtnMomoTransaction::STATUS_FAILED,
            'party_msisdn' => '26876123456',
            'mtn_reference_id' => $reference,
            'wallet_debited_at' => now()->subMinute(),
            'wallet_refunded_at' => null,
        ]);
        $transaction->forceFill([
            'context_type' => MinorFamilySupportTransfer::class,
            'context_uuid' => $transfer->id,
        ])->save();

        $result = app(MoneyMovementTransactionInspector::class)->inspect(reference: $reference);

        $this->assertSame(MinorFamilySupportTransfer::class, $result['minor_family_context']['context_type']);
        $this->assertSame($transfer->id, $result['minor_family_context']['context_uuid']);
        $this->assertSame($reference, $result['minor_family_context']['provider_reference_id']);
        $this->assertSame(MinorFamilySupportTransfer::STATUS_FAILED_UNRECONCILED, $result['minor_family_context']['support_transfer']['status']);
        $this->assertContains(
            'Minor family support transfer failed without a recorded wallet refund. Funds-at-risk reconciliation is required.',
            $result['warnings'],
        );
    }
}
