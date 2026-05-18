<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Wallets;

use App\Domain\Wallet\Models\WalletLinking;
use App\Filament\Admin\Pages\Wallets\Concerns\HasMockWalletActions;
use App\Models\SecurityAuditLog;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

abstract class AbstractLinkedAccountsPage extends Page implements HasTable
{
    use HasMockWalletActions;
    use InteractsWithTable;

    protected static ?string $navigationGroup = 'E-Wallets';

    protected static string $view = 'filament.admin.pages.wallets.table-page';

    public static string $providerKey = '';

    public static string $providerLabel = '';

    public static string $mockEndpointPath = '';

    public function getTitle(): string
    {
        return static::$providerLabel . ' — Linked accounts';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => WalletLinking::query()
                ->with('user')
                ->where('provider', static::$providerKey))
            ->columns([
                TextColumn::make('user.email')->label('User')->searchable(),
                TextColumn::make('account_ref')->label('MSISDN / Ref')->searchable(),
                TextColumn::make('link_status')->badge(),
                TextColumn::make('linked_at')->dateTime('Y-m-d H:i'),
                TextColumn::make('last_used_at')->dateTime('Y-m-d H:i')->placeholder('—'),
            ])
            ->actions([
                TableAction::make('unlink')
                    ->label('Unlink')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (WalletLinking $row): bool => Auth::user()?->can('wallet.manage_mock')
                        && $row->link_status !== WalletLinking::STATUS_DISABLED)
                    ->action(function (WalletLinking $row): void {
                        $row->fill([
                            'link_status'         => WalletLinking::STATUS_DISABLED,
                            'disabled_at'         => now(),
                            'disabled_by_user_id' => Auth::id(),
                        ])->save();
                        $row->delete();

                        SecurityAuditLog::query()->create([
                            'event_type'  => 'wallet.linking_disabled',
                            'severity'    => 'high',
                            'user_id'     => Auth::id(),
                            'reason'      => "Wallet linking disabled by admin (linking_id={$row->id}, provider={$row->provider})",
                            'occurred_at' => now(),
                            'context'     => [
                                'linking_id' => $row->id,
                                'provider'   => $row->provider,
                            ],
                        ]);
                    }),
            ]);
    }
}
