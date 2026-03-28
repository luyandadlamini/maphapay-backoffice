<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Connectors;

use App\Domain\Wallet\Contracts\BlockchainConnector;
use App\Domain\Wallet\ValueObjects\AddressData;
use App\Domain\Wallet\ValueObjects\BalanceData;
use App\Domain\Wallet\ValueObjects\GasEstimate;
use App\Domain\Wallet\ValueObjects\SignedTransaction;
use App\Domain\Wallet\ValueObjects\TransactionData;
use App\Domain\Wallet\ValueObjects\TransactionResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Tron blockchain connector.
 *
 * Uses TronGrid/Full Node HTTP API for blockchain operations.
 * Supports mainnet, shasta (testnet), and nile (testnet).
 */
class TronConnector implements BlockchainConnector
{
    public function __construct(
        private readonly string $rpcUrl,
        private readonly string $network = 'mainnet',
        private readonly ?string $apiKey = null,
    ) {
    }

    public function generateAddress(string $publicKey): AddressData
    {
        // Tron addresses are base58check-encoded with 0x41 prefix
        return new AddressData(
            address: $publicKey,
            publicKey: $publicKey,
            chain: 'tron',
            metadata: [
                'network'      => $this->network,
                'generated_at' => now()->toIso8601String(),
            ]
        );
    }

    public function getBalance(string $address): BalanceData
    {
        try {
            $response = $this->apiCall('wallet/getaccount', ['address' => $address]);
            $balance = (string) ($response['balance'] ?? '0');

            return new BalanceData(
                address: $address,
                balance: $balance,
                chain: 'tron',
                symbol: 'TRX',
                decimals: 6,
                metadata: [
                    'network'      => $this->network,
                    'bandwidth'    => $response['net_usage'] ?? 0,
                    'energy'       => $response['energy_usage'] ?? 0,
                    'last_updated' => now()->toIso8601String(),
                ]
            );
        } catch (Throwable $e) {
            Log::error('Tron getBalance failed', ['address' => $address, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getTokenBalances(string $address): array
    {
        try {
            $response = $this->apiCall('wallet/getaccount', ['address' => $address]);
            $trc20 = $response['trc20'] ?? [];

            return array_map(function (array $tokenData): array {
                $contract = (string) (array_key_first($tokenData) ?? '');
                $balance = $tokenData[$contract] ?? '0';

                return [
                    'contract' => $contract,
                    'symbol'   => 'TRC20',
                    'decimals' => 6,
                    'balance'  => (string) $balance,
                    'name'     => 'TRC-20 Token',
                ];
            }, $trc20);
        } catch (Throwable $e) {
            Log::error('Tron getTokenBalances failed', ['address' => $address, 'error' => $e->getMessage()]);

            return [];
        }
    }

    public function estimateGas(TransactionData $transaction): GasEstimate
    {
        // Tron uses bandwidth + energy instead of gas
        // TRC-20 transfer typically costs ~15 TRX worth of energy
        $estimatedEnergy = '100000';
        $energyPrice = '420'; // sun per energy unit
        $estimatedCost = bcmul($estimatedEnergy, $energyPrice);

        return new GasEstimate(
            gasLimit: $estimatedEnergy,
            gasPrice: $energyPrice,
            maxFeePerGas: $energyPrice,
            maxPriorityFeePerGas: '0',
            estimatedCost: $estimatedCost,
            chain: 'tron',
            metadata: [
                'network'          => $this->network,
                'fee_type'         => 'energy',
                'energy_required'  => $estimatedEnergy,
                'energy_price_sun' => $energyPrice,
                'estimated_at'     => now()->toIso8601String(),
            ]
        );
    }

    public function broadcastTransaction(SignedTransaction $transaction): TransactionResult
    {
        try {
            $response = $this->apiCall('wallet/broadcasttransaction', [
                'raw_data_hex' => $transaction->rawTransaction,
            ]);

            $success = ($response['result'] ?? false) === true;
            $txId = $response['txid'] ?? $transaction->hash;

            return new TransactionResult(
                hash: $txId,
                status: $success ? 'pending' : 'failed',
                blockNumber: null,
                metadata: [
                    'chain'        => 'tron',
                    'network'      => $this->network,
                    'broadcast_at' => now()->toIso8601String(),
                ]
            );
        } catch (Throwable $e) {
            Log::error('Tron broadcastTransaction failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getTransaction(string $hash): ?TransactionData
    {
        try {
            $response = $this->apiCall('wallet/gettransactionbyid', ['value' => $hash]);

            if (empty($response) || ! isset($response['txID'])) {
                return null;
            }

            $contract = $response['raw_data']['contract'][0] ?? [];
            $ret = $response['ret'][0] ?? [];

            return new TransactionData(
                from: $contract['parameter']['value']['owner_address'] ?? '',
                to: $contract['parameter']['value']['to_address'] ?? '',
                value: (string) ($contract['parameter']['value']['amount'] ?? 0),
                chain: 'tron',
                hash: $hash,
                blockNumber: null,
                status: ($ret['contractRet'] ?? '') === 'SUCCESS' ? 'confirmed' : 'failed',
                metadata: [
                    'network'      => $this->network,
                    'contract_ret' => $ret['contractRet'] ?? 'UNKNOWN',
                ]
            );
        } catch (Throwable $e) {
            Log::error('Tron getTransaction failed', ['hash' => $hash, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** @return array<string, string> */
    public function getGasPrices(): array
    {
        // Tron energy/bandwidth pricing
        return [
            'slow'     => '280',
            'standard' => '420',
            'fast'     => '420',
            'instant'  => '420',
        ];
    }

    public function subscribeToEvents(string $address, callable $callback): void
    {
        Log::info('Tron event subscription requested', [
            'address' => $address,
            'network' => $this->network,
        ]);
    }

    public function unsubscribeFromEvents(string $address): void
    {
        Log::info('Tron event unsubscription requested', ['address' => $address]);
    }

    public function getChainId(): string
    {
        return $this->network;
    }

    public function isHealthy(): bool
    {
        try {
            $response = $this->apiCall('wallet/getnowblock', []);

            return isset($response['blockID']);
        } catch (Throwable) {
            return false;
        }
    }

    public function getTransactionStatus(string $hash): TransactionResult
    {
        try {
            $response = $this->apiCall('walletsolidity/gettransactioninfobyid', ['value' => $hash]);

            if (empty($response) || ! isset($response['id'])) {
                return new TransactionResult(
                    hash: $hash,
                    status: 'pending',
                    blockNumber: null,
                    metadata: ['chain' => 'tron', 'network' => $this->network, 'confirmations' => 0]
                );
            }

            $receipt = $response['receipt'] ?? [];
            $blockNumber = $response['blockNumber'] ?? null;

            return new TransactionResult(
                hash: $hash,
                status: ($receipt['result'] ?? 'SUCCESS') === 'SUCCESS' ? 'confirmed' : 'failed',
                blockNumber: $blockNumber,
                metadata: [
                    'chain'         => 'tron',
                    'network'       => $this->network,
                    'confirmations' => 19, // Tron ~19 confirmations for finality
                    'energy_used'   => $receipt['energy_usage_total'] ?? 0,
                    'net_usage'     => $receipt['net_usage'] ?? 0,
                ]
            );
        } catch (Throwable $e) {
            Log::error('Tron getTransactionStatus failed', ['hash' => $hash, 'error' => $e->getMessage()]);

            return new TransactionResult(
                hash: $hash,
                status: 'unknown',
                blockNumber: null,
                metadata: ['chain' => 'tron', 'error' => $e->getMessage()]
            );
        }
    }

    public function validateAddress(string $address): bool
    {
        // Tron addresses: base58check starting with T, 34 characters
        return (bool) preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address);
    }

    /** @param array<string, mixed> $params
     * @return array<string, mixed> */
    private function apiCall(string $path, array $params): array
    {
        $request = Http::timeout(30);

        if ($this->apiKey) {
            $request = $request->withHeaders(['TRON-PRO-API-KEY' => $this->apiKey]);
        }

        $response = $request->post("{$this->rpcUrl}/{$path}", $params);

        if (! $response->successful()) {
            throw new RuntimeException("Tron API call failed: {$path} - HTTP {$response->status()}");
        }

        return $response->json() ?? [];
    }
}
