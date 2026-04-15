<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Services\AccountService;
use App\Domain\Account\Services\CompanyAccountService;
use App\Domain\Account\Services\MerchantAccountService;
use App\Domain\Account\Services\Cache\AccountCacheService;
use App\Domain\Account\Workflows\CreateAccountWorkflow;
use App\Domain\Account\Workflows\DepositAccountWorkflow;
use App\Domain\Account\Workflows\DestroyAccountWorkflow;
use App\Domain\Account\Workflows\FreezeAccountWorkflow;
use App\Domain\Account\Workflows\UnfreezeAccountWorkflow;
use App\Http\Controllers\Controller;
use App\Rules\NoControlCharacters;
use App\Rules\NoSqlInjection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Throwable;
use Workflow\WorkflowStub;

class AccountController extends Controller
{
    public function __construct(
        // @phpstan-ignore-next-line
        private readonly AccountService $accountService,
        private readonly AccountCacheService $accountCache,
        private readonly MerchantAccountService $merchantAccountService,
        private readonly CompanyAccountService $companyAccountService,
    ) {
    }

        #[OA\Get(
            path: '/api/accounts',
            operationId: 'listAccounts',
            tags: ['Accounts'],
            summary: 'List accounts',
            description: 'Retrieves a list of accounts for the authenticated user',
            security: [['sanctum' => []]]
        )]
    #[OA\Response(
        response: 200,
        description: 'List of accounts',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Account')),
        ])
    )]
    public function index(Request $request): JsonResponse
    {
        // Get the authenticated user
        /** @var \App\Models\User $user */
        $user = $request->user();

        $memberships = $user->activeAccountMemberships()
            ->with('user')
            ->get();

        if ($memberships->isNotEmpty()) {
            return response()->json([
                'success' => true,
                'data' => $this->transformMemberships($memberships),
            ]);
        }

        // Retrieve accounts for the authenticated user
        $accounts = Account::where('user_uuid', $user->uuid)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(
            [
                // @phpstan-ignore-next-line
                'data' => $accounts->map(
                    function ($account) {
                        return [
                            'uuid'       => $account->uuid,
                            'user_uuid'  => $account->user_uuid,
                            'name'       => $account->name,
                            'balance'    => $account->balance,
                            'frozen'     => $account->frozen ?? false,
                            'created_at' => $account->created_at,
                            'updated_at' => $account->updated_at,
                        ];
                    }
                ),
            ]
        );
    }

    public function createMerchant(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trade_name' => ['required', 'string', 'max:255', new NoControlCharacters(), new NoSqlInjection()],
            'merchant_category' => ['required', 'string', 'max:100', new NoControlCharacters(), new NoSqlInjection()],
            'classification' => 'required|in:informal,sole_proprietor,registered_business',
            'settlement_method' => 'required|in:maphapay_wallet,mobile_money,bank',
            'location' => ['nullable', 'string', 'max:255', new NoControlCharacters(), new NoSqlInjection()],
            'description' => ['nullable', 'string', 'max:1000', new NoControlCharacters(), new NoSqlInjection()],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();
        $tenantId = (string) ($request->attributes->get('tenant_id') ?? '');

        if ($tenantId === '') {
            return response()->json([
                'success' => false,
                'message' => 'A valid account context is required to create a merchant account.',
            ], 403);
        }

        // Verify personal account membership exists
        $personalMembership = AccountMembership::query()
            ->forUser($user->uuid)
            ->where('account_type', 'personal')
            ->where('status', 'active')
            ->first();

        if ($personalMembership === null) {
            return response()->json([
                'success' => false,
                'message' => 'A personal account is required before creating a merchant account.',
            ], 403);
        }

        // KYC gate — only approved users may create a merchant account
        if (!in_array($user->kyc_status, ['approved'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Identity verification is required before creating a merchant account.',
            ], 403);
        }

        try {
            $result = $this->merchantAccountService->createForUser($user, $tenantId, $validated);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('AccountController: createMerchant failed', [
                'user_uuid' => $user->uuid,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to create merchant account. Please try again.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'account_uuid' => $result['account']->uuid,
                'tenant_id' => $tenantId,
                'account_type' => 'merchant',
                'display_name' => $result['account']->display_name,
                'role' => $result['membership']->role,
            ],
        ], 201);
    }

    public function createCompany(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255', new NoControlCharacters(), new NoSqlInjection()],
            'business_type' => 'required|in:pty_ltd,public,sole_trader,informal',
            'registration_number' => ['nullable', 'string', 'max:20', 'regex:/^R7\/\d{5}$/'],
            'tin_number' => ['nullable', 'string', 'max:20', 'regex:/^\d{10}$/'],
            'industry' => ['nullable', 'string', 'max:100', new NoControlCharacters(), new NoSqlInjection()],
            'company_size' => ['nullable', 'string', 'in:small,medium,large,enterprise'],
            'settlement_method' => 'required|in:maphapay_wallet,mobile_money,bank',
            'address' => ['nullable', 'string', 'max:500', new NoControlCharacters(), new NoSqlInjection()],
            'description' => ['nullable', 'string', 'max:1000', new NoControlCharacters(), new NoSqlInjection()],
        ]);

        // Conditional validation: formal businesses require industry and company_size
        if (in_array($validated['business_type'], ['pty_ltd', 'public', 'sole_trader'], true)) {
            if (empty($validated['industry'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Industry is required for formal business types.',
                    'errors' => ['industry' => ['Industry is required for formal business types.']],
                ], 422);
            }
            if (empty($validated['company_size'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Company size is required for formal business types.',
                    'errors' => ['company_size' => ['Company size is required for formal business types.']],
                ], 422);
            }
        }

        /** @var \App\Models\User $user */
        $user = $request->user();
        $tenantId = (string) ($request->attributes->get('tenant_id') ?? '');

        if ($tenantId === '') {
            return response()->json([
                'success' => false,
                'message' => 'A valid account context is required to create a company account.',
            ], 403);
        }

        $personalMembership = AccountMembership::query()
            ->forUser($user->uuid)
            ->where('account_type', 'personal')
            ->where('status', 'active')
            ->first();

        if ($personalMembership === null) {
            return response()->json([
                'success' => false,
                'message' => 'A personal account is required before creating a company account.',
            ], 403);
        }

        if (!in_array($user->kyc_status, ['approved'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Identity verification is required before creating a company account.',
            ], 403);
        }

        try {
            $result = $this->companyAccountService->createForUser($user, $tenantId, $validated);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('CompanyController: createCompany failed', [
                'user_uuid' => $user->uuid,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to create company account. Please try again.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'account_uuid' => $result['account']->uuid,
                'tenant_id' => $tenantId,
                'account_type' => 'company',
                'display_name' => $result['account']->display_name,
                'role' => $result['membership']->role,
                'verification_tier' => $result['membership']->verification_tier,
                'capabilities' => $result['membership']->capabilities ?? [],
            ],
        ], 201);
    }

    #[OA\Post(
            path: '/api/accounts',
            operationId: 'createAccount',
            tags: ['Accounts'],
            summary: 'Create a new account',
            description: 'Creates a new bank account for a user with an optional initial balance',
            security: [['sanctum' => []]],
            requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_uuid', 'name'], properties: [
            new OA\Property(property: 'user_uuid', type: 'string', format: 'uuid', example: '660e8400-e29b-41d4-a716-446655440000'),
            new OA\Property(property: 'name', type: 'string', example: 'Savings Account', maxLength: 255),
            new OA\Property(property: 'initial_balance', type: 'integer', example: 10000, minimum: 0, description: 'Initial balance in cents'),
            ]))
        )]
    #[OA\Response(
        response: 201,
        description: 'Account created successfully',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/Account'),
        new OA\Property(property: 'message', type: 'string', example: 'Account created successfully'),
        ])
    )]
    #[OA\Response(
        response: 422,
        description: 'Validation error',
        content: new OA\JsonContent(ref: '#/components/schemas/Error')
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(
            [
                'name'            => ['required', 'string', 'max:255', new NoControlCharacters(), new NoSqlInjection()],
                'initial_balance' => 'sometimes|integer|min:0',
            ]
        );

        // Sanitize the account name to prevent XSS
        $sanitizedName = strip_tags($validated['name']);
        $sanitizedName = htmlspecialchars($sanitizedName, ENT_QUOTES, 'UTF-8');
        // Remove dangerous protocols
        $sanitizedName = (string) preg_replace('/javascript:/i', '', $sanitizedName);
        $sanitizedName = (string) preg_replace('/data:/i', '', $sanitizedName);
        $sanitizedName = (string) preg_replace('/vbscript:/i', '', $sanitizedName);
        $sanitizedName = trim($sanitizedName);

        // Generate a UUID for the new account
        $accountUuid = Str::uuid()->toString();

        /** @var \App\Models\User $user */
        $user = $request->user();

        // Create the Account data object with the UUID
        // Always use the authenticated user's UUID, never from request
        $accountData = new \App\Domain\Account\DataObjects\Account(
            uuid: $accountUuid,
            name: $sanitizedName,
            userUuid: $user->uuid
        );

        $workflow = WorkflowStub::make(CreateAccountWorkflow::class);
        $workflow->start($accountData);

        // If initial balance is provided, make a deposit
        if (isset($validated['initial_balance']) && $validated['initial_balance'] > 0) {
            $depositWorkflow = WorkflowStub::make(DepositAccountWorkflow::class);
            $depositWorkflow->start(
                new AccountUuid($accountUuid),
                new Money($validated['initial_balance'])
            );
        }

        // Wait a moment for the projector to create the account record
        $account = Account::where('uuid', $accountUuid)->first();

        // In test mode, the account might not exist yet, so create it
        if (! $account) {
            $account = Account::create(
                [
                    'uuid'      => $accountUuid,
                    'user_uuid' => $user->uuid,
                    'name'      => $sanitizedName,
                    'balance'   => $validated['initial_balance'] ?? 0,
                ]
            );
        }

        return response()->json(
            [
                'data' => [
                    'uuid'       => $account->uuid,
                    'user_uuid'  => $account->user_uuid,
                    'name'       => $account->name,
                    'balance'    => $account->balance,
                    'frozen'     => $account->frozen ?? false,
                    'created_at' => $account->created_at,
                ],
                'message' => 'Account created successfully',
            ],
            201
        );
    }

        #[OA\Get(
            path: '/api/accounts/{uuid}',
            operationId: 'getAccount',
            tags: ['Accounts'],
            summary: 'Get account details',
            description: 'Retrieves detailed information about a specific account',
            security: [['sanctum' => []]],
            parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'Account UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            ]
        )]
    #[OA\Response(
        response: 200,
        description: 'Account details',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/Account'),
        ])
    )]
    #[OA\Response(
        response: 404,
        description: 'Account not found',
        content: new OA\JsonContent(ref: '#/components/schemas/Error')
    )]
    public function show(Request $request, string $uuid): JsonResponse
    {
        // Try to get from cache first
        $account = $this->accountCache->get($uuid);

        if (! $account) {
            abort(404, 'Account not found');
        }

        // Check authorization - user must own the account
        /** @var \App\Models\User $user */
        $user = $request->user();
        if ($account->user_uuid !== $user->uuid) {
            abort(403, 'Forbidden');
        }

        return response()->json(
            [
                'data' => [
                    'uuid'       => $account->uuid,
                    'user_uuid'  => $account->user_uuid,
                    'name'       => $account->name,
                    'balance'    => $account->balance,
                    'frozen'     => $account->frozen ?? false,
                    'created_at' => $account->created_at,
                    'updated_at' => $account->updated_at,
                ],
            ]
        );
    }

    #[OA\Patch(
        path: '/api/accounts/{uuid}',
        operationId: 'updateAccount',
        tags: ['Accounts'],
        summary: 'Update an account',
        description: 'Updates an account\'s display name',
        security: [['sanctum' => []]],
        parameters: [
        new OA\Parameter(name: 'uuid', in: 'path', description: 'Account UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'display_name', type: 'string', example: 'My Business', maxLength: 255),
        ]))
    )]
    #[OA\Response(
        response: 200,
        description: 'Account updated successfully',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/Account'),
        ])
    )]
    #[OA\Response(
        response: 422,
        description: 'Validation error',
        content: new OA\JsonContent(ref: '#/components/schemas/Error')
    )]
    public function update(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate(
            [
                'display_name' => ['sometimes', 'string', 'max:255', new NoControlCharacters(), new NoSqlInjection()],
            ]
        );

        $membership = AccountMembership::query()
            ->where('account_uuid', $uuid)
            ->where('user_uuid', $request->user()->uuid)
            ->first();

        if (! $membership) {
            abort(403, 'Forbidden');
        }

        if (isset($validated['display_name'])) {
            $sanitized = strip_tags($validated['display_name']);
            $sanitized = htmlspecialchars($sanitized, ENT_QUOTES, 'UTF-8');
            $sanitized = (string) preg_replace('/javascript:/i', '', $sanitized);
            $sanitized = (string) preg_replace('/data:/i', '', $sanitized);
            $sanitized = (string) preg_replace('/vbscript:/i', '', $sanitized);
            $sanitized = trim($sanitized);

            $membership->display_name = $sanitized;
            $membership->save();
        }

        return response()->json([
            'data' => [
                'uuid' => $membership->account_uuid,
                'display_name' => $membership->display_name,
            ],
        ]);
    }

    /**
     * @param \Illuminate\Support\Collection<int, AccountMembership> $memberships
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function transformMemberships($memberships)
    {
        return $memberships->map(function (AccountMembership $membership): array {
            if ($membership->account_type === 'personal') {
                $displayName = $membership->user?->name ?: 'Personal';
            } else {
                $displayName = $membership->display_name ?: $membership->account_uuid;
            }

            return [
                'account_uuid' => $membership->account_uuid,
                'tenant_id' => $membership->tenant_id,
                'account_type' => $membership->account_type,
                'display_name' => $displayName,
                'role' => $membership->role,
                'status' => $membership->status,
                'capabilities' => $membership->capabilities ?? [],
                'verification_tier' => $membership->verification_tier ?? 'unverified',
                'balance_preview' => null,
                'currency' => 'SZL',
            ];
        })->values();
    }

        #[OA\Delete(
            path: '/api/accounts/{uuid}',
            operationId: 'deleteAccount',
            tags: ['Accounts'],
            summary: 'Delete an account',
            description: 'Deletes an account. Account must have zero balance and not be frozen.',
            security: [['sanctum' => []]],
            parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'Account UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            ]
        )]
    #[OA\Response(
        response: 200,
        description: 'Account deletion initiated',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Account deletion initiated'),
        ])
    )]
    #[OA\Response(
        response: 422,
        description: 'Cannot delete account',
        content: new OA\JsonContent(ref: '#/components/schemas/Error')
    )]
    #[OA\Response(
        response: 404,
        description: 'Account not found',
        content: new OA\JsonContent(ref: '#/components/schemas/Error')
    )]
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $account = Account::where('uuid', $uuid)->firstOrFail();

        // Check authorization - user must own the account
        /** @var \App\Models\User $user */
        $user = $request->user();
        if ($account->user_uuid !== $user->uuid) {
            abort(403, 'Forbidden');
        }

        // Check if account has any positive balance in any asset
        $hasPositiveBalance = $account->balances()
            ->where('balance', '>', 0)
            ->exists();

        if ($hasPositiveBalance) {
            return response()->json(
                [
                    'message' => 'Cannot delete account with positive balance',
                    'error'   => 'ACCOUNT_HAS_BALANCE',
                ],
                422
            );
        }

        if ($account->frozen) {
            return response()->json(
                [
                    'message' => 'Cannot delete frozen account',
                    'error'   => 'ACCOUNT_FROZEN',
                ],
                422
            );
        }

        $accountUuid = new AccountUuid($uuid);

        $workflow = WorkflowStub::make(DestroyAccountWorkflow::class);
        $workflow->start($accountUuid);

        return response()->json(
            [
                'message' => 'Account deletion initiated',
            ]
        );
    }

        #[OA\Post(
            path: '/api/accounts/{uuid}/freeze',
            operationId: 'freezeAccount',
            tags: ['Accounts'],
            summary: 'Freeze an account',
            description: 'Freezes an account to prevent any transactions',
            security: [['sanctum' => []]],
            parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'Account UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            ],
            requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [
            new OA\Property(property: 'reason', type: 'string', example: 'Suspicious activity detected', maxLength: 255),
            new OA\Property(property: 'authorized_by', type: 'string', example: 'admin@example.com', maxLength: 255),
            ]))
        )]
    #[OA\Response(
        response: 200,
        description: 'Account frozen successfully',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Account frozen successfully'),
        ])
    )]
    #[OA\Response(
        response: 422,
        description: 'Account already frozen',
        content: new OA\JsonContent(ref: '#/components/schemas/Error')
    )]
    public function freeze(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate(
            [
                'reason'        => 'required|string|max:255',
                'authorized_by' => 'sometimes|string|max:255',
            ]
        );

        $account = Account::where('uuid', $uuid)->firstOrFail();

        // Check authorization - user must own the account OR be an admin
        // Use Spatie role check for admin, not tokenCan which is for Sanctum scopes
        /** @var \App\Models\User $user */
        $user = $request->user();
        $isAdmin = $user->hasRole(['admin', 'super_admin', 'bank_admin']);
        if (! $isAdmin && $account->user_uuid !== $user->uuid) {
            abort(403, 'Forbidden');
        }

        if ($account->frozen) {
            return response()->json(
                [
                    'message' => 'Account is already frozen',
                    'error'   => 'ACCOUNT_ALREADY_FROZEN',
                ],
                422
            );
        }

        $accountUuid = new AccountUuid($uuid);

        $workflow = WorkflowStub::make(FreezeAccountWorkflow::class);
        $workflow->start(
            $accountUuid,
            $validated['reason'],
            $validated['authorized_by'] ?? null
        );

        return response()->json(
            [
                'message' => 'Account frozen successfully',
            ]
        );
    }

        #[OA\Post(
            path: '/api/accounts/{uuid}/unfreeze',
            operationId: 'unfreezeAccount',
            tags: ['Accounts'],
            summary: 'Unfreeze an account',
            description: 'Unfreezes a previously frozen account',
            security: [['sanctum' => []]],
            parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', description: 'Account UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            ],
            requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [
            new OA\Property(property: 'reason', type: 'string', example: 'Investigation completed', maxLength: 255),
            new OA\Property(property: 'authorized_by', type: 'string', example: 'admin@example.com', maxLength: 255),
            ]))
        )]
    #[OA\Response(
        response: 200,
        description: 'Account unfrozen successfully',
        content: new OA\JsonContent(properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Account unfrozen successfully'),
        ])
    )]
    #[OA\Response(
        response: 422,
        description: 'Account not frozen',
        content: new OA\JsonContent(ref: '#/components/schemas/Error')
    )]
    public function unfreeze(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate(
            [
                'reason'        => 'required|string|max:255',
                'authorized_by' => 'sometimes|string|max:255',
            ]
        );

        $account = Account::where('uuid', $uuid)->firstOrFail();

        // Check authorization - user must own the account OR be an admin
        /** @var \App\Models\User $user */
        $user = $request->user();
        $isAdmin = $user->hasRole(['admin', 'super_admin', 'bank_admin']);
        if (! $isAdmin && $account->user_uuid !== $user->uuid) {
            abort(403, 'Forbidden');
        }

        if (! $account->frozen) {
            return response()->json(
                [
                    'message' => 'Account is not frozen',
                    'error'   => 'ACCOUNT_NOT_FROZEN',
                ],
                422
            );
        }

        $accountUuid = new AccountUuid($uuid);

        $workflow = WorkflowStub::make(UnfreezeAccountWorkflow::class);
        $workflow->start(
            $accountUuid,
            $validated['reason'],
            $validated['authorized_by'] ?? null
        );

        return response()->json(
            [
                'message' => 'Account unfrozen successfully',
            ]
        );
    }
}
