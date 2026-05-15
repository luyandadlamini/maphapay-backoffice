<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Mock\Jobs;

use App\Domain\Wallet\Mock\MockFailureRules;
use App\Domain\Wallet\Mock\MockWalletStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class DispatchMockWalletCallbackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $providerId,
        private readonly string $kind,
        private readonly string $referenceId,
    ) {
    }

    public function handle(MockWalletStore $store): void
    {
        $request = $store->getRequest($this->providerId, $this->kind, $this->referenceId);
        if ($request === null) {
            return;
        }

        $accountRef = (string) ($request['account_ref'] ?? '');
        $outcome = MockFailureRules::callbackOutcome($accountRef);

        if ($outcome === MockFailureRules::CB_SILENT_TIMEOUT) {
            return;
        }

        if ($outcome === MockFailureRules::CB_SUCCESSFUL) {
            $this->moveSuccessfulBalance($store, $request, $accountRef);
            $status = 'SUCCESSFUL';
            $reason = null;
        } else {
            $status = 'FAILED';
            $reason = substr($outcome, strlen('failed:'));
        }

        $financialTransactionId = $status === 'SUCCESSFUL' ? (string) Str::uuid() : null;
        $store->updateRequest($this->providerId, $this->kind, $this->referenceId, array_filter([
            'status'                   => $status,
            'reason'                   => $reason,
            'financial_transaction_id' => $financialTransactionId,
        ], static fn (mixed $value): bool => $value !== null));

        $body = [
            'referenceId' => $this->referenceId,
            'status'      => $status,
        ];

        if ($financialTransactionId !== null) {
            $body['financialTransactionId'] = $financialTransactionId;
        }

        if ($reason !== null) {
            $body['reason'] = $reason;
        }

        $rawBody = json_encode($body, JSON_THROW_ON_ERROR);
        $hmacKey = (string) config("wallet_mocks.providers.{$this->providerId}.hmac_key", '');

        Http::withBody($rawBody, 'application/json')
            ->withHeaders([
                'X-Callback-Token' => (string) config("wallet_mocks.providers.{$this->providerId}.callback_token", ''),
                'X-Signature'      => hash_hmac('sha256', $rawBody, $hmacKey),
            ])
            ->post((string) config("wallet_mocks.providers.{$this->providerId}.callback_url", ''));
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function moveSuccessfulBalance(MockWalletStore $store, array $request, string $accountRef): void
    {
        $amountMinor = (int) ($request['amount_minor'] ?? 0);

        if ($this->kind === 'collect') {
            $store->debitAccount($this->providerId, $accountRef, $amountMinor);

            return;
        }

        $store->creditAccount($this->providerId, $accountRef, $amountMinor);
    }
}
