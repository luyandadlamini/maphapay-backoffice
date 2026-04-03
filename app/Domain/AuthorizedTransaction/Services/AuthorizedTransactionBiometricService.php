<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Services;

use App\Domain\AuthorizedTransaction\Exceptions\TransactionNotFoundException;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransactionBiometricChallenge;
use App\Domain\Mobile\Models\MobileDevice;
use App\Domain\Mobile\Services\BiometricAuthenticationService;
use RuntimeException;

class AuthorizedTransactionBiometricService
{
    public function __construct(
        private readonly BiometricAuthenticationService $biometricAuthenticationService,
    ) {}

    public function issueChallengeForUser(
        string $trx,
        int $userId,
        string $deviceId,
        ?string $remark = null,
        ?string $ipAddress = null,
    ): AuthorizedTransactionBiometricChallenge {
        $transaction = $this->findTransaction($trx, $userId, $remark);
        $device = $this->findEligibleDevice($deviceId, $userId);

        AuthorizedTransactionBiometricChallenge::query()
            ->where('authorized_transaction_id', $transaction->id)
            ->where('mobile_device_id', $device->id)
            ->pending()
            ->update(['status' => AuthorizedTransactionBiometricChallenge::STATUS_EXPIRED]);

        return AuthorizedTransactionBiometricChallenge::createForTransaction($transaction, $device, $ipAddress);
    }

    public function verifyChallengeForUser(
        string $trx,
        int $userId,
        string $deviceId,
        string $challenge,
        string $signature,
        ?string $remark = null,
        ?string $ipAddress = null,
    ): void {
        $transaction = $this->findTransaction($trx, $userId, $remark);
        $device = $this->findEligibleDevice($deviceId, $userId);

        $challengeRecord = AuthorizedTransactionBiometricChallenge::query()
            ->where('authorized_transaction_id', $transaction->id)
            ->where('mobile_device_id', $device->id)
            ->where('challenge', $challenge)
            ->first();

        if (! $challengeRecord || $challengeRecord->status !== AuthorizedTransactionBiometricChallenge::STATUS_PENDING) {
            throw new RuntimeException('Biometric challenge not found or expired.');
        }

        if ($challengeRecord->isExpired()) {
            $challengeRecord->markAsExpired();

            throw new RuntimeException('Biometric challenge not found or expired.');
        }

        $verified = $this->biometricAuthenticationService->verifyTransactionChallengeSignature(
            $device,
            $challengeRecord->challenge,
            $signature,
            $challengeRecord->ip_address,
            $ipAddress,
        );

        if (! $verified) {
            $challengeRecord->markAsFailed();

            throw new RuntimeException('Unable to verify your identity. Please try again.');
        }

        $challengeRecord->markAsVerified();
    }

    private function findTransaction(string $trx, int $userId, ?string $remark = null): AuthorizedTransaction
    {
        $transaction = AuthorizedTransaction::query()
            ->where('trx', $trx)
            ->where('user_id', $userId)
            ->first();

        if (! $transaction) {
            throw new TransactionNotFoundException('Transaction not found.');
        }

        if ($remark !== null && $remark !== '' && $transaction->remark !== $remark) {
            throw new RuntimeException('Transaction remark mismatch.');
        }

        if ($transaction->verification_type !== AuthorizedTransaction::VERIFICATION_PIN) {
            throw new RuntimeException('Biometric verification is only available for PIN-class transactions.');
        }

        if (! $transaction->isPending()) {
            throw new RuntimeException('This transaction has already been processed or is no longer pending.');
        }

        if ($transaction->isExpired()) {
            throw new RuntimeException('This transaction has expired.');
        }

        if ($transaction->user?->transaction_pin === null) {
            throw new RuntimeException('Transaction PIN has not been set for this account.');
        }

        return $transaction;
    }

    private function findEligibleDevice(string $deviceId, int $userId): MobileDevice
    {
        $device = MobileDevice::query()
            ->where('device_id', $deviceId)
            ->where('user_id', $userId)
            ->first();

        if (! $device) {
            throw new RuntimeException('Device not found.');
        }

        if (! $device->canUseBiometric()) {
            throw new RuntimeException('Biometric authentication is not available for this device.');
        }

        return $device;
    }
}
