<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Compliance\Models\AuditLog;
use App\Filament\Admin\Resources\AuditLogResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AuditLogResource extends Resource
{
    use \App\Filament\Admin\Traits\RespectsModuleVisibility;

    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Platform';

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
            ->columns(
                [
                    //
                ]
            )
            ->filters(
                [
                    //
                ]
            )
            ->actions(
                [
                    Tables\Actions\EditAction::make(),
                ]
            )
            ->bulkActions(
                [
                    Tables\Actions\BulkActionGroup::make(
                        [
                            Tables\Actions\DeleteBulkAction::make(),
                        ]
                    ),
                ]
            );
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
            'index'  => Pages\ListAuditLogs::route('/'),
            'create' => Pages\CreateAuditLog::route('/create'),
            'edit'   => Pages\EditAuditLog::route('/{record}/edit'),
        ];
    }
}
