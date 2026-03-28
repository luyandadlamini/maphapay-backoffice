<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\Transaction;
use App\Domain\Account\Workflows\DepositAccountWorkflow;
use App\Domain\Account\Workflows\WithdrawAccountWorkflow;
use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Workflows\AssetDepositWorkflow;
use App\Domain\Asset\Workflows\AssetWithdrawWorkflow;
use App\Http\Controllers\Controller;
use DB;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Workflow\WorkflowStub;

class TransactionController extends Controller
{
    /**
     * Legacy {@see \App\Domain\Account\Aggregates\TransactionAggregate} / {@see Money} path is single-currency; only this code uses {@see DepositAccountWorkflow} / {@see WithdrawAccountWorkflow}.
     */
    private const LEGACY_ACCOUNT_MONEY_ASSET_CODE = 'USD';

        #[OA\Post(
            path: '/api/accounts/{uuid}/deposit',
            operationId: 'depositToAccount',
            tags: ['Transactions'],
            summary: 'Deposit money to an account',
            description: 'Deposits money into a specified account',
            security: [['sanctum' => []]],
            parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'Account UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            ],
            requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['amount'], properties: [
            new OA\Property(property: 'amount', type: 'integer', example: 10000, minimum: 1, description: 'Amount in cents'),
            new OA\Property(property: 'description', type: 'string', example: 'Monthly salary', maxLength: 255),
            ]))
        )]
    #[OA\Response(
        response: 200,
        description: 'Deposit successful',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'account_uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'new_balance', type: 'integer', example: 60000),
        new OA\Property(property: 'amount_deposited', type: 'integer', example: 10000),
        new OA\Property(property: 'transaction_type', type: 'string', example: 'deposit'),
        ]),
        new OA\Property(property: 'message', type: 'string', example: 'Deposit successful'),
        ])
    )]
    #[OA\Response(
        response: 422,
        description: 'Cannot deposit to frozen account',
        content: new OA\JsonContent(ref: '#/components/schemas/Error')
    )]
    #[OA\Response(
        response: 404,
        description: 'Account not found',
        content: new OA\JsonContent(ref: '#/components/schemas/Error')
    )]
    public function deposit(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate(
            [
                'amount'      => 'required|numeric|min:0.01',
                'asset_code'  => 'required|string|exists:assets,code',
                'description' => 'sometimes|string|max:255',
            ]
        );

        $account = Account::where('uuid', $uuid)->firstOrFail();

        // Check if user owns this account
        if ($account->user_uuid !== auth()->user()->uuid) {
            return response()->json(
                [
                    'message' => 'Access denied to this account',
                    'error'   => 'FORBIDDEN',
                ],
                403
            );
        }

        if ($account->frozen) {
            return response()->json(
                [
                    'message' => 'Cannot deposit to frozen account',
                    'error'   => 'ACCOUNT_FROZEN',
                ],
                422
            );
        }

        $asset = Asset::where('code', $validated['asset_code'])->firstOrFail();
        $amountInMinorUnits = (int) round($validated['amount'] * (10 ** $asset->precision));

        $accountUuid = new AccountUuid($uuid);

        // Legacy Money workflow only supports the single-currency aggregate; SZL and other assets use asset workflows.
        if ($this->shouldUseLegacyAccountMoneyWorkflow($validated['asset_code'])) {
            $money = new Money($amountInMinorUnits);
            $workflow = WorkflowStub::make(DepositAccountWorkflow::class);
            $workflow->start($accountUuid, $money);
        } else {
            $workflow = WorkflowStub::make(AssetDepositWorkflow::class);
            $workflow->start($accountUuid, $validated['asset_code'], $amountInMinorUnits);
        }

        return response()->json(
            [
                'message' => 'Deposit initiated successfully',
            ]
        );
    }

        #[OA\Post(
            path: '/api/accounts/{uuid}/withdraw',
            operationId: 'withdrawFromAccount',
            tags: ['Transactions'],
            summary: 'Withdraw money from an account',
            description: 'Withdraws money from a specified account',
            security: [['sanctum' => []]],
            parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'Account UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            ],
            requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['amount'], properties: [
            new OA\Property(property: 'amount', type: 'integer', example: 5000, minimum: 1, description: 'Amount in cents'),
            new OA\Property(property: 'description', type: 'string', example: 'ATM withdrawal', maxLength: 255),
            ]))
        )]
    #[OA\Response(
        response: 200,
        description: 'Withdrawal successful',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'account_uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'new_balance', type: 'integer', example: 45000),
        new OA\Property(property: 'amount_withdrawn', type: 'integer', example: 5000),
        new OA\Property(property: 'transaction_type', type: 'string', example: 'withdrawal'),
        ]),
        new OA\Property(property: 'message', type: 'string', example: 'Withdrawal successful'),
        ])
    )]
    #[OA\Response(
        response: 422,
        description: 'Insufficient balance or frozen account',
        content: new OA\JsonContent(ref: '#/components/schemas/Error')
    )]
    #[OA\Response(
        response: 404,
        description: 'Account not found',
        content: new OA\JsonContent(ref: '#/components/schemas/Error')
    )]
    public function withdraw(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate(
            [
                'amount'      => 'required|numeric|min:0.01',
                'asset_code'  => 'required|string|exists:assets,code',
                'description' => 'sometimes|string|max:255',
            ]
        );

        $account = Account::where('uuid', $uuid)->firstOrFail();

        // Check if user owns this account
        if ($account->user_uuid !== auth()->user()->uuid) {
            return response()->json(
                [
                    'message' => 'Access denied to this account',
                    'error'   => 'FORBIDDEN',
                ],
                403
            );
        }

        if ($account->frozen) {
            return response()->json(
                [
                    'message' => 'Cannot withdraw from frozen account',
                    'error'   => 'ACCOUNT_FROZEN',
                ],
                422
            );
        }

        $asset = Asset::where('code', $validated['asset_code'])->firstOrFail();
        $amountInMinorUnits = (int) round($validated['amount'] * (10 ** $asset->precision));

        // Check sufficient balance
        $balance = $account->getBalance($validated['asset_code']);

        if ($balance < $amountInMinorUnits) {
            return response()->json(
                [
                    'message' => 'Insufficient balance',
                    'errors'  => [
                        'amount' => ['Insufficient balance'],
                    ],
                ],
                422
            );
        }

        $accountUuid = new AccountUuid($uuid);

        try {
            if ($this->shouldUseLegacyAccountMoneyWorkflow($validated['asset_code'])) {
                $money = new Money($amountInMinorUnits);
                $workflow = WorkflowStub::make(WithdrawAccountWorkflow::class);
                $workflow->start($accountUuid, $money);
            } else {
                $workflow = WorkflowStub::make(AssetWithdrawWorkflow::class);
                $workflow->start($accountUuid, $validated['asset_code'], $amountInMinorUnits);
            }
        } catch (Exception $e) {
            return response()->json(
                [
                    'message' => 'Withdrawal failed',
                    'error'   => 'WITHDRAWAL_FAILED',
                ],
                422
            );
        }

        return response()->json(
            [
                'message' => 'Withdrawal initiated successfully',
            ]
        );
    }

        #[OA\Get(
            path: '/api/accounts/{uuid}/transactions',
            operationId: 'getAccountTransactions',
            tags: ['Transactions'],
            summary: 'Get transaction history for an account',
            description: 'Retrieves paginated transaction history from event store',
            security: [['sanctum' => []]],
            parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'Account UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'type', in: 'query', description: 'Filter by transaction type', required: false, schema: new OA\Schema(type: 'string', enum: ['credit', 'debit'])),
            new OA\Parameter(name: 'asset_code', in: 'query', description: 'Filter by asset code', required: false, schema: new OA\Schema(type: 'string', example: 'USD')),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 50)),
            ]
        )]
    #[OA\Response(
        response: 200,
        description: 'Transaction history retrieved successfully',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Transaction')),
        new OA\Property(property: 'meta', type: 'object'),
        ])
    )]
    #[OA\Response(
        response: 404,
        description: 'Account not found',
        content: new OA\JsonContent(ref: '#/components/schemas/Error')
    )]
    public function history(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate(
            [
                'type'       => 'sometimes|string|in:credit,debit',
                'asset_code' => 'sometimes|string|max:10',
                'per_page'   => 'sometimes|integer|min:1|max:100',
            ]
        );

        $account = Account::where('uuid', $uuid)->firstOrFail();

        // Build event classes to query based on filters
        $eventClasses = [
            'App\Domain\Account\Events\MoneyAdded',
            'App\Domain\Account\Events\MoneySubtracted',
            'App\Domain\Account\Events\MoneyTransferred',
            'App\Domain\Account\Events\AssetBalanceAdded',
            'App\Domain\Account\Events\AssetBalanceSubtracted',
            'App\Domain\Account\Events\AssetTransferred',
        ];

        $query = DB::table('stored_events')
            ->where('aggregate_uuid', $uuid)
            ->whereIn('event_class', $eventClasses)
            ->orderBy('created_at', 'desc');

        $events = $query->paginate($validated['per_page'] ?? 50);

        // Transform events to transaction format
        $transactions = collect($events->items())->map(
            function ($event) {
                /** @var array<string, mixed> $properties */
                $properties = json_decode((string) $event->event_properties, true);
                if (! is_array($properties)) {
                    $properties = [];
                }
                $eventClass = class_basename($event->event_class);

                // Default values; asset_code is set per event (unknown payloads fall back to legacy fiat).
                $transaction = [
                    'id'           => $event->id,
                    'account_uuid' => $event->aggregate_uuid,
                    'type'         => $this->getTransactionType($eventClass),
                    'amount'       => 0,
                    'asset_code'   => self::LEGACY_ACCOUNT_MONEY_ASSET_CODE,
                    'description'  => $this->getTransactionDescription($eventClass),
                    'hash'         => $this->historyEventHash($properties),
                    'created_at'   => $event->created_at,
                    'metadata'     => [],
                ];

                // Extract amount and asset based on event type
                switch ($eventClass) {
                    case 'MoneyAdded':
                    case 'MoneySubtracted':
                        $transaction['amount'] = $this->historyMoneyAmount($properties);
                        $transaction['asset_code'] = $this->historyAssetCodeForLegacyMoneyPayload($properties);
                        break;

                    case 'AssetBalanceAdded':
                    case 'AssetBalanceSubtracted':
                        $transaction['amount'] = (int) ($properties['amount'] ?? 0);
                        $transaction['asset_code'] = $this->historyAssetCodeFromScalar(
                            $properties,
                            'assetCode',
                            self::LEGACY_ACCOUNT_MONEY_ASSET_CODE
                        );
                        break;

                    case 'MoneyTransferred':
                        $transaction['amount'] = $this->historyMoneyAmount($properties);
                        $transaction['asset_code'] = $this->historyAssetCodeForLegacyMoneyPayload($properties);
                        $transaction['metadata'] = $this->historyTransferMetadata($properties);
                        break;

                    case 'AssetTransferred':
                        $transaction['amount'] = (int) ($properties['amount'] ?? 0);
                        $transaction['asset_code'] = $this->historyAssetCodeForAssetTransferred($properties);
                        $transaction['metadata'] = $this->historyTransferMetadata($properties);
                        break;
                }

                return $transaction;
            }
        )->filter(
            function ($transaction) use ($validated) {
                // Apply filters
                if (isset($validated['type']) && $transaction['type'] !== $validated['type']) {
                    return false;
                }

                if (isset($validated['asset_code']) && $transaction['asset_code'] !== $validated['asset_code']) {
                    return false;
                }

                return true;
            }
        )->values();

        return response()->json(
            [
                'data' => $transactions,
                'meta' => [
                    'current_page' => $events->currentPage(),
                    'last_page'    => $events->lastPage(),
                    'per_page'     => $events->perPage(),
                    'total'        => $events->total(),
                    'account_uuid' => $uuid,
                ],
            ]
        );
    }

    /**
     * Get transaction type from event class.
     */
    private function getTransactionType(string $eventClass): string
    {
        return match ($eventClass) {
            'MoneyAdded', 'AssetBalanceAdded' => 'credit',
            'MoneySubtracted', 'AssetBalanceSubtracted' => 'debit',
            'MoneyTransferred', 'AssetTransferred' => 'transfer',
            default => 'unknown',
        };
    }

    /**
     * Get transaction description from event class.
     */
    private function getTransactionDescription(string $eventClass): string
    {
        return match ($eventClass) {
            'MoneyAdded', 'AssetBalanceAdded' => 'Deposit',
            'MoneySubtracted', 'AssetBalanceSubtracted' => 'Withdrawal',
            'MoneyTransferred', 'AssetTransferred' => 'Transfer',
            default => 'Transaction',
        };
    }

    private function shouldUseLegacyAccountMoneyWorkflow(string $assetCode): bool
    {
        return $assetCode === self::LEGACY_ACCOUNT_MONEY_ASSET_CODE;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function historyEventHash(array $properties): ?string
    {
        $hash = $properties['hash'] ?? null;
        if (! is_array($hash)) {
            return null;
        }

        $inner = $hash['hash'] ?? null;

        return is_string($inner) ? $inner : null;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function historyMoneyAmount(array $properties): int
    {
        $money = $properties['money'] ?? null;
        if (! is_array($money)) {
            return 0;
        }

        return (int) ($money['amount'] ?? 0);
    }

    /**
     * Stored legacy Money events may include `currency` (upcasters) even though {@see Money} has no currency field.
     *
     * @param array<string, mixed> $properties
     */
    private function historyAssetCodeForLegacyMoneyPayload(array $properties): string
    {
        foreach (['currency', 'assetCode'] as $key) {
            if (isset($properties[$key]) && is_string($properties[$key]) && $properties[$key] !== '') {
                return $properties[$key];
            }
        }

        $money = $properties['money'] ?? null;
        if (is_array($money) && isset($money['currency']) && is_string($money['currency']) && $money['currency'] !== '') {
            return $money['currency'];
        }

        return self::LEGACY_ACCOUNT_MONEY_ASSET_CODE;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function historyAssetCodeFromScalar(array $properties, string $key, string $fallback): string
    {
        $value = $properties[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function historyAssetCodeForAssetTransferred(array $properties): string
    {
        foreach (['assetCode', 'fromAsset'] as $key) {
            if (isset($properties[$key]) && is_string($properties[$key]) && $properties[$key] !== '') {
                return $properties[$key];
            }
        }

        return self::LEGACY_ACCOUNT_MONEY_ASSET_CODE;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function historyTransferMetadata(array $properties): array
    {
        $to = $this->historyTransferEndpointUuid($properties, 'toAccount', 'to');
        $from = $this->historyTransferEndpointUuid($properties, 'fromAccount', 'from');

        return [
            'to_account'   => $to,
            'from_account' => $from,
        ];
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function historyTransferEndpointUuid(array $properties, string $legacyKey, string $canonicalKey): ?string
    {
        foreach ([$legacyKey, $canonicalKey] as $key) {
            $node = $properties[$key] ?? null;
            if (! is_array($node)) {
                continue;
            }

            $uuid = $node['uuid'] ?? null;
            if (is_string($uuid) && $uuid !== '') {
                return $uuid;
            }
        }

        return null;
    }
}
