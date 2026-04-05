<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Services;

use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Models\MoneyRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MaphaPayMoneyMovementTelemetry
{
    public const METRIC_RETRIES_TOTAL = 'metrics:maphapay:money_movement:retries_total';

    public const METRIC_VERIFICATION_FAILURES_TOTAL = 'metrics:maphapay:money_movement:verification_failures_total';

    public const METRIC_DUPLICATE_ACCEPTANCE_PREVENTED_TOTAL = 'metrics:maphapay:money_movement:duplicate_acceptance_prevented_total';

    public const METRIC_ROLLOUT_BLOCKED_TOTAL = 'metrics:maphapay:money_movement:rollout_blocked_total';

    public function logEvent(
        string $event,
        array $context = [],
        string $level = 'info',
        bool $audit = false,
    ): void {
        $payload = array_filter(
            array_merge([
                'domain' => 'maphapay_money_movement',
                'event'  => $event,
            ], $context),
            static fn (mixed $value): bool => $value !== null && $value !== [],
        );

        $channel = $audit
            ? (string) config('maphapay_migration.observability.audit_channel', 'audit')
            : (string) config('maphapay_migration.observability.log_channel', 'structured');

        Log::channel($channel)->log($level, 'maphapay.compat.money_movement', $payload);
    }

    public function logRolloutBlocked(Request $request, string $flag): void
    {
        $this->incrementCounter(self::METRIC_ROLLOUT_BLOCKED_TOTAL);

        $this->logEvent('rollout_blocked', $this->requestContext($request, [
            'flag' => $flag,
        ]), 'warning');
    }

    public function logIdempotencyReplay(Request $request, string $idempotencyKey, array $context = []): void
    {
        if (! $this->isMoneyMovementPath($request->path())) {
            return;
        }

        $this->incrementCounter(self::METRIC_RETRIES_TOTAL);

        $this->logEvent('idempotency_replay', $this->requestContext($request, array_merge($context, [
            'idempotency_key_suffix' => $this->maskIdempotencyKey($idempotencyKey),
        ])));
    }

    public function logIdempotencyConflict(Request $request, string $idempotencyKey, string $reason): void
    {
        if (! $this->isMoneyMovementPath($request->path())) {
            return;
        }

        $this->logEvent('idempotency_conflict', $this->requestContext($request, [
            'idempotency_key_suffix' => $this->maskIdempotencyKey($idempotencyKey),
            'reason'                 => $reason,
        ]), 'warning');
    }

    public function logVerificationFailure(
        Request $request,
        string $method,
        ?string $remark,
        ?string $trx,
        string $message,
        int $status,
        ?AuthorizedTransaction $transaction = null,
    ): void {
        $this->incrementCounter(self::METRIC_VERIFICATION_FAILURES_TOTAL);

        $this->logEvent('verification_failed', $this->requestContext($request, [
            'verification_method' => $method,
            'remark'              => $remark,
            'trx'                 => $trx,
            'status_code'         => $status,
            'message'             => $message,
        ] + $this->transactionContext($transaction)), 'warning');
    }

    public function logDuplicateAcceptancePrevented(
        Request $request,
        MoneyRequest $moneyRequest,
        string $reason,
        ?string $idempotencyKey = null,
    ): void {
        $this->incrementCounter(self::METRIC_DUPLICATE_ACCEPTANCE_PREVENTED_TOTAL);

        $this->logEvent('duplicate_acceptance_prevented', $this->requestContext($request, [
            'money_request_id'       => $moneyRequest->id,
            'money_request_status'   => $moneyRequest->status,
            'reason'                 => $reason,
            'idempotency_key_suffix' => $this->maskIdempotencyKey($idempotencyKey),
        ]), 'warning', true);
    }

    public function logMoneyRequestTransition(
        MoneyRequest $moneyRequest,
        string $fromStatus,
        string $toStatus,
        array $context = [],
    ): void {
        $this->logEvent('money_request_transition', array_merge([
            'money_request_id'  => $moneyRequest->id,
            'from_status'       => $fromStatus,
            'to_status'         => $toStatus,
            'requester_user_id' => $moneyRequest->requester_user_id,
            'recipient_user_id' => $moneyRequest->recipient_user_id,
            'amount'            => $moneyRequest->amount,
            'asset_code'        => $moneyRequest->asset_code,
            'trx'               => $moneyRequest->trx,
        ], $context), 'info', true);
    }

    public function logOperationReplay(int $userId, string $operationType, string $idempotencyKey): void
    {
        if (! in_array($operationType, ['send_money', 'request_money_received'], true)) {
            return;
        }

        $this->incrementCounter(self::METRIC_RETRIES_TOTAL);

        $this->logEvent('operation_record_replay', [
            'user_id'                => $userId,
            'operation_type'         => $operationType,
            'idempotency_key_suffix' => $this->maskIdempotencyKey($idempotencyKey),
        ]);
    }

    /**
     * @return array{retries_total: int, verification_failures_total: int, duplicate_acceptance_prevented_total: int, rollout_blocked_total: int}
     */
    public function metricSnapshot(): array
    {
        return [
            'retries_total'                        => (int) Cache::get(self::METRIC_RETRIES_TOTAL, 0),
            'verification_failures_total'          => (int) Cache::get(self::METRIC_VERIFICATION_FAILURES_TOTAL, 0),
            'duplicate_acceptance_prevented_total' => (int) Cache::get(self::METRIC_DUPLICATE_ACCEPTANCE_PREVENTED_TOTAL, 0),
            'rollout_blocked_total'                => (int) Cache::get(self::METRIC_ROLLOUT_BLOCKED_TOTAL, 0),
        ];
    }

    public function exceptionMessage(Throwable $throwable): string
    {
        return $throwable->getMessage() !== ''
            ? $throwable->getMessage()
            : class_basename($throwable);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function requestContext(Request $request, array $context = []): array
    {
        return array_merge([
            'method'  => $request->method(),
            'path'    => $request->path(),
            'user_id' => $request->user()?->getAuthIdentifier(),
            'ip'      => $request->ip(),
        ], $context);
    }

    public function maskIdempotencyKey(?string $idempotencyKey): ?string
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return null;
        }

        return substr($idempotencyKey, -8);
    }

    private function incrementCounter(string $key): void
    {
        Cache::add($key, 0, now()->addDay());
        Cache::increment($key);
    }

    /**
     * @return array<string, mixed>
     */
    public function transactionContext(?AuthorizedTransaction $transaction): array
    {
        if ($transaction === null) {
            return [];
        }

        $payload = is_array($transaction->payload) ? $transaction->payload : [];
        $result = is_array($transaction->result) ? $transaction->result : [];
        $policy = is_array($payload['_verification_policy'] ?? null) ? $payload['_verification_policy'] : [];

        return array_filter([
            'transaction_id'         => $transaction->id,
            'trx'                    => $transaction->trx,
            'reference'              => $result['reference'] ?? null,
            'sender_account_uuid'    => $payload['from_account_uuid'] ?? null,
            'recipient_account_uuid' => $payload['to_account_uuid'] ?? null,
            'sender_user_id'         => $transaction->user_id,
            'recipient_user_id'      => $payload['recipient_user_id'] ?? $payload['requester_user_id'] ?? null,
            'amount'                 => $payload['amount'] ?? $result['amount'] ?? null,
            'asset_code'             => $payload['asset_code'] ?? $result['asset_code'] ?? null,
            'status'                 => $transaction->status,
            'failure_reason'         => $transaction->failure_reason,
            'verification_policy'    => $policy['verification_type'] ?? $transaction->verification_type,
            'risk_reason'            => $policy['risk_reason'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function isMoneyMovementPath(string $path): bool
    {
        return preg_match(
            '#^(api/)?(send-money/store|request-money/store|request-money/received-store/|verification-process/verify/)#',
            $path,
        ) === 1;
    }
}
