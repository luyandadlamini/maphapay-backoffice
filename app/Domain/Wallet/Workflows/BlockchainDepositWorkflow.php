<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Workflows;

use App\Domain\Account\Aggregates\Account;
use App\Domain\Wallet\Workflows\Activities\BlockchainDepositActivities;
use App\Models\User;
use Workflow\ActivityStub;
use Workflow\Workflow;
use Workflow\WorkflowStub;

class BlockchainDepositWorkflow extends Workflow
{
    private ActivityStub $activities;

    public function __construct()
    {
        $this->activities = WorkflowStub::newActivityStub(
            BlockchainDepositActivities::class,
            [
                'startToCloseTimeout' => 300, // 5 minutes
                'retryAttempts'       => 3,
            ]
        );
    }

    public function execute(
        string $walletId,
        string $chain,
        string $transactionHash,
        string $fromAddress,
        string $toAddress,
        string $amount,
        string $asset = 'native',
        ?string $tokenAddress = null
    ) {
        // Step 1: Verify transaction on blockchain
        $transactionData = yield $this->activities->verifyTransaction(
            $chain,
            $transactionHash
        );

        if (! $transactionData['confirmed']) {
            // Wait for confirmations
            yield $this->activities->waitForConfirmations(
                $chain,
                $transactionHash,
                6 // Required confirmations
            );

            // Re-verify after confirmations
            $transactionData = yield $this->activities->verifyTransaction(
                $chain,
                $transactionHash
            );
        }

        // Step 2: Validate transaction details match
        yield $this->activities->validateTransactionDetails(
            $transactionData,
            $toAddress,
            $amount,
            $asset,
            $tokenAddress
        );

        // Step 3: Check for duplicate deposits
        $isDuplicate = yield $this->activities->checkDuplicateDeposit(
            $walletId,
            $transactionHash
        );

        if ($isDuplicate) {
            return [
                'status'           => 'duplicate',
                'message'          => 'This transaction has already been processed',
                'transaction_hash' => $transactionHash,
            ];
        }

        // Step 4: Record blockchain transaction
        yield $this->activities->recordBlockchainTransaction(
            $walletId,
            $chain,
            $transactionHash,
            $fromAddress,
            $toAddress,
            $amount,
            $asset,
            $transactionData
        );

        // Step 5: Get user's fiat account
        $userId = yield $this->activities->getUserIdFromWallet($walletId);
        $accountId = yield $this->activities->getUserFiatAccount($userId, $chain);

        // Step 6: Calculate fiat value
        $fiatValue = yield $this->activities->calculateFiatValue(
            $amount,
            $asset,
            $chain,
            $tokenAddress
        );

        // Step 7: Credit user's fiat account
        yield $this->activities->creditFiatAccount(
            $accountId,
            $fiatValue,
            "Blockchain deposit from {$chain}",
            [
                'transaction_hash' => $transactionHash,
                'chain'            => $chain,
                'asset'            => $asset,
                'amount'           => $amount,
            ]
        );

        // Step 8: Update token balance if ERC20/BEP20
        if ($asset !== 'native' && $tokenAddress) {
            yield $this->activities->updateTokenBalance(
                $walletId,
                $toAddress,
                $chain,
                $tokenAddress,
                $amount
            );
        }

        // Step 9: Send notification
        yield $this->activities->sendDepositNotification(
            $userId,
            $chain,
            $amount,
            $asset,
            $fiatValue,
            $transactionHash
        );

        return [
            'status'           => 'completed',
            'transaction_hash' => $transactionHash,
            'amount'           => $amount,
            'asset'            => $asset,
            'fiat_value'       => $fiatValue,
            'chain'            => $chain,
        ];
    }
}
