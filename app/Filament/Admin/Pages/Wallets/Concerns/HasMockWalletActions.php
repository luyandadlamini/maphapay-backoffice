<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Wallets\Concerns;

use App\Domain\Wallet\Models\WalletLinking;
use App\Domain\Wallet\Services\MockWalletAdminClient;
use App\Models\SecurityAuditLog;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

trait HasMockWalletActions
{
    protected function getHeaderActions(): array
    {
        if (!Auth::user()?->can('wallet.manage_mock')) {
            return [];
        }

        return [
            $this->fundAction(),
            $this->balanceAction(),
        ];
    }

    protected function fundAction(): Action
    {
        return Action::make('fund')
            ->label('Fund account')
            ->modalHeading(static::$providerLabel.' — sandbox only — not real funds')
            ->color('success')
            ->form([
                TextInput::make('account_ref')
                    ->required()
                    ->default($this->mostRecentAccountRef())
                    ->label('Account reference / MSISDN'),
                TextInput::make('amount_major')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->label('Amount (SZL)'),
                Textarea::make('note')->rows(2)->label('Note (optional)'),
            ])
            ->action(function (array $data): void {
                $client = app(MockWalletAdminClient::class);
                $amountMinor = (int) round(((float) $data['amount_major']) * 100);

                try {
                    $result = $client->fund(
                        static::$mockEndpointPath,
                        $data['account_ref'],
                        $amountMinor,
                        $data['note'] ?? null,
                    );

                    SecurityAuditLog::query()->create([
                        'event_type'  => 'wallet.mock_fund',
                        'severity'    => 'medium',
                        'user_id'     => Auth::id(),
                        'reason'      => 'Mock-funded '.static::$providerKey.' account '.$data['account_ref'].' with '.$amountMinor.' minor units',
                        'occurred_at' => now(),
                        'context'     => [
                            'provider'    => static::$providerKey,
                            'account_ref' => $data['account_ref'],
                            'amount'      => $amountMinor,
                        ],
                    ]);

                    Notification::make()
                        ->title('Funded '.$data['account_ref'])
                        ->body("New balance: {$result->currency} ".number_format($result->balanceMinor / 100, 2))
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Fund failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function balanceAction(): Action
    {
        return Action::make('balance')
            ->label('Check balance')
            ->modalHeading(static::$providerLabel.' — mock balance lookup')
            ->color('gray')
            ->form([
                TextInput::make('account_ref')
                    ->required()
                    ->default($this->mostRecentAccountRef())
                    ->label('Account reference / MSISDN'),
            ])
            ->action(function (array $data): void {
                try {
                    $result = app(MockWalletAdminClient::class)
                        ->balance(static::$mockEndpointPath, $data['account_ref']);

                    Notification::make()
                        ->title($data['account_ref'])
                        ->body("Balance: {$result->currency} ".number_format($result->balanceMinor / 100, 2))
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('No mock balance')
                        ->body('Fund the account first to create the record.')
                        ->warning()
                        ->send();
                }
            });
    }

    private function mostRecentAccountRef(): ?string
    {
        return WalletLinking::query()
            ->where('provider', static::$providerKey)
            ->latest('linked_at')
            ->value('account_ref');
    }
}
