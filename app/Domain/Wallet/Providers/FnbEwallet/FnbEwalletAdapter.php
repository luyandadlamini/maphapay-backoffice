<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Providers\FnbEwallet;

use App\Domain\FnbEwallet\Services\FnbEwalletClient;
use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\Wallet\Contracts\WalletLinkResult;
use App\Domain\Wallet\Contracts\WalletMovementRequest;
use App\Domain\Wallet\Contracts\WalletMovementResult;
use App\Domain\Wallet\Contracts\WalletMovementStatus;
use App\Domain\Wallet\Contracts\WalletProviderAdapter;
use Illuminate\Support\Str;
use RuntimeException;

final class FnbEwalletAdapter implements WalletProviderAdapter
{
    public function __construct(
        private readonly FnbEwalletClient $client,
    ) {
    }

    public function providerId(): string
    {
        return 'fnb_ewallet';
    }

    public function link(string $identifier, string $currency): WalletLinkResult
    {
        $mobile = $this->normaliseMobile($identifier);

        return new WalletLinkResult(
            providerId: $this->providerId(),
            providerAccountRef: $mobile,
            displayName: 'FNB eWallet ' . $mobile,
            linkToken: base64_encode("fnb_ewallet:{$mobile}"),
            linkStatus: WalletLinkResult::LINK_STATUS_ACTIVE,
        );
    }

    public function collect(WalletMovementRequest $req): WalletMovementResult
    {
        $referenceId = (string) Str::uuid();

        try {
            $this->client->initiateCredit(
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
            $this->client->initiateTransfer(
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
        $payload = $this->client->getCreditStatus($providerRequestId);
        $remote = strtoupper((string) ($payload['status'] ?? 'PENDING'));

        $status = match ($remote) {
            'SUCCESSFUL', 'SUCCESS', 'COMPLETED', 'POSTED' => WalletMovementStatus::STATUS_SUCCESSFUL,
            'FAILED', 'REJECTED', 'DECLINED' => WalletMovementStatus::STATUS_FAILED,
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

        if ((bool) config('fnb_ewallet.verify_callback_token') !== false) {
            $expected = (string) config('fnb_ewallet.callback_token');
            $actual = $this->headerValue($lower, 'x-callback-token');

            if ($actual === '' || ! hash_equals($expected, $actual)) {
                return false;
            }
        }

        if ((bool) config('fnb_ewallet.verify_hmac_signature') !== false) {
            $key = (string) config('fnb_ewallet.hmac_key');
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

    private function normaliseMobile(string $mobile): string
    {
        return preg_replace('/\D+/', '', $mobile) ?? '';
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
