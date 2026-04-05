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
            'index'  => Pages\ListKycDocuments::route('/'),
            'create' => Pages\CreateKycDocument::route('/create'),
            'edit'   => Pages\EditKycDocument::route('/{record}/edit'),
        ];
    }
}
