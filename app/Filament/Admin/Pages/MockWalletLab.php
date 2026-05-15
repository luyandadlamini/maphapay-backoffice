<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Asset\Models\Asset;
use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\Wallet\Mock\MockWalletFundingService;
use App\Domain\Wallet\Services\WalletCollectionResult;
use App\Domain\Wallet\Services\WalletCollectionService;
use App\Domain\Wallet\Services\WalletDisbursementResult;
use App\Domain\Wallet\Services\WalletDisbursementService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MockWalletLab extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Transactions';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Mock Wallet Lab';

    protected static string $view = 'filament.admin.pages.mock-wallet-lab';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array{account_ref: string, balance_minor: int, currency: string, recent?: array<int, array<string, mixed>>}|null */
    public ?array $balance = null;

    /** @var array<string, mixed>|null */
    public ?array $lastMovement = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-transactions') ?? false;
    }

    public function mount(): void
    {
        $this->mockWalletForm()->fill([
            'provider_id'     => array_key_first($this->providerOptions()) ?? 'emali_eswatini_mobile',
            'account_ref'     => '',
            'currency'        => 'SZL',
            'amount'          => '100.00',
            'reset'           => false,
            'movement_type'   => 'collect',
            'user_uuid'       => '',
            'link_token'      => 'mock-link-token',
            'memo'            => 'Admin mock wallet test',
            'idempotency_key' => '',
            'callback_url'    => '',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Mock account')
                    ->description('Use this for local/test provider balances. These balances live in Redis and expire automatically.')
                    ->schema([
                        Forms\Components\Select::make('provider_id')
                            ->label('Provider')
                            ->options($this->providerOptions())
                            ->required()
                            ->native(false),
                        Forms\Components\TextInput::make('account_ref')
                            ->label('External account ref')
                            ->placeholder('26876000001')
                            ->required()
                            ->maxLength(80),
                        Forms\Components\TextInput::make('currency')
                            ->required()
                            ->maxLength(12)
                            ->default('SZL')
                            ->formatStateUsing(fn (?string $state): string => strtoupper((string) ($state ?? 'SZL'))),
                        Forms\Components\TextInput::make('amount')
                            ->label('Amount')
                            ->required()
                            ->rules(['regex:/^\d+(\.\d+)?$/'])
                            ->helperText('Major units, for example 100.00.'),
                        Forms\Components\Toggle::make('reset')
                            ->label('Set absolute balance')
                            ->helperText('Off = top up. On = replace balance.'),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Movement smoke test')
                    ->description('Optional: start a collect or disburse request through the provider adapter and transaction table.')
                    ->schema([
                        Forms\Components\Select::make('movement_type')
                            ->label('Movement')
                            ->options([
                                'collect'  => 'Collect from external wallet',
                                'disburse' => 'Disburse to external wallet',
                            ])
                            ->required()
                            ->native(false),
                        Forms\Components\TextInput::make('user_uuid')
                            ->label('User UUID')
                            ->maxLength(80)
                            ->helperText('Required for collect/disburse smoke tests.'),
                        Forms\Components\TextInput::make('link_token')
                            ->label('Link token')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('idempotency_key')
                            ->label('Idempotency key')
                            ->maxLength(120)
                            ->placeholder('Generated if blank'),
                        Forms\Components\TextInput::make('callback_url')
                            ->label('Callback URL')
                            ->maxLength(255)
                            ->placeholder(url('/api/webhooks/wallets/{provider}')),
                        Forms\Components\Textarea::make('memo')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function lookupBalance(): void
    {
        if (! $this->mocksEnabled()) {
            $this->notifyMocksDisabled();

            return;
        }

        $state = $this->mockWalletForm()->getState();
        $providerId = (string) $state['provider_id'];
        $accountRef = trim((string) $state['account_ref']);

        if ($accountRef === '') {
            $this->notifyAccountRequired();

            return;
        }

        $this->balance = app(MockWalletFundingService::class)->getBalance($providerId, $accountRef);

        Notification::make()
            ->title('Mock balance loaded')
            ->success()
            ->send();
    }

    public function fundAccount(): void
    {
        if (! $this->mocksEnabled()) {
            $this->notifyMocksDisabled();

            return;
        }

        $state = $this->mockWalletForm()->getState();
        $providerId = (string) $state['provider_id'];
        $accountRef = trim((string) $state['account_ref']);
        $currency = strtoupper((string) $state['currency']);

        if ($accountRef === '') {
            $this->notifyAccountRequired();

            return;
        }

        try {
            $amountMinor = $this->amountMinor((string) $state['amount'], $currency);
            $funding = app(MockWalletFundingService::class);

            $this->balance = (bool) ($state['reset'] ?? false)
                ? $funding->setBalance($providerId, $accountRef, $amountMinor, $currency)
                : $funding->fund($providerId, $accountRef, $amountMinor, $currency);

            Notification::make()
                ->title((bool) ($state['reset'] ?? false) ? 'Mock balance reset' : 'Mock account funded')
                ->body("{$providerId} / {$accountRef} is now {$this->formatMinor($this->balance['balance_minor'], $currency)} {$currency}.")
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Mock funding failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function runMovement(): void
    {
        if (! $this->mocksEnabled()) {
            $this->notifyMocksDisabled();

            return;
        }

        $state = $this->mockWalletForm()->getState();
        $userUuid = trim((string) ($state['user_uuid'] ?? ''));

        if ($userUuid === '') {
            Notification::make()
                ->title('User UUID is required')
                ->danger()
                ->send();

            return;
        }

        $providerId = (string) $state['provider_id'];
        $accountRef = trim((string) $state['account_ref']);
        $currency = strtoupper((string) $state['currency']);
        $idempotencyKey = trim((string) ($state['idempotency_key'] ?? '')) ?: 'admin-mock-' . (string) Str::uuid();
        $callbackUrl = trim((string) ($state['callback_url'] ?? '')) ?: url("/api/webhooks/wallets/{$providerId}");
        $memo = (string) ($state['memo'] ?? 'Admin mock wallet test');

        try {
            $result = $state['movement_type'] === 'disburse'
                ? app(WalletDisbursementService::class)->disburse(
                    $providerId,
                    $userUuid,
                    $accountRef,
                    (string) ($state['link_token'] ?? ''),
                    $this->amountMinor((string) $state['amount'], $currency),
                    $currency,
                    $idempotencyKey,
                    $callbackUrl,
                    $memo,
                )
                : app(WalletCollectionService::class)->collect(
                    $providerId,
                    $userUuid,
                    $accountRef,
                    (string) ($state['link_token'] ?? ''),
                    $this->amountMinor((string) $state['amount'], $currency),
                    $currency,
                    $idempotencyKey,
                    $callbackUrl,
                    $memo,
                );

            $this->lastMovement = $this->movementToArray($result, $idempotencyKey);

            Notification::make()
                ->title('Mock movement started')
                ->body("Provider request {$this->lastMovement['provider_request_id']} is {$this->lastMovement['status']}.")
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Mock movement failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @return array<string, string>
     */
    private function providerOptions(): array
    {
        $providers = config('wallet_mocks.providers', []);

        if (! is_array($providers)) {
            return [];
        }

        $labels = [
            'mtn_momo'              => 'MTN MoMo',
            'emali_eswatini_mobile' => 'eMali',
            'fnb_ewallet'           => 'FNB eWallet',
            'standard_unayo'        => 'Standard Unayo',
            'nedbank_send_money'    => 'Nedbank Send Money',
        ];

        return collect(array_keys($providers))
            ->mapWithKeys(fn (string $providerId): array => [$providerId => $labels[$providerId] ?? $providerId])
            ->all();
    }

    private function mocksEnabled(): bool
    {
        return (bool) config('wallet_mocks.enabled')
            && (! app()->environment('production') || (bool) config('wallet_mocks.allow_in_production'));
    }

    private function mockWalletForm(): Form
    {
        $form = $this->getForm('form');

        if (! $form instanceof Form) {
            throw new RuntimeException('Mock wallet form is not available.');
        }

        return $form;
    }

    private function amountMinor(string $amount, string $currency): int
    {
        return MoneyConverter::toSmallestUnit($amount, $this->precisionFor($currency));
    }

    private function precisionFor(string $currency): int
    {
        $asset = Asset::query()->where('code', $currency)->first();

        return $asset instanceof Asset ? $asset->precision : 2;
    }

    private function formatMinor(int $amountMinor, string $currency): string
    {
        return MoneyConverter::toMajorUnitString($amountMinor, $this->precisionFor($currency));
    }

    /**
     * @return array<string, mixed>
     */
    private function movementToArray(WalletCollectionResult|WalletDisbursementResult $result, string $idempotencyKey): array
    {
        return [
            'transaction_id'      => $result->transactionId,
            'provider_request_id' => $result->providerRequestId,
            'status'              => $result->status,
            'failure_reason'      => $result->failureReason,
            'is_replay'           => $result->isReplay,
            'idempotency_key'     => $idempotencyKey,
        ];
    }

    private function notifyMocksDisabled(): void
    {
        Notification::make()
            ->title('Wallet mocks are disabled')
            ->body('Set WALLET_MOCKS_ENABLED=true in a non-production environment.')
            ->danger()
            ->send();
    }

    private function notifyAccountRequired(): void
    {
        Notification::make()
            ->title('External account ref is required')
            ->danger()
            ->send();
    }
}
