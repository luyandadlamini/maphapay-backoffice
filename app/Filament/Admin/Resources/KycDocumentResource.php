<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Compliance\Models\KycDocument;
use App\Filament\Admin\Resources\KycDocumentResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class KycDocumentResource extends Resource
{
    use \App\Filament\Admin\Traits\RespectsModuleVisibility;

    protected static ?string $model = KycDocument::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Compliance';

    public static function form(Form $form): Form
    {
        return $form
            ->schema(
                [
                    //
                ]
            );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('document_type')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'verified' => 'success',
                        'pending'  => 'warning',
                        'rejected' => 'danger',
                        default    => 'secondary',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('uploaded_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending'  => 'Pending',
                        'verified' => 'Verified',
                        'rejected' => 'Rejected',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('approveDocuments')
                        ->label('Approve Documents')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn () => auth()->user()->can('approve-kyc') || auth()->user()->hasRole('super-admin'))
                        ->action(function ($records): void {
                            foreach ($records as $record) {
                                $record->markAsVerified(auth()->user()->email ?? 'admin');
                            }
                            \Filament\Notifications\Notification::make()
                                ->title('Documents Approved')
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\BulkAction::make('rejectDocuments')
                        ->label('Reject Documents')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn () => auth()->user()->can('reject-kyc') || auth()->user()->hasRole('super-admin'))
                        ->form([
                            \Filament\Forms\Components\Textarea::make('reason')
                                ->label('Rejection Reason')
                                ->required(),
                        ])
                        ->action(function ($records, array $data): void {
                            foreach ($records as $record) {
                                $record->markAsRejected($data['reason'], auth()->user()->email ?? 'admin');
                            }
                            \Filament\Notifications\Notification::make()
                                ->title('Documents Rejected')
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListKycDocuments::route('/'),
            'create' => Pages\CreateKycDocument::route('/create'),
            'edit'   => Pages\EditKycDocument::route('/{record}/edit'),
        ];
    }
}
