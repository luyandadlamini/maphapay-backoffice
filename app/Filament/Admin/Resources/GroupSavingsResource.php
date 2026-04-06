<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Models\GroupPocket;
use App\Filament\Admin\Resources\GroupSavingsResource\Pages;
use Exception;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GroupSavingsResource extends Resource
{
    protected static ?string $model = GroupPocket::class;

    protected static ?string $modelLabel = 'Group Pocket (Stokvel)';

    protected static ?string $pluralModelLabel = 'Group Pockets (Stokvels)';

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Wallets & Ledgers';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool { return false; }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-group-savings') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Group Name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('contributions_count')
                    ->label('Members')
                    ->counts('contributions')
                    ->sortable(),
                TextColumn::make('current_amount')
                    ->label('Balance')
                    ->money('SZL')
                    ->sortable(),
                TextColumn::make('target_amount')
                    ->label('Target')
                    ->money('SZL')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active'    => 'success',
                        'completed' => 'info',
                        'closed'    => 'danger',
                        default     => 'secondary',
                    }),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active'    => 'Active',
                        'completed' => 'Completed',
                        'closed'    => 'Closed',
                    ]),
                Tables\Filters\TernaryFilter::make('is_locked')
                    ->label('Locked'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('freeze_group')
                    ->label('Freeze Group')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (GroupPocket $record) => $record->status === 'active')
                    ->form([
                        Textarea::make('reason')
                            ->label('Reason for freezing')
                            ->required()
                            ->minLength(5),
                    ])
                    ->action(function (GroupPocket $record, array $data): void {
                        try {
                            $record->update(['is_locked' => true, 'status' => 'closed']);

                            if (function_exists('activity')) {
                                activity()
                                    ->performedOn($record)
                                    ->causedBy(auth()->user())
                                    ->withProperties(['reason' => $data['reason']])
                                    ->log('group_pocket_frozen');
                            }

                            Notification::make()
                                ->title('Group pocket frozen')
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()->title('Failed')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGroupSavings::route('/'),
        ];
    }
}
