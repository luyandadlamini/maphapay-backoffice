<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\Wallet\Workflows\WalletTransferWorkflow;
use App\Http\Controllers\Controller;
use DB;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Workflow\WorkflowStub;

class TransferController extends Controller
{
    /**
     * Fallback when legacy stored events omit currency / asset metadata (see {@see TransactionController::LEGACY_ACCOUNT_MONEY_ASSET_CODE}).
     */
    private const LEGACY_ACCOUNT_MONEY_ASSET_CODE = 'USD';

    #[OA\Post(
        path: '/api/v2/transfers',
        operationId: 'createTransfer',
        tags: ['Transfers'],
        summary: 'Create a money transfer',
        description: <<<'DESC'
Transfers funds between accounts for the given `asset_code`.

**Amount contract:** `amount` is the transfer value in **major (human) units** for that asset (not smallest units). Send it as a **string** to avoid floating-point ambiguity and to keep idempotency keys stable (e.g. `"12.34"` for two decimal places). JSON numbers are accepted but strings are recommended.

Conversion to ledger integers uses the asset's `precision` (number of decimal places): `amount_minor = round_major_to_minor(amount)`.

Responses expose both `amount` (major units, string) and `amount_minor` (smallest units, integer) so clients never have to guess which encoding is used.
DESC
        ,
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['from_account_uuid', 'to_account_uuid', 'amount', 'asset_code'],
            properties: [
                new OA\Property(property: 'from_account_uuid', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
                new OA\Property(property: 'to_account_uuid', type: 'string', format: 'uuid', example: '660e8400-e29b-41d4-a716-446655440000'),
                new OA\Property(
                    property: 'amount',
                    type: 'string',
                    example: '12.34',
                    description: 'Major units for `asset_code` (decimal string recommended; must not exceed the asset precision). Not smallest-unit / "cents" integers.'
                ),
                new OA\Property(property: 'asset_code', type: 'string', example: 'USD', description: 'Asset code; determines decimal precision for `amount`.'),
                new OA\Property(property: 'description', type: 'string', example: 'Payment for services', maxLength: 255),
                new OA\Property(property: 'reference', type: 'string', example: 'INV-001', maxLength: 255),
            ]
        ))
    )]
    #[OA\Response(
        response: 201,
        description: 'Transfer initiated successfully',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
                new OA\Property(property: 'status', type: 'string', example: 'pending'),
                new OA\Property(property: 'from_account', type: 'string', format: 'uuid'),
                new OA\Property(property: 'to_account', type: 'string', format: 'uuid'),
                new OA\Property(property: 'amount', type: 'string', description: 'Major units (string)', example: '12.34'),
                new OA\Property(property: 'amount_minor', type: 'integer', description: 'Amount in smallest units for the asset', example: 1234),
                new OA\Property(property: 'asset_code', type: 'string', example: 'USD'),
                new OA\Property(property: 'reference', type: 'string', nullable: true),
                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
            ]),
            new OA\Property(property: 'message', type: 'string', example: 'Transfer initiated successfully'),
        ])
    )]
    #[OA\Response(
        response: 422,
        description: 'Validation error or business rule violation',
        content: new OA\JsonContent(ref: '#/components/schemas/Error')
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(
            [
                'from_account_uuid' => 'sometimes|uuid|exists:accounts,uuid',
                'to_account_uuid'   => 'sometimes|uuid|exists:accounts,uuid|different:from_account_uuid',
                'from_account'      => 'sometimes|uuid|exists:accounts,uuid',
                'to_account'        => 'sometimes|uuid|exists:accounts,uuid|different:from_account',
                'amount'            => 'required',
                'asset_code'        => 'required|string|exists:assets,code',
                'reference'         => 'sometimes|string|max:255',
                'description'       => 'sometimes|string|max:255',
            ]
        );

        // Support both field name formats for backward compatibility
        $fromAccountUuid = $validated['from_account_uuid'] ?? $validated['from_account'] ?? null;
        $toAccountUuid = $validated['to_account_uuid'] ?? $validated['to_account'] ?? null;

        if (! $fromAccountUuid || ! $toAccountUuid) {
            return response()->json(
                [
                    'message' => 'Both from and to account UUIDs are required',
                    'errors'  => [
                        'from_account_uuid' => $fromAccountUuid ? [] : ['The from account uuid field is required.'],
                        'to_account_uuid'   => $toAccountUuid ? [] : ['The to account uuid field is required.'],
                    ],
                ],
                422
            );
        }

        $fromAccount = Account::where('uuid', $fromAccountUuid)->first();
        $toAccount = Account::where('uuid', $toAccountUuid)->first();

        // Check authorization - user must own the from account
        if ($fromAccount && $fromAccount->user_uuid !== $request->user()->uuid) {
            return response()->json(
                [
                    'message' => 'Unauthorized: You can only transfer from your own accounts',
                    'error'   => 'UNAUTHORIZED_TRANSFER',
                ],
                403
            );
        }

        if ($fromAccount && $fromAccount->frozen) {
            return response()->json(
                [
                    'message' => 'Cannot transfer from frozen account',
                    'error'   => 'SOURCE_ACCOUNT_FROZEN',
                ],
                422
            );
        }

        if ($toAccount && $toAccount->frozen) {
            return response()->json(
                [
                    'message' => 'Cannot transfer to frozen account',
                    'error'   => 'DESTINATION_ACCOUNT_FROZEN',
                ],
                422
            );
        }

        $asset = Asset::where('code', $validated['asset_code'])->firstOrFail();

        try {
            $amountStr = $this->normalizeAndValidateMajorAmountInput($validated['amount'], $asset);
        } catch (InvalidArgumentException $e) {
            return response()->json(
                [
                    'message' => 'The given data was invalid.',
                    'errors'  => [
                        'amount' => [$e->getMessage()],
                    ],
                ],
                422
            );
        }

        $amountInMinorUnits = $this->majorAmountStringToMinorUnits($amountStr, $asset->precision);

        if ($amountInMinorUnits < 1) {
            return response()->json(
                [
                    'message' => 'The given data was invalid.',
                    'errors'  => [
                        'amount' => ['The amount must be at least the smallest representable unit for this asset.'],
                    ],
                ],
                422
            );
        }

        // Check sufficient balance
        $fromBalance = $fromAccount->getBalance($validated['asset_code']);

        if ($fromBalance < $amountInMinorUnits) {
            return response()->json(
                [
                    'message'          => 'Insufficient funds',
                    'error'            => 'INSUFFICIENT_FUNDS',
                    'current_balance'  => $fromBalance,
                    'requested_amount' => $amountInMinorUnits,
                    'description'      => 'Balances and requested_amount are in smallest units for the asset (integer).',
                ],
                422
            );
        }

        $fromUuid = new AccountUuid($fromAccountUuid);
        $toUuid = new AccountUuid($toAccountUuid);

        try {
            // Use our wallet transfer workflow for all assets
            $workflow = WorkflowStub::make(WalletTransferWorkflow::class);
            $workflow->start($fromUuid, $toUuid, $validated['asset_code'], $amountInMinorUnits);
        } catch (Exception $e) {
            return response()->json(
                [
                    'message' => 'Transfer failed',
                    'error'   => 'TRANSFER_FAILED',
                ],
                422
            );
        }

        // Since we're using event sourcing, we don't have a traditional transfer record
        // Just use the provided data for the response
        $transferUuid = Str::uuid()->toString();

        $amountMajorOut = $this->minorUnitsToMajorAmountString($amountInMinorUnits, $asset->precision);

        return response()->json(
            [
                'data' => [
                    'uuid'         => $transferUuid,
                    'status'       => 'pending',
                    'from_account' => $fromAccountUuid,
                    'to_account'   => $toAccountUuid,
                    'amount'       => $amountMajorOut,
                    'amount_minor' => $amountInMinorUnits,
                    'asset_code'   => $validated['asset_code'],
                    'reference'    => $validated['reference'] ?? $validated['description'] ?? null,
                    'created_at'   => now()->toISOString(),
                ],
                'message' => 'Transfer initiated successfully',
            ],
            201
        );
    }

    /**
     * Get transfer details.
     */
    #[OA\Get(
        path: '/api/v2/transfers/{uuid}',
        operationId: 'getTransfer',
        tags: ['Transfers'],
        summary: 'Get a transfer by aggregate UUID',
        description: 'Returns a single `MoneyTransferred` event. `amount` is major units (string); `amount_minor` is smallest units (integer).',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Transfer details',
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
                new OA\Property(property: 'from_account_uuid', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'to_account_uuid', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'asset_code', type: 'string', example: 'USD'),
                new OA\Property(property: 'amount', type: 'string', description: 'Major units', example: '100.00'),
                new OA\Property(property: 'amount_minor', type: 'integer', description: 'Smallest units', example: 10000),
                new OA\Property(property: 'hash', type: 'string', nullable: true),
                new OA\Property(property: 'created_at', type: 'string'),
                new OA\Property(property: 'updated_at', type: 'string'),
            ]),
        ])
    )]
    public function show(string $uuid): JsonResponse
    {
        // Since transfers are event sourced, we need to query stored_events
        $event = DB::table('stored_events')
            ->where('event_class', 'App\Domain\Account\Events\MoneyTransferred')
            ->where('aggregate_uuid', $uuid)
            ->first();

        if (! $event) {
            abort(404, 'Transfer not found');
        }

        /** @var array<string, mixed> $properties */
        $properties = json_decode((string) $event->event_properties, true) ?: [];

        $amountMinor = $this->transferPropertiesAmountMinor($properties);
        $assetCode = $this->transferPropertiesAssetCode($properties);
        $asset = Asset::where('code', $assetCode)->first();
        $precision = $asset !== null ? $asset->precision : 2;

        return response()->json(
            [
                'data' => [
                    'uuid'              => $uuid,
                    'from_account_uuid' => $this->transferFromAccountUuid($properties),
                    'to_account_uuid'   => $this->transferToAccountUuid($properties),
                    'asset_code'        => $assetCode,
                    'amount'            => $this->minorUnitsToMajorAmountString($amountMinor, $precision),
                    'amount_minor'      => $amountMinor,
                    'hash'              => $this->transferHashFromProperties($properties),
                    'created_at'        => $event->created_at,
                    'updated_at'        => $event->created_at,
                ],
            ]
        );
    }

    /**
     * Get transfer history for an account.
     */
    #[OA\Get(
        path: '/api/v2/accounts/{accountUuid}/transfers',
        operationId: 'listAccountTransfers',
        tags: ['Transfers'],
        summary: 'List transfers involving an account',
        description: 'Each item uses the same amount contract as `GET /api/v2/transfers/{uuid}`: `amount` (major, string) and `amount_minor` (smallest units, integer).',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'accountUuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ]
    )]
    #[OA\Response(
        response: 200,
        description: 'Paginated transfer list',
        content: new OA\JsonContent(properties: [
            new OA\Property(
                property: 'data',
                type: 'array',
                items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'uuid', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'from_account_uuid', type: 'string', format: 'uuid', nullable: true),
                        new OA\Property(property: 'to_account_uuid', type: 'string', format: 'uuid', nullable: true),
                        new OA\Property(property: 'asset_code', type: 'string'),
                        new OA\Property(property: 'amount', type: 'string'),
                        new OA\Property(property: 'amount_minor', type: 'integer'),
                        new OA\Property(property: 'direction', type: 'string', enum: ['outgoing', 'incoming']),
                        new OA\Property(property: 'created_at', type: 'string'),
                    ],
                    type: 'object'
                )
            ),
            new OA\Property(property: 'meta', type: 'object'),
        ])
    )]
    public function history(string $accountUuid): JsonResponse
    {
        Account::where('uuid', $accountUuid)->firstOrFail();

        // Since transfers are event sourced, we need to query stored_events
        $events = DB::table('stored_events')
            ->where('event_class', 'App\Domain\Account\Events\MoneyTransferred')
            ->where(
                function ($query) use ($accountUuid) {
                    $query->where('aggregate_uuid', $accountUuid)
                        ->orWhereRaw("event_properties->>'$.to_uuid' = ?", [$accountUuid])
                        ->orWhereRaw("event_properties->>'$.from_uuid' = ?", [$accountUuid]);
                }
            )
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        // Transform events to transfer-like format
        $transfers = collect($events->items())->map(
            function ($event) use ($accountUuid) {
                /** @var array<string, mixed> $properties */
                $properties = json_decode((string) $event->event_properties, true) ?: [];

                $amountMinor = $this->transferPropertiesAmountMinor($properties);
                $assetCode = $this->transferPropertiesAssetCode($properties);
                $asset = Asset::where('code', $assetCode)->first();
                $precision = $asset !== null ? $asset->precision : 2;

                $fromUuid = $this->transferFromAccountUuid($properties) ?? $event->aggregate_uuid;

                return [
                    'uuid'              => $event->aggregate_uuid,
                    'from_account_uuid' => $this->transferFromAccountUuid($properties) ?? $event->aggregate_uuid,
                    'to_account_uuid'   => $this->transferToAccountUuid($properties),
                    'asset_code'        => $assetCode,
                    'amount'            => $this->minorUnitsToMajorAmountString($amountMinor, $precision),
                    'amount_minor'      => $amountMinor,
                    'direction'         => $fromUuid === $accountUuid ? 'outgoing' : 'incoming',
                    'created_at'        => $event->created_at,
                ];
            }
        );

        return response()->json(
            [
                'data' => $transfers,
                'meta' => [
                    'current_page' => $events->currentPage(),
                    'last_page'    => $events->lastPage(),
                    'per_page'     => $events->perPage(),
                    'total'        => $events->total(),
                ],
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function transferPropertiesAmountMinor(array $properties): int
    {
        $money = $properties['money'] ?? null;
        if (is_array($money) && isset($money['amount'])) {
            return (int) $money['amount'];
        }

        if (isset($properties['amount']) && is_numeric($properties['amount'])) {
            return (int) $properties['amount'];
        }

        return 0;
    }

    /**
     * Aligns with {@see TransactionController::historyAssetCodeForLegacyMoneyPayload} so stored event shapes stay consistent.
     *
     * @param  array<string, mixed>  $properties
     */
    private function transferPropertiesAssetCode(array $properties): string
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
     * @param  array<string, mixed>  $properties
     */
    private function transferFromAccountUuid(array $properties): ?string
    {
        return $this->transferEndpointUuid($properties, 'from_uuid', 'fromAccount', 'from');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function transferToAccountUuid(array $properties): ?string
    {
        return $this->transferEndpointUuid($properties, 'to_uuid', 'toAccount', 'to');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function transferEndpointUuid(array $properties, string $flatKey, string $legacyKey, string $canonicalKey): ?string
    {
        if (isset($properties[$flatKey]) && is_string($properties[$flatKey]) && $properties[$flatKey] !== '') {
            return $properties[$flatKey];
        }

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

    /**
     * @param  array<string, mixed>  $properties
     */
    private function transferHashFromProperties(array $properties): ?string
    {
        $hash = $properties['hash'] ?? null;
        if (! is_array($hash)) {
            return null;
        }

        $inner = $hash['hash'] ?? null;

        return is_string($inner) ? $inner : null;
    }

    /**
     * Normalizes request input to a non-empty major-unit decimal string. Prefer JSON strings; numeric types are supported for compatibility.
     *
     * @throws InvalidArgumentException
     */
    private function normalizeAndValidateMajorAmountInput(mixed $raw, Asset $asset): string
    {
        if ($raw === null || $raw === '') {
            throw new InvalidArgumentException('The amount field is required.');
        }

        if (is_float($raw)) {
            $minor = $asset->toSmallestUnit($raw);

            return $this->minorUnitsToMajorAmountString($minor, $asset->precision);
        }

        if (is_int($raw)) {
            return (string) $raw;
        }

        if (! is_string($raw)) {
            throw new InvalidArgumentException('The amount must be a string, integer, or number.');
        }

        $amount = trim($raw);
        if ($amount === '') {
            throw new InvalidArgumentException('The amount may not be empty.');
        }

        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('The amount must be numeric.');
        }

        if (str_starts_with($amount, '+')) {
            throw new InvalidArgumentException('The amount must not use a plus sign; use a plain decimal string.');
        }

        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/', $amount)) {
            throw new InvalidArgumentException('The amount must be a non-negative decimal string without scientific notation.');
        }

        if (str_contains($amount, '.')) {
            $parts = explode('.', $amount, 2);
            $frac = $parts[1] ?? '';
            if (strlen($frac) > $asset->precision) {
                throw new InvalidArgumentException(
                    'The amount must not have more than ' . $asset->precision . ' decimal place(s) for asset ' . $asset->code . '.'
                );
            }
        }

        return $amount;
    }

    /**
     * Half-up rounding to smallest units; avoids float math on the string path.
     */
    private function majorAmountStringToMinorUnits(string $amount, int $precision): int
    {
        if (! preg_match('/^(?<whole>(?:0|[1-9]\d*))(?:\.(?<frac>\d+))?$/', $amount, $m)) {
            return 0;
        }

        $whole = (int) $m['whole'];
        $fracPart = $m['frac'] ?? '';
        $fracPart .= str_repeat('0', $precision + 1);
        $fracDigits = substr($fracPart, 0, $precision + 1);
        $fracDigits = str_pad($fracDigits, $precision + 1, '0', STR_PAD_RIGHT);

        $fracMain = (int) substr($fracDigits, 0, $precision);
        $roundDigit = (int) substr($fracDigits, $precision, 1);

        if ($roundDigit >= 5) {
            $fracMain++;
        }

        $scale = 10 ** $precision;

        if ($fracMain >= $scale) {
            $whole += intdiv($fracMain, $scale);
            $fracMain %= $scale;
        }

        return $whole * $scale + $fracMain;
    }

    /**
     * Deterministic major-unit string from minor units (no floating-point).
     */
    private function minorUnitsToMajorAmountString(int $minor, int $precision): string
    {
        $negative = $minor < 0;
        $abs = abs($minor);

        if ($precision === 0) {
            $result = (string) $abs;
        } else {
            $scale = 10 ** $precision;
            $whole = intdiv($abs, $scale);
            $frac = $abs % $scale;
            $fracStr = str_pad((string) $frac, $precision, '0', STR_PAD_LEFT);
            $result = $whole . '.' . $fracStr;
        }

        return $negative ? '-' . $result : $result;
    }
}
