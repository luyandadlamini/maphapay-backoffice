<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Services;

use App\Domain\AuthorizedTransaction\Contracts\AuthorizedTransactionHandlerInterface;
use App\Domain\AuthorizedTransaction\Contracts\MoneyMovementRiskSignalProviderInterface;
use App\Domain\AuthorizedTransaction\Exceptions\InvalidTransactionPinException;
use App\Domain\AuthorizedTransaction\Exceptions\TransactionNotFoundException;
use App\Domain\AuthorizedTransaction\Exceptions\TransactionPinNotSetException;
use App\Domain\AuthorizedTransaction\Handlers\RequestMoneyHandler;
use App\Domain\AuthorizedTransaction\Handlers\RequestMoneyReceivedHandler;
use App\Domain\AuthorizedTransaction\Handlers\ScheduledSendHandler;
use App\Domain\AuthorizedTransaction\Handlers\SendMoneyHandler;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Shared\OperationRecord\OperationRecordService;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Orchestrates the two-step money-movement verification flow.
 *
 * Step 1 — initiate():
 *   Creates an AuthorizedTransaction record and returns the trx reference
 *   and the next verification step (otp | pin | none) to the controller.
 *
 * Step 2 — verify() / finalize():
 *   Validates OTP or PIN, then dispatches the correct handler atomically.
 *   The status is flipped to "completed" inside a DB transaction before
 *   the handler runs, so concurrent verify calls cannot both succeed.
 */
class AuthorizedTransactionManager
{
    /** OTP validity window in minutes. */
    private const OTP_TTL_MINUTES = 10;

    /** Authorized transaction expiry in minutes. */
    private const TXN_TTL_MINUTES = 60;

    /** Maximum invalid OTP/PIN attempts before the authorization is failed closed. */
    private const MAX_VERIFICATION_FAILURES = 5;

    /**
     * Remark → handler class mapping.
     * Add new operation types here without touching the controllers.
     *
     * @var array<string, class-string<AuthorizedTransactionHandlerInterface>>
     */
    private const HANDLER_MAP = [
        AuthorizedTransaction::REMARK_SEND_MONEY             => SendMoneyHandler::class,
        AuthorizedTransaction::REMARK_SCHEDULED_SEND         => ScheduledSendHandler::class,
        AuthorizedTransaction::REMARK_REQUEST_MONEY          => RequestMoneyHandler::class,
        AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED => RequestMoneyReceivedHandler::class,
    ];

    public function __construct(
        private readonly SendMoneyHandler $sendMoneyHandler,
        private readonly ScheduledSendHandler $scheduledSendHandler,
        private readonly RequestMoneyHandler $requestMoneyHandler,
        private readonly RequestMoneyReceivedHandler $requestMoneyReceivedHandler,
        private readonly MoneyMovementRiskSignalProviderInterface $riskSignals,
        private readonly OperationRecordService $operationRecordService,
    ) {
    }

    /**
     * Step 1: Create an authorized transaction record.
     *
     * @param array<string, mixed> $payload         Normalized operation parameters.
     *                                              Amount MUST be a major-unit string (e.g. "25.10").
     * @param string               $idempotencyKey  Optional idempotency key from the HTTP request.
     *                                              When provided, finalizeAtomically() wraps handler
     *                                              execution with a domain-level OperationRecord guard
     *                                              that prevents duplicate execution even after the
     *                                              HTTP-layer cache (24 h) expires.
     * @return AuthorizedTransaction
     */
    public function initiate(
        int $userId,
        string $remark,
        array $payload,
        string $verificationType = AuthorizedTransaction::VERIFICATION_OTP,
        string $idempotencyKey = '',
    ): AuthorizedTransaction {
        if (! isset(self::HANDLER_MAP[$remark])) {
            throw new InvalidArgumentException("Unknown remark: {$remark}");
        }

        $trx = $this->generateTrx();

        // Embed the idempotency key into the payload so finalize() can read it
        // without requiring a separate column on authorized_transactions.
        if ($idempotencyKey !== '') {
            $payload['_idempotency_key'] = $idempotencyKey;
        }

        $txn = AuthorizedTransaction::create([
            'user_id'           => $userId,
            'remark'            => $remark,
            'trx'               => $trx,
            'payload'           => $payload,
            'status'            => AuthorizedTransaction::STATUS_PENDING,
            'verification_type' => $verificationType,
            'expires_at'        => now()->addMinutes(self::TXN_TTL_MINUTES),
        ]);

        return $txn;
    }

    /**
     * Dispatch an OTP for an existing pending transaction.
     *
     * Generates a 6-digit OTP, hashes it, and returns the raw code for
     * delivery (SMS/email). The caller is responsible for sending the code.
     */
    public function dispatchOtp(AuthorizedTransaction $txn): string
    {
        $this->assertPendingAndNotExpired($txn);

        $otp = (string) random_int(100000, 999999);

        $txn->update([
            'otp_hash'       => Hash::make($otp),
            'otp_sent_at'    => now(),
            'otp_expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
        ]);

        return $otp;
    }

    /**
     * Step 2a: Verify OTP and finalize the operation.
     *
     * @return array<string, mixed> Handler result data (stored + returned to mobile).
     * @throws RuntimeException     On OTP mismatch, expiry, or duplicate execution.
     */
    public function verifyOtp(string $trx, int $userId, string $otp): array
    {
        $txn = $this->findForUser($trx, $userId);

        $this->assertPendingAndNotExpired($txn);

        if ($txn->remark === AuthorizedTransaction::REMARK_SCHEDULED_SEND
            && $txn->verification_confirmed_at !== null) {
            return $this->scheduledSendVerificationResult($txn);
        }

        if ($txn->isOtpExpired()) {
            $this->expirePendingTransaction($txn, 'OTP has expired. Please request a new one.');
            throw new RuntimeException('OTP has expired. Please request a new one.');
        }

        if (! $txn->otp_hash || ! Hash::check($otp, $txn->otp_hash)) {
            $this->recordVerificationFailure($txn, 'Invalid OTP.');

            if (! $txn->fresh()?->isPending()) {
                throw new RuntimeException('Verification attempt limit exceeded. This transaction has been cancelled.');
            }

            throw new RuntimeException('Invalid OTP.');
        }

        if ($txn->remark === AuthorizedTransaction::REMARK_SCHEDULED_SEND) {
            return $this->markScheduledSendVerified($txn);
        }

        return $this->finalizeAtomically($txn);
    }

    /**
     * Step 2b: Verify PIN and finalize the operation.
     *
     * @return array<string, mixed> Handler result data.
     * @throws TransactionPinNotSetException  When the user has not set a transaction PIN.
     * @throws InvalidTransactionPinException When the submitted PIN does not match.
     * @throws RuntimeException               On duplicate execution or expired transaction.
     */
    public function verifyPin(string $trx, int $userId, string $pin): array
    {
        $txn = $this->findForUser($trx, $userId);

        $this->assertPendingAndNotExpired($txn);

        if ($txn->remark === AuthorizedTransaction::REMARK_SCHEDULED_SEND
            && $txn->verification_confirmed_at !== null) {
            return $this->scheduledSendVerificationResult($txn);
        }

        $user = $txn->user;

        if (! $user) {
            throw new RuntimeException('Transaction user not found.');
        }

        if ($user->transaction_pin === null) {
            throw new TransactionPinNotSetException(
                'Transaction PIN has not been set for this account.'
            );
        }

        if (! Hash::check($pin, $user->transaction_pin)) {
            $this->recordVerificationFailure($txn, 'Invalid transaction PIN.');

            if (! $txn->fresh()?->isPending()) {
                throw new RuntimeException('Verification attempt limit exceeded. This transaction has been cancelled.');
            }

            throw new InvalidTransactionPinException('Invalid transaction PIN.');
        }

        if ($txn->remark === AuthorizedTransaction::REMARK_SCHEDULED_SEND) {
            return $this->markScheduledSendVerified($txn);
        }

        return $this->finalizeAtomically($txn);
    }

    /**
     * Step 2c: Verify a successful biometric approval and finalize the operation.
     *
     * @return array<string, mixed>
     */
    public function verifyBiometric(string $trx, int $userId): array
    {
        $txn = $this->findForUser($trx, $userId);

        $this->assertPendingAndNotExpired($txn);

        if ($txn->verification_type !== AuthorizedTransaction::VERIFICATION_PIN) {
            throw new RuntimeException('Biometric verification is only available for PIN-class transactions.');
        }

        if ($txn->remark === AuthorizedTransaction::REMARK_SCHEDULED_SEND
            && $txn->verification_confirmed_at !== null) {
            return $this->scheduledSendVerificationResult($txn);
        }

        if ($txn->remark === AuthorizedTransaction::REMARK_SCHEDULED_SEND) {
            return $this->markScheduledSendVerified($txn);
        }

        return $this->finalizeAtomically($txn);
    }

    /**
     * Directly finalize without verification (for VERIFICATION_NONE flows or
     * scheduled send execution from a console command).
     *
     * @return array<string, mixed>
     */
    public function finalize(AuthorizedTransaction $txn): array
    {
        $this->assertPendingAndNotExpired($txn);
        $this->assertScheduledSendExecutable($txn);

        return $this->finalizeAtomically($txn);
    }

    /**
     * Atomically flip status to completed and dispatch the handler.
     *
     * Uses a single UPDATE ... WHERE status = 'pending' to ensure exactly one
     * caller succeeds even under concurrent verify requests.
     *
     * @return array<string, mixed>
     * @throws RuntimeException If the atomic claim fails (already completed/claimed).
     */
    private function finalizeAtomically(AuthorizedTransaction $txn): array
    {
        $claimed = false;

        try {
            return DB::transaction(function () use ($txn, &$claimed): array {
                // Atomic check-and-set: only one concurrent verify call can claim this.
                $claimCount = DB::table('authorized_transactions')
                    ->where('id', $txn->id)
                    ->where('status', AuthorizedTransaction::STATUS_PENDING)
                    ->update(['status' => AuthorizedTransaction::STATUS_COMPLETED, 'updated_at' => now()]);

                if ($claimCount === 0) {
                    // Already completed by another concurrent request — return stored result.
                    $txn->refresh();

                    if ($txn->isCompleted() && $txn->result !== null) {
                        return $txn->result;
                    }

                    throw new RuntimeException(
                        'This transaction has already been processed or is no longer pending.'
                    );
                }

                $claimed = true;
                $handler = $this->resolveHandler($txn->remark);

                $this->assertPreExecutionRiskAllows($txn);

                $result = $this->executeWithIdempotencyGuard(
                    $txn,
                    fn (): array => $handler->handle($txn),
                );

                $txn->update(['result' => $result]);

                return $result;
            });
        } catch (Throwable $e) {
            if ($claimed) {
                DB::table('authorized_transactions')
                    ->where('id', $txn->id)
                    ->update([
                        'status'         => AuthorizedTransaction::STATUS_FAILED,
                        'failure_reason' => $e->getMessage(),
                        'updated_at'     => now(),
                    ]);
            }

            throw $e;
        }
    }

    private function assertPreExecutionRiskAllows(AuthorizedTransaction $txn): void
    {
        $decision = $this->riskSignals->evaluatePreExecution($txn, is_array($txn->payload) ? $txn->payload : []);

        if (($decision['allow'] ?? true) === true) {
            return;
        }

        throw new RuntimeException((string) ($decision['reason'] ?? 'Transaction blocked by pre-execution risk checks.'));
    }

    /**
     * Wrap the handler execution with a domain-level idempotency guard when the
     * transaction payload carries a '_idempotency_key' sentinel set by initiate().
     *
     * Without a key (legacy callers), the closure is invoked directly — no guard overhead.
     *
     * @param  Closure(): array<string, mixed> $fn
     * @return array<string, mixed>
     */
    private function executeWithIdempotencyGuard(AuthorizedTransaction $txn, Closure $fn): array
    {
        $idempotencyKey = (string) ($txn->payload['_idempotency_key'] ?? '');

        if ($idempotencyKey === '') {
            return $fn();
        }

        // Hash the payload without the sentinel so the hash reflects only business data.
        // ksort() ensures a deterministic key order across separate PHP processes.
        $payloadForHash = array_diff_key($txn->payload, ['_idempotency_key' => true]);
        ksort($payloadForHash);
        $payloadHash = hash(
            'sha256',
            (string) json_encode($payloadForHash, JSON_THROW_ON_ERROR),
        );

        return $this->operationRecordService->guardAndRun(
            $txn->user_id,
            $txn->remark,
            $idempotencyKey,
            $payloadHash,
            $fn,
        );
    }

    /**
     * Scheduled sends: OTP/PIN only records consent; wallet transfer runs from
     * {@see \App\Console\Commands\ExecuteScheduledSends} via finalize().
     *
     * @return array<string, mixed>
     */
    private function markScheduledSendVerified(AuthorizedTransaction $txn): array
    {
        return DB::transaction(function () use ($txn): array {
            $updated = DB::table('authorized_transactions')
                ->where('id', $txn->id)
                ->where('status', AuthorizedTransaction::STATUS_PENDING)
                ->update([
                    'verification_confirmed_at' => now(),
                    'otp_hash'                  => null,
                    'otp_sent_at'               => null,
                    'otp_expires_at'            => null,
                    'updated_at'                => now(),
                ]);

            if ($updated === 0) {
                $txn->refresh();

                if ($txn->verification_confirmed_at !== null) {
                    return $this->scheduledSendVerificationResult($txn);
                }

                throw new RuntimeException(
                    'This transaction has already been processed or is no longer pending.'
                );
            }

            $txn->refresh();

            return $this->scheduledSendVerificationResult($txn);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduledSendVerificationResult(AuthorizedTransaction $txn): array
    {
        $payload = $txn->payload;

        return [
            'trx'          => $txn->trx,
            'scheduled'    => true,
            'scheduled_at' => $payload['scheduled_at'] ?? null,
            'message'      => 'Scheduled send authorized. Funds move at the scheduled time.',
        ];
    }

    private function assertScheduledSendExecutable(AuthorizedTransaction $txn): void
    {
        if ($txn->remark !== AuthorizedTransaction::REMARK_SCHEDULED_SEND) {
            return;
        }

        $executable = $txn->verification_confirmed_at !== null
            || $txn->verification_type === AuthorizedTransaction::VERIFICATION_NONE;

        if (! $executable) {
            throw new RuntimeException(
                'Scheduled send must be verified (OTP/PIN) before execution.'
            );
        }
    }

    private function findForUser(string $trx, int $userId): AuthorizedTransaction
    {
        $txn = AuthorizedTransaction::where('trx', $trx)
            ->where('user_id', $userId)
            ->first();

        if (! $txn) {
            throw new TransactionNotFoundException("Authorized transaction '{$trx}' not found.");
        }

        return $txn;
    }

    private function assertPendingAndNotExpired(AuthorizedTransaction $txn): void
    {
        if ($txn->isExpired()) {
            $this->expirePendingTransaction($txn, 'This authorized transaction has expired.');
            throw new RuntimeException('This authorized transaction has expired.');
        }

        if (! $txn->isPending()) {
            throw new RuntimeException(
                "Transaction is not pending (current status: {$txn->status})."
            );
        }
    }

    private function recordVerificationFailure(AuthorizedTransaction $txn, string $failureMessage): void
    {
        DB::transaction(function () use ($txn, $failureMessage): void {
            /** @var AuthorizedTransaction|null $lockedTxn */
            $lockedTxn = AuthorizedTransaction::query()
                ->whereKey($txn->id)
                ->lockForUpdate()
                ->first();

            if ($lockedTxn === null || ! $lockedTxn->isPending()) {
                return;
            }

            $nextFailures = (int) $lockedTxn->verification_failures + 1;
            $updates = [
                'verification_failures' => $nextFailures,
                'failure_reason'        => $failureMessage,
                'updated_at'            => now(),
            ];

            if ($nextFailures >= self::MAX_VERIFICATION_FAILURES) {
                $updates['status'] = AuthorizedTransaction::STATUS_FAILED;
                $updates['expires_at'] = now();
                $updates['failure_reason'] = 'Verification attempt limit exceeded.';
                $updates['otp_hash'] = null;
                $updates['otp_sent_at'] = null;
                $updates['otp_expires_at'] = null;
            }

            $lockedTxn->update($updates);
        });
    }

    private function expirePendingTransaction(AuthorizedTransaction $txn, string $reason): void
    {
        AuthorizedTransaction::query()
            ->whereKey($txn->id)
            ->where('status', AuthorizedTransaction::STATUS_PENDING)
            ->update([
                'status'         => AuthorizedTransaction::STATUS_EXPIRED,
                'failure_reason' => $reason,
                'expires_at'     => now(),
                'updated_at'     => now(),
            ]);
    }

    private function resolveHandler(string $remark): AuthorizedTransactionHandlerInterface
    {
        return match ($remark) {
            AuthorizedTransaction::REMARK_SEND_MONEY             => $this->sendMoneyHandler,
            AuthorizedTransaction::REMARK_SCHEDULED_SEND         => $this->scheduledSendHandler,
            AuthorizedTransaction::REMARK_REQUEST_MONEY          => $this->requestMoneyHandler,
            AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED => $this->requestMoneyReceivedHandler,
            default                                              => throw new InvalidArgumentException("No handler for remark: {$remark}"),
        };
    }

    private function generateTrx(): string
    {
        // Short alphanumeric reference returned to mobile (e.g. "TRX-A1B2C3D4").
        return 'TRX-' . strtoupper(Str::random(8));
    }
}
