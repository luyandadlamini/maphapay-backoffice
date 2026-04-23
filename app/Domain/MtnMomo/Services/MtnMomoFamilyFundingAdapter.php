<?php

declare(strict_types=1);

namespace App\Domain\MtnMomo\Services;

use App\Models\MtnMomoTransaction;
use Illuminate\Support\Str;

class MtnMomoFamilyFundingAdapter
{
    public function __construct(
        private readonly MtnMomoClient $client,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public function initiateInboundCollection(array $payload): array
    {
        $referenceId = (string) ($payload['provider_reference_id'] ?? Str::uuid());
        $amount = $this->normaliseAmount($payload['amount'] ?? null);
        $assetCode = $this->normaliseAssetCode($payload['asset_code'] ?? null);
        $payerMsisdn = (string) ($payload['payer_msisdn'] ?? '');
        $idempotencyKey = (string) ($payload['idempotency_key'] ?? '');
        $payerMessage = (string) ($payload['payer_message'] ?? 'MaphaPay family funding');
        $payeeNote = (string) ($payload['note'] ?? '');

        $this->client->assertConfigured();
        $this->client->requestToPay(
            $referenceId,
            $amount,
            $assetCode,
            $payerMsisdn,
            $idempotencyKey,
            $payerMessage,
            $payeeNote,
        );

        return [
            'provider_name' => 'mtn_momo',
            'provider_reference_id' => $referenceId,
            'provider_status' => MtnMomoTransaction::STATUS_PENDING,
            'transaction_type' => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
            'party_msisdn' => $payerMsisdn,
            'amount' => $amount,
            'asset_code' => $assetCode,
            'note' => $payeeNote,
            'idempotency_key' => $idempotencyKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public function initiateOutboundDisbursement(array $payload): array
    {
        $referenceId = (string) ($payload['provider_reference_id'] ?? Str::uuid());
        $amount = $this->normaliseAmount($payload['amount'] ?? null);
        $assetCode = $this->normaliseAssetCode($payload['asset_code'] ?? null);
        $payeeMsisdn = (string) ($payload['recipient_msisdn'] ?? '');
        $idempotencyKey = (string) ($payload['idempotency_key'] ?? '');
        $payerMessage = (string) ($payload['payer_message'] ?? ($payload['note'] ?? ''));
        $payeeNote = (string) ($payload['payee_note'] ?? 'MaphaPay family support');

        $this->client->assertConfigured();
        $this->client->disburse(
            $referenceId,
            $amount,
            $assetCode,
            $payeeMsisdn,
            $idempotencyKey,
            $payerMessage,
            $payeeNote,
        );

        return [
            'provider_name' => 'mtn_momo',
            'provider_reference_id' => $referenceId,
            'provider_status' => MtnMomoTransaction::STATUS_PENDING,
            'transaction_type' => MtnMomoTransaction::TYPE_DISBURSEMENT,
            'party_msisdn' => $payeeMsisdn,
            'amount' => $amount,
            'asset_code' => $assetCode,
            'note' => (string) ($payload['note'] ?? ''),
            'idempotency_key' => $idempotencyKey,
        ];
    }

    private function normaliseAmount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function normaliseAssetCode(mixed $assetCode): string
    {
        return strtoupper(trim((string) $assetCode));
    }
}
