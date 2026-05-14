<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Providers\MtnMomo;

use App\Domain\MtnMomo\Services\MtnMomoClient;
use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\Wallet\Contracts\WalletLinkResult;
use App\Domain\Wallet\Contracts\WalletMovementRequest;
use App\Domain\Wallet\Contracts\WalletMovementResult;
use App\Domain\Wallet\Contracts\WalletMovementStatus;
use App\Domain\Wallet\Contracts\WalletProviderAdapter;
use Illuminate\Support\Str;
use RuntimeException;

final class MtnMomoAdapter implements WalletProviderAdapter
{
    public function __construct(
        private readonly MtnMomoClient $client,
    ) {
    }

    public function providerId(): string
    {
        return 'mtn_momo';
    }

    public function link(string $identifier, string $currency): WalletLinkResult
    {
        $msisdn = $this->normaliseMsisdn($identifier);

        return new WalletLinkResult(
            providerId: $this->providerId(),
            providerAccountRef: $msisdn,
            displayName: 'MTN MoMo ' . $msisdn,
            linkToken: base64_encode("mtn:{$msisdn}"),
            linkStatus: WalletLinkResult::LINK_STATUS_ACTIVE,
        );
    }

    public function collect(WalletMovementRequest $req): WalletMovementResult
    {
        $referenceId = (string) Str::uuid();

        try {
            $this->client->requestToPay(
                $referenceId,
                $this->amountMajor($req->amountMinor),
                strtoupper($req->currency),
                $req->providerAccountRef,
                $req->idempotencyKey,
                $req->memo,
                $req->memo,
            );
        } catch (RuntimeException $exception) {
            return new WalletMovementResult(
                providerRequestId: $referenceId,
                status: WalletMovementResult::STATUS_FAILED,
                failureReason: $exception->getMessage(),
            );
        }

        return new WalletMovementResult(
            providerRequestId: $referenceId,
            status: WalletMovementResult::STATUS_PENDING,
            failureReason: null,
        );
    }

    public function disburse(WalletMovementRequest $req): WalletMovementResult
    {
        $referenceId = (string) Str::uuid();

        try {
            $this->client->disburse(
                $referenceId,
                $this->amountMajor($req->amountMinor),
                strtoupper($req->currency),
                $req->providerAccountRef,
                $req->idempotencyKey,
                $req->memo,
                $req->memo,
            );
        } catch (RuntimeException $exception) {
            return new WalletMovementResult(
                providerRequestId: $referenceId,
                status: WalletMovementResult::STATUS_FAILED,
                failureReason: $exception->getMessage(),
            );
        }

        return new WalletMovementResult(
            providerRequestId: $referenceId,
            status: WalletMovementResult::STATUS_PENDING,
            failureReason: null,
        );
    }

    public function status(string $providerRequestId): WalletMovementStatus
    {
        $payload = $this->client->getRequestToPayStatus($providerRequestId);
        $mtnStatus = strtoupper((string) ($payload['status'] ?? 'PENDING'));

        $status = match ($mtnStatus) {
            'SUCCESSFUL' => WalletMovementStatus::STATUS_SUCCESSFUL,
            'FAILED'     => WalletMovementStatus::STATUS_FAILED,
            default      => WalletMovementStatus::STATUS_PENDING,
        };

        $failureReason = $status === WalletMovementStatus::STATUS_FAILED
            ? $this->stringOrNull($payload['reason'] ?? null)
            : null;

        return new WalletMovementStatus(
            providerRequestId: $providerRequestId,
            status: $status,
            failureReason: $failureReason,
            settledAt: $status === WalletMovementStatus::STATUS_PENDING ? null : time(),
        );
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public function verifyWebhookSignature(string $rawBody, array $headers): bool
    {
        $lowerHeaders = array_change_key_case($headers, CASE_LOWER);

        if ((bool) config('mtn_momo.verify_callback_token') !== false) {
            $expectedToken = (string) config('mtn_momo.callback_token');
            $actualToken = $this->headerValue($lowerHeaders, 'x-callback-token');

            if ($actualToken === '' || ! hash_equals($expectedToken, $actualToken)) {
                return false;
            }
        }

        if ((bool) config('mtn_momo.verify_hmac_signature') !== false) {
            $hmacKey = (string) config('mtn_momo.hmac_key');
            $expectedSignature = hash_hmac('sha256', $rawBody, $hmacKey);
            $actualSignature = $this->headerValue($lowerHeaders, 'x-signature');

            if ($actualSignature === '' || ! hash_equals($expectedSignature, $actualSignature)) {
                return false;
            }
        }

        return true;
    }

    private function amountMajor(int $amountMinor): string
    {
        return MoneyConverter::toMajorUnitString($amountMinor, 2);
    }

    private function normaliseMsisdn(string $msisdn): string
    {
        return preg_replace('/\D+/', '', $msisdn) ?? '';
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function headerValue(array $headers, string $name): string
    {
        $value = $headers[$name] ?? '';

        if (is_array($value)) {
            $value = $value[0] ?? '';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $stringValue = (string) $value;

        return $stringValue === '' ? null : $stringValue;
    }
}
