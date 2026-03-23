<?php

declare(strict_types=1);

namespace App\Domain\AgentProtocol\Services;

use App\Domain\AgentProtocol\DataObjects\VerifiableDigitalCredential;
use App\Domain\AgentProtocol\Enums\VdcType;
use App\Domain\AgentProtocol\Models\AgentMandate;
use Illuminate\Support\Facades\Log;

/**
 * SD-JWT-VC credential issuance and verification service.
 *
 * Issues Verifiable Digital Credentials for AP2 mandates,
 * providing cryptographic proof of authorization.
 */
class VdcService
{
    /**
     * Issue a VDC for a mandate.
     */
    public function issueCredential(string $mandateId, VdcType $type, string $issuerDid): VerifiableDigitalCredential
    {
        $mandate = AgentMandate::where('uuid', $mandateId)->firstOrFail();

        $claims = [
            'mandate_id'   => $mandateId,
            'mandate_type' => $mandate->type,
            'payload_hash' => hash('sha256', (string) json_encode($mandate->payload)),
            'amount_cents' => $mandate->amount_cents,
            'currency'     => $mandate->currency,
        ];

        // In demo mode, use HMAC-SHA256 signature
        $signaturePayload = json_encode([
            'type'    => $type->value,
            'issuer'  => $issuerDid,
            'subject' => $mandate->subject_did,
            'claims'  => $claims,
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', $signaturePayload, (string) config('app.key'));

        $vdc = new VerifiableDigitalCredential(
            type: $type->value,
            issuer: $issuerDid,
            subject: $mandate->subject_did,
            claims: $claims,
            disclosures: [$mandate->type, $mandate->issuer_did],
            signature: $signature,
            issuedAt: gmdate('Y-m-d\TH:i:s\Z'),
            expiresAt: $mandate->expires_at,
            mandateId: $mandateId,
        );

        // Store VDC hash on the mandate
        $mandate->update(['vdc_hash' => $vdc->computeHash()]);

        Log::info('AP2: VDC issued', [
            'mandate_id' => $mandateId,
            'vdc_type'   => $type->value,
            'issuer'     => $issuerDid,
        ]);

        return $vdc;
    }

    /**
     * Verify a VDC signature and claims.
     */
    public function verifyCredential(VerifiableDigitalCredential $vdc): bool
    {
        // Recompute signature
        $signaturePayload = json_encode([
            'type'    => $vdc->type,
            'issuer'  => $vdc->issuer,
            'subject' => $vdc->subject,
            'claims'  => $vdc->claims,
        ], JSON_THROW_ON_ERROR);

        $expectedSignature = hash_hmac('sha256', $signaturePayload, (string) config('app.key'));

        if (! hash_equals($expectedSignature, $vdc->signature)) {
            Log::warning('AP2: VDC signature verification failed', [
                'mandate_id' => $vdc->mandateId,
                'issuer'     => $vdc->issuer,
            ]);

            return false;
        }

        // Check expiry
        if ($vdc->expiresAt !== null && strtotime($vdc->expiresAt) < time()) {
            Log::warning('AP2: VDC expired', [
                'mandate_id' => $vdc->mandateId,
                'expires_at' => $vdc->expiresAt,
            ]);

            return false;
        }

        // If mandate exists, verify hash matches
        if ($vdc->mandateId !== null) {
            $mandate = AgentMandate::where('uuid', $vdc->mandateId)->first();

            if ($mandate instanceof AgentMandate && $mandate->vdc_hash !== null) {
                $computedHash = $vdc->computeHash();

                if (! hash_equals($mandate->vdc_hash, $computedHash)) {
                    Log::warning('AP2: VDC hash mismatch', [
                        'mandate_id' => $vdc->mandateId,
                        'expected'   => $mandate->vdc_hash,
                        'computed'   => $computedHash,
                    ]);

                    return false;
                }
            }
        }

        return true;
    }
}
