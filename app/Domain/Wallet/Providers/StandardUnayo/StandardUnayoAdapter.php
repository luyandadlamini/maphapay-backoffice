<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Providers\StandardUnayo;

use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\StandardUnayo\Services\StandardUnayoClient;
use App\Domain\Wallet\Contracts\WalletLinkResult;
use App\Domain\Wallet\Contracts\WalletMovementRequest;
use App\Domain\Wallet\Contracts\WalletMovementResult;
use App\Domain\Wallet\Contracts\WalletMovementStatus;
use App\Domain\Wallet\Contracts\WalletProviderAdapter;
use Illuminate\Support\Str;
use RuntimeException;

final class StandardUnayoAdapter implements WalletProviderAdapter
{
    public function __construct(
        private readonly StandardUnayoClient $client,
    ) {
    }

    public function providerId(): string
    {
        return 'standard_unayo';
    }

    public function link(string $identifier, string $currency): WalletLinkResult
    {
        $msisdn = $this->normaliseMsisdn($identifier);

        return new WalletLinkResult(
            providerId: $this->providerId(),
            providerAccountRef: $msisdn,
            displayName: 'Unayo ' . $msisdn,
            linkToken: base64_encode("standard_unayo:{$msisdn}"),
            linkStatus: WalletLinkResult::LINK_STATUS_ACTIVE,
        );
    }

    public function collect(WalletMovementRequest $req): WalletMovementResult
    {
        $referenceId = (string) Str::uuid();

        try {
            $this->client->initiateCashIn(
                $referenceId,
                $this->amountMajor($req->amountMinor),
                strtoupper($req->currency),
                $req->providerAccountRef,
                $req->idempotencyKey,
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
            $this->client->initiateCashOut(
                $referenceId,
                $this->amountMajor($req->amountMinor),
                strtoupper($req->currency),
                $req->providerAccountRef,
                $req->idempotencyKey,
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
        $payload = $this->client->getCashInStatus($providerRequestId);
        $remote = strtoupper((string) ($payload['status'] ?? 'PENDING'));

        $status = match ($remote) {
            'SETTLED', 'SUCCESSFUL', 'COMPLETED' => WalletMovementStatus::STATUS_SUCCESSFUL,
            'REVERSED', 'FAILED', 'REJECTED' => WalletMovementStatus::STATUS_FAILED,
            default => WalletMovementStatus::STATUS_PENDING,
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
        $lower = array_change_key_case($headers, CASE_LOWER);

        if ((bool) config('standard_unayo.verify_callback_token') !== false) {
            $expected = (string) config('standard_unayo.callback_token');
            $actual = $this->headerValue($lower, 'x-callback-token');

            if ($actual === '' || ! hash_equals($expected, $actual)) {
                return false;
            }
        }

        if ((bool) config('standard_unayo.verify_hmac_signature') !== false) {
            $key = (string) config('standard_unayo.hmac_key');
            $expected = hash_hmac('sha256', $rawBody, $key);
            $actual = $this->headerValue($lower, 'x-signature');

            if ($actual === '' || ! hash_equals($expected, $actual)) {
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

        $string = (string) $value;

        return $string === '' ? null : $string;
    }
}
