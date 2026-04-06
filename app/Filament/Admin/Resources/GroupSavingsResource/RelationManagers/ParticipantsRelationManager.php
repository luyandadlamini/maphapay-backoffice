<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GroupSavingsResource\RelationManagers;

use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ParticipantsRelationManager extends RelationManager
{
    protected static string $relationship = 'participants';

    protected static ?string $title = 'Members';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->default('Unknown User'),
                Tables\Columns\TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin'  => 'danger',
                        'member' => 'success',
                        default  => 'gray',
                    }),
                Tables\Columns\TextColumn::make('joined_at')
                    ->label('Joined')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('left_at')
                    ->label('Left At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('joined_at', 'desc')
            ->filters([
                //
            ])
            ->headerActions([
            ])
            ->actions([
                Tables\Actions\Action::make('remove_member')
                    ->label('Remove Member')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remove Member from Group')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Reason for removal')
                            ->required()
                            ->minLength(5),
                    ])
                    ->action(function ($record, array $data): void {
                        $record->update(['left_at' => now()]);

                        if (function_exists('activity')) {
                            activity()
                                ->performedOn($record)
                                ->causedBy(auth()->user())
                                ->withProperties(['reason' => $data['reason']])
                                ->log('removed_from_group');
                        }
                    })
                    ->visible(fn ($record): bool => is_null($record->left_at) && auth()->user()?->can('manage-group-savings')),
            ])
            ->bulkActions([
            ]);
    }
}
