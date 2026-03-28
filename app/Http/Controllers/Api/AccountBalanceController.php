<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Services\ExchangeRateService;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Account Balances',
    description: 'APIs for managing multi-asset account balances'
)]
class AccountBalanceController extends Controller
{
        #[OA\Get(
            path: '/api/accounts/{uuid}/balances',
            operationId: 'getAccountBalances',
            tags: ['Account Balances'],
            summary: 'Get account balances',
            description: 'Retrieve all asset balances for a specific account',
            security: [['sanctum' => []]],
            parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, description: 'The account UUID', schema: new OA\Schema(type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')),
            new OA\Parameter(name: 'asset', in: 'query', required: false, description: 'Filter by specific asset code', schema: new OA\Schema(type: 'string', example: 'USD')),
            new OA\Parameter(name: 'positive', in: 'query', required: false, description: 'Only show positive balances', schema: new OA\Schema(type: 'boolean', example: true)),
            ]
        )]
    #[OA\Response(
        response: 200,
        description: 'Successful operation',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'account_uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'balances', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'asset_code', type: 'string'),
        new OA\Property(property: 'balance', type: 'integer'),
        new OA\Property(property: 'formatted', type: 'string'),
        new OA\Property(property: 'asset', type: 'object', properties: [
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'type', type: 'string'),
        new OA\Property(property: 'symbol', type: 'string'),
        new OA\Property(property: 'precision', type: 'integer'),
        ]),
        ])),
        new OA\Property(property: 'summary', type: 'object', properties: [
        new OA\Property(property: 'total_assets', type: 'integer'),
        new OA\Property(
            property: 'total_usd_equivalent',
            type: 'string',
            description: 'Approximate total in USD major units (2 decimal places in the formatted string). USD leg uses each asset\'s precision for smallest-unit amounts. Non-USD legs are converted via ExchangeRateService when a rate exists; assets without a rate contribute 0 to this sum.'
        ),
        ]),
        ]),
        ])
    )]
    #[OA\Response(
        response: 404,
        description: 'Account not found',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Account not found'),
        new OA\Property(property: 'error', type: 'string', example: 'The specified account UUID was not found'),
        ])
    )]
    public function show(Request $request, string $uuid): JsonResponse
    {
        $account = Account::where('uuid', $uuid)->first();

        if (! $account) {
            return response()->json(
                [
                    'message' => 'Account not found',
                    'error'   => 'The specified account UUID was not found',
                ],
                404
            );
        }

        $query = $account->balances()->with('asset');

        // Filter by specific asset
        if ($request->has('asset')) {
            $query->where('asset_code', strtoupper($request->string('asset')->toString()));
        }

        // Filter positive balances only
        if ($request->boolean('positive')) {
            $query->where('balance', '>', 0);
        }

        $balances = $query->get();

        // Calculate USD equivalent for summary
        $totalUsdEquivalent = $this->calculateUsdEquivalent($balances);

        return response()->json(
            [
                'data' => [
                    'account_uuid' => $account->uuid,
                    'balances'     => $balances->map(
                        function ($balance) {
                            $asset = $balance->asset;
                            $formatted = $this->formatAmount($balance->balance, $asset);

                            return [
                                'asset_code' => $balance->asset_code,
                                'balance'    => $balance->balance,
                                'formatted'  => $formatted,
                                'asset'      => [
                                    'code'      => $asset->code,
                                    'name'      => $asset->name,
                                    'type'      => $asset->type,
                                    'symbol'    => $asset->symbol,
                                    'precision' => $asset->precision,
                                ],
                            ];
                        }
                    ),
                    'summary' => [
                        'total_assets'         => $balances->where('balance', '>', 0)->count(),
                        'total_usd_equivalent' => number_format($totalUsdEquivalent, 2),
                    ],
                ],
            ]
        );
    }

        #[OA\Get(
            path: '/api/balances',
            operationId: 'listAllBalances',
            tags: ['Account Balances'],
            summary: 'List all account balances',
            description: 'Get balances across all accounts with filtering and aggregation options',
            security: [['sanctum' => []]],
            parameters: [
            new OA\Parameter(name: 'asset', in: 'query', required: false, description: 'Filter by specific asset code', schema: new OA\Schema(type: 'string', example: 'USD')),
            new OA\Parameter(name: 'min_balance', in: 'query', required: false, description: 'Minimum balance filter (in smallest unit)', schema: new OA\Schema(type: 'integer', example: 1000)),
            new OA\Parameter(name: 'user_uuid', in: 'query', required: false, description: 'Filter by account owner', schema: new OA\Schema(type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Number of results per page (max 100)', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, example: 20)),
            ]
        )]
    #[OA\Response(
        response: 200,
        description: 'Successful operation',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'account_uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'asset_code', type: 'string'),
        new OA\Property(property: 'balance', type: 'integer'),
        new OA\Property(property: 'formatted', type: 'string'),
        new OA\Property(property: 'account', type: 'object', properties: [
        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
        new OA\Property(property: 'user_uuid', type: 'string', format: 'uuid'),
        ]),
        ])),
        new OA\Property(property: 'meta', type: 'object', properties: [
        new OA\Property(property: 'total_accounts', type: 'integer'),
        new OA\Property(property: 'total_balances', type: 'integer'),
        new OA\Property(property: 'asset_totals', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
        ]),
        ])
    )]
    #[OA\Response(
        response: 422,
        description: 'Validation error',
        content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')
    )]
    public function index(Request $request): JsonResponse
    {
        $request->validate(
            [
                'asset'       => 'sometimes|string|size:3',
                'min_balance' => 'sometimes|integer|min:0',
                'user_uuid'   => 'sometimes|uuid',
                'limit'       => 'sometimes|integer|min:1|max:100',
            ]
        );

        $query = AccountBalance::with(['account', 'asset']);

        // Apply filters
        if ($request->has('asset')) {
            $query->where('asset_code', strtoupper($request->string('asset')->toString()));
        }

        if ($request->has('min_balance')) {
            $query->where('balance', '>=', $request->integer('min_balance'));
        }

        if ($request->has('user_uuid')) {
            $query->whereHas(
                'account',
                function ($q) use ($request) {
                    /** @phpstan-ignore-next-line */
                    $q->where('user_uuid', $request->string('user_uuid')->toString());
                }
            );
        }

        $limit = $request->integer('limit', 50);
        $balances = $query->orderBy('balance', 'desc')->limit($limit)->get();

        // Calculate aggregations
        $totalAccounts = Account::count();
        $totalBalances = AccountBalance::count();
        $assetTotals = $this->calculateAssetTotals();

        return response()->json(
            [
                'data' => $balances->map(
                    function ($balance) {
                        return [
                            'account_uuid' => $balance->account_uuid,
                            'asset_code'   => $balance->asset_code,
                            'balance'      => $balance->balance,
                            'formatted'    => $this->formatAmount($balance->balance, $balance->asset),
                            'account'      => [
                                'uuid'      => $balance->account->uuid,
                                'user_uuid' => $balance->account->user_uuid,
                            ],
                        ];
                    }
                ),
                'meta' => [
                    'total_accounts' => $totalAccounts,
                    'total_balances' => $totalBalances,
                    'asset_totals'   => $assetTotals,
                ],
            ]
        );
    }

    private function formatAmount(int $amount, Asset $asset): string
    {
        $formatted = number_format(
            $amount / (10 ** $asset->precision),
            $asset->precision,
            '.',
            ''
        );

        return "{$formatted} {$asset->code}";
    }

    /**
     * Approximate portfolio value in USD major units for display.
     *
     * Smallest-unit → major uses each {@see Asset::precision} (no fixed /100).
     * Non-USD rows use {@see ExchangeRateService::convert()} when the container
     * provides the service and a rate exists; otherwise they add 0 (same as
     * omitting unpriced assets).
     *
     * @param  EloquentCollection<int, AccountBalance>  $balances
     */
    private function calculateUsdEquivalent(EloquentCollection $balances): float
    {
        $total = 0.0;
        $exchangeRateService = app()->bound(ExchangeRateService::class)
            ? app(ExchangeRateService::class)
            : null;

        /** @var Asset|null $usdAsset */
        $usdAsset = Asset::query()->where('code', 'USD')->first();
        $usdLedgerPrecision = $usdAsset instanceof Asset ? max(0, $usdAsset->precision) : 2;

        foreach ($balances as $balance) {
            $asset = $balance->asset;
            if (! $asset instanceof Asset) {
                continue;
            }

            if (strtoupper($balance->asset_code) === 'USD') {
                $total += $this->smallestUnitToMajor((int) $balance->balance, max(0, $asset->precision));

                continue;
            }

            if ($exchangeRateService === null) {
                continue;
            }

            $usdSmallest = $exchangeRateService->convert(
                (int) $balance->balance,
                $balance->asset_code,
                'USD'
            );

            if ($usdSmallest === null) {
                continue;
            }

            $total += $this->smallestUnitToMajor($usdSmallest, $usdLedgerPrecision);
        }

        return $total;
    }

    /**
     * Convert a ledger amount in smallest units to major units using the asset's decimal precision.
     */
    private function smallestUnitToMajor(int $amountSmallest, int $precision): float
    {
        $precision = max(0, $precision);

        return $amountSmallest / (10 ** $precision);
    }

    private function calculateAssetTotals(): array
    {
        return Cache::remember(
            'asset_totals',
            300,
            function () {
                $totals = AccountBalance::selectRaw('asset_code, SUM(balance) as total')
                    ->groupBy('asset_code')
                    ->with('asset')
                    ->get()
                    ->mapWithKeys(
                        function ($item) {
                            $asset = Asset::where('code', $item->asset_code)->first();
                            if ($asset) {
                                $formatted = number_format(
                                    $item->total / (10 ** $asset->precision),
                                    $asset->precision,
                                    '.',
                                    ''
                                );

                                return [$item->asset_code => $formatted];
                            }

                            return [$item->asset_code => (string) $item->total];
                        }
                    );

                return $totals->toArray();
            }
        );
    }
}
