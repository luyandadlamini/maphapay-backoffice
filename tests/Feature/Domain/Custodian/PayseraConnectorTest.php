<?php

use App\Domain\Account\DataObjects\Money;
use App\Domain\Custodian\Connectors\PayseraConnector;
use App\Domain\Custodian\ValueObjects\TransferRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Mock successful OAuth response
    Http::fake([
        'https://bank.paysera.com/oauth/v1/token' => Http::response([
            'access_token' => 'mock-access-token',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
        ], 200),
    ]);
});

it('allows empty credentials in testing environment', function () {
    // In testing environment, empty credentials are allowed
    $connector = new PayseraConnector([]);

    expect($connector)->toBeInstanceOf(PayseraConnector::class);
    expect($connector->getName())->toBe('Paysera');
});

it('can be instantiated with valid config', function () {
    $connector = new PayseraConnector([
        'client_id'     => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);

    expect($connector)->toBeInstanceOf(PayseraConnector::class);
    expect($connector->getName())->toBe('Paysera');
});

it('checks availability via health endpoint', function () {
    Http::fake([
        'https://bank.paysera.com/rest/v1/health' => Http::response(['status' => 'ok'], 200),
    ]);

    $connector = new PayseraConnector([
        'client_id'     => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);

    expect($connector->isAvailable())->toBeTrue();
});

it('returns false when health check fails', function () {
    Http::fake([
        'https://bank.paysera.com/rest/v1/health' => Http::response(['error' => 'Service unavailable'], 503),
    ]);

    $connector = new PayseraConnector([
        'client_id'     => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);

    expect($connector->isAvailable())->toBeFalse();
});

it('obtains access token with client credentials', function () {
    Http::fake([
        'https://bank.paysera.com/oauth/v1/token' => Http::response([
            'access_token' => 'test-token-123',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
        ], 200),
        'https://bank.paysera.com/rest/v1/accounts/123/balance' => Http::response([
            'balances' => [
                ['currency' => 'EUR', 'amount' => '100000'],
            ],
        ], 200),
    ]);

    $connector = new PayseraConnector([
        'client_id'     => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);

    // This should trigger token acquisition
    $balance = $connector->getBalance('123', 'EUR');

    expect($balance)->toBeInstanceOf(Money::class);
    expect($balance->getAmount())->toBe(100000);

    // Verify OAuth request was made
    Http::assertSent(function ($request) {
        return $request->url() === 'https://bank.paysera.com/oauth/v1/token' &&
               $request['grant_type'] === 'client_credentials' &&
               $request['client_id'] === 'test-client-id' &&
               $request['client_secret'] === 'test-client-secret';
    });
});

it('retrieves account balance', function () {
    Http::fake([
        'https://bank.paysera.com/rest/v1/accounts/ACC123/balance' => Http::response([
            'balances' => [
                ['currency' => 'EUR', 'amount' => '50000'],
                ['currency' => 'USD', 'amount' => '25000'],
            ],
        ], 200),
    ]);

    $connector = new PayseraConnector([
        'client_id'     => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);

    $balance = $connector->getBalance('ACC123', 'USD');

    expect($balance)->toBeInstanceOf(Money::class);
    expect($balance->getAmount())->toBe(25000);
});

it('returns zero balance for non-existent currency', function () {
    Http::fake([
        'https://bank.paysera.com/rest/v1/accounts/ACC123/balance' => Http::response([
            'balances' => [
                ['currency' => 'EUR', 'amount' => '50000'],
            ],
        ], 200),
    ]);

    $connector = new PayseraConnector([
        'client_id'     => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);

    $balance = $connector->getBalance('ACC123', 'GBP');

    expect($balance->getAmount())->toBe(0);
});

it('retrieves account information', function () {
    Http::fake([
        'https://bank.paysera.com/rest/v1/accounts/ACC123' => Http::response([
            'id'               => 'ACC123',
            'name'             => 'Business Account',
            'status'           => 'active',
            'default_currency' => 'EUR',
            'type'             => 'business',
            'iban'             => 'LT123456789012345678',
            'bic'              => 'PAYSLTXX',
            'created_at'       => '2023-01-15T10:00:00Z',
        ], 200),
        'https://bank.paysera.com/rest/v1/accounts/ACC123/balance' => Http::response([
            'balances' => [
                ['currency' => 'EUR', 'amount' => '150000'],
                ['currency' => 'USD', 'amount' => '75000'],
            ],
        ], 200),
    ]);

    $connector = new PayseraConnector([
        'client_id'     => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);

    $accountInfo = $connector->getAccountInfo('ACC123');

    expect($accountInfo->accountId)->toBe('ACC123');
    expect($accountInfo->name)->toBe('Business Account');
    expect($accountInfo->status)->toBe('active');
    expect($accountInfo->type)->toBe('business');
    expect($accountInfo->balances)->toBe([
        'EUR' => 150000,
        'USD' => 75000,
    ]);
    expect($accountInfo->metadata['iban'])->toBe('LT123456789012345678');
    expect($accountInfo->metadata['bic'])->toBe('PAYSLTXX');
});

it('initiates a transfer', function () {
    Http::fake([
        'https://bank.paysera.com/rest/v1/payments' => Http::response([
            'id'           => 'PAY123',
            'status'       => 'pending',
            'from_account' => 'ACC001',
            'to_account'   => 'ACC002',
            'currency'     => 'EUR',
            'amount'       => '10000',
            'fee'          => '50',
            'reference'    => 'REF123',
            'created_at'   => '2023-06-17T10:00:00Z',
        ], 200),
    ]);

    $connector = new PayseraConnector([
        'client_id'     => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);

    $transferRequest = new TransferRequest(
        fromAccount: 'ACC001',
        toAccount: 'ACC002',
        amount: new Money(10000),
        assetCode: 'EUR',
        reference: 'REF123',
        description: 'Test payment'
    );

    $receipt = $connector->initiateTransfer($transferRequest);

    expect($receipt->id)->toBe('PAY123');
    expect($receipt->status)->toBe('pending');
    expect($receipt->amount)->toBe(10000);
    expect($receipt->fee)->toBe(50);
    expect($receipt->isPending())->toBeTrue();
});

it('retrieves transaction status', function () {
    Http::fake([
        'https://bank.paysera.com/rest/v1/payments/PAY123' => Http::response([
            'id'           => 'PAY123',
            'status'       => 'completed',
            'from_account' => 'ACC001',
            'to_account'   => 'ACC002',
            'currency'     => 'EUR',
            'amount'       => '10000',
            'fee'          => '50',
            'reference'    => 'REF123',
            'created_at'   => '2023-06-17T10:00:00Z',
            'completed_at' => '2023-06-17T10:05:00Z',
        ], 200),
    ]);

    $connector = new PayseraConnector([
        'client_id'     => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);

    $receipt = $connector->getTransactionStatus('PAY123');

    expect($receipt->status)->toBe('completed');
    expect($receipt->isCompleted())->toBeTrue();
    expect($receipt->completedAt)->not->toBeNull();
});

it('cancels a transaction', function () {
    Http::fake([
        'https://bank.paysera.com/rest/v1/payments/PAY123/cancel' => Http::response([], 200),
    ]);

    $connector = new PayseraConnector([
        'client_id'     => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);

    $result = $connector->cancelTransaction('PAY123');

    expect($result)->toBeTrue();
});

it('validates account existence', function () {
    Http::fake([
        'https://bank.paysera.com/rest/v1/accounts/ACC123' => Http::response([
            'id'     => 'ACC123',
            'status' => 'active',
        ], 200),
    ]);

    $connector = new PayseraConnector([
        'client_id'     => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);

    expect($connector->validateAccount('ACC123'))->toBeTrue();
});

it('returns false for invalid account', function () {
    Http::fake([
        'https://bank.paysera.com/rest/v1/accounts/INVALID' => Http::response(['error' => 'Not found'], 404),
    ]);

    $connector = new PayseraConnector([
        'client_id'     => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);

    expect($connector->validateAccount('INVALID'))->toBeFalse();
});

it('retrieves transaction history', function () {
    Http::fake([
        'https://bank.paysera.com/rest/v1/accounts/ACC123/payments?limit=10&offset=0' => Http::response([
            'payments' => [
                [
                    'id'           => 'PAY001',
                    'status'       => 'completed',
                    'from_account' => 'ACC123',
                    'to_account'   => 'ACC456',
                    'currency'     => 'EUR',
                    'amount'       => '5000',
                    'fee'          => '25',
                    'reference'    => 'REF001',
                    'created_at'   => '2023-06-17T09:00:00Z',
                    'completed_at' => '2023-06-17T09:05:00Z',
                ],
                [
                    'id'           => 'PAY002',
                    'status'       => 'completed',
                    'from_account' => 'ACC789',
                    'to_account'   => 'ACC123',
                    'currency'     => 'EUR',
                    'amount'       => '15000',
                    'reference'    => 'REF002',
                    'created_at'   => '2023-06-17T10:00:00Z',
                    'completed_at' => '2023-06-17T10:02:00Z',
                ],
            ],
        ], 200),
    ]);

    $connector = new PayseraConnector([
        'client_id'     => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);

    $history = $connector->getTransactionHistory('ACC123', 10, 0);

    expect($history)->toHaveCount(2);
    expect($history[0]['id'])->toBe('PAY001');
    expect($history[0]['amount'])->toBe(5000);
    expect($history[1]['id'])->toBe('PAY002');
    expect($history[1]['amount'])->toBe(15000);
});

it('returns supported assets', function () {
    $connector = new PayseraConnector([
        'client_id'     => 'test-client-id',
        'client_secret' => 'test-client-secret',
    ]);

    $assets = $connector->getSupportedAssets();

    expect($assets)->toContain('EUR');
    expect($assets)->toContain('USD');
    expect($assets)->toContain('GBP');
    expect($assets)->not->toContain('BTC'); // Paysera is fiat-only
});
