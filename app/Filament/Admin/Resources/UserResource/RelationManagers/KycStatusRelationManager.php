<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class KycStatusRelationManager extends RelationManager
{
    protected static string $relationship = 'kycDocuments';

    protected static ?string $recordTitleAttribute = 'document_type';

    protected static ?string $title = 'KYC Documents';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('document_type')
                    ->label('Document Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'id_card'         => 'primary',
                        'passport'        => 'info',
                        'drivers_license' => 'success',
                        'utility_bill'    => 'warning',
                        default           => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending'  => 'warning',
                        'verified' => 'success',
                        'rejected' => 'danger',
                        'expired'  => 'gray',
                        default    => 'gray',
                    }),
                Tables\Columns\TextColumn::make('uploaded_at')
                    ->label('Uploaded')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                Tables\Columns\TextColumn::make('verified_at')
                    ->label('Verified')
                    ->dateTime('M j, Y g:i A')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('verified_by')
                    ->label('Verified By')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('rejection_reason')
                    ->label('Rejection Reason')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('uploaded_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending'  => 'Pending',
                        'verified' => 'Verified',
                        'rejected' => 'Rejected',
                        'expired'  => 'Expired',
                    ]),
                Tables\Filters\SelectFilter::make('document_type')
                    ->options([
                        'id_card'         => 'ID Card',
                        'passport'        => 'Passport',
                        'drivers_license' => 'Driver\'s License',
                        'utility_bill'    => 'Utility Bill',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }
}
