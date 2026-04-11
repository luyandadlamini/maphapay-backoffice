<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Concerns\HasBackofficeWorkspace;
use App\Filament\Admin\Resources\FeatureFlagResource\Pages;
use App\Models\Feature;
use App\Support\Backoffice\AdminActionGovernance;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class FeatureFlagResource extends Resource
{
    use HasBackofficeWorkspace;

    protected static ?string $model = Feature::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Feature Flags';

    protected static string $backofficeWorkspace = 'platform_administration';

    public static function canViewAny(): bool
    {
        return app(BackofficeWorkspaceAccess::class)->canAccess(static::getBackofficeWorkspace());
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Feature Flag Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Flag Name')
                            ->required()
                            ->disabledOn('edit'),

                        Forms\Components\TextInput::make('scope')
                            ->label('Scope')
                            ->default('global')
                            ->disabledOn('edit'),

                        Forms\Components\Toggle::make('value')
                            ->label('Enabled')
                            ->helperText('Toggle to enable or disable this feature'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Flag')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('scope')
                    ->label('Scope')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\IconColumn::make('value')
                    ->label('Enabled')
                    ->boolean()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('enabled')
                    ->label('Enabled Only')
                    ->query(fn ($query) => $query->where('value', 'true')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('toggle')
                    ->label(fn ($record) => $record->isActive() ? 'Disable' : 'Enable')
                    ->icon(fn ($record) => $record->isActive() ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn ($record) => $record->isActive() ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Reason for change')
                            ->required()
                            ->minLength(10),
                    ])
                    ->action(function ($record, array $data): void {
                        static::authorizeWorkspace();

                        $oldValue = $record->value;
                        $newValue = ! $record->isActive();
                        $record->update(['value' => $newValue]);

                        static::adminActionGovernance()->auditDirectAction(
                            workspace: static::getBackofficeWorkspace(),
                            action: 'backoffice.feature_flags.toggled',
                            reason: (string) $data['reason'],
                            auditable: $record,
                            oldValues: ['value' => $oldValue],
                            newValues: ['value' => $newValue],
                            metadata: [
                                'flag' => $record->name,
                                'scope' => $record->scope,
                                'actor_email' => auth()->user()->email ?? 'system',
                            ],
                            tags: 'backoffice,platform,feature-flags'
                        );

                        Notification::make()
                            ->title('Feature flag updated')
                            ->body("{$record->name} has been " . ($newValue ? 'enabled' : 'disabled'))
                            ->success()
                            ->send();
                    }),

                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('enable')
                    ->label('Enable Selected')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->action(fn ($records) => $records->each->update(['value' => true]))
                    ->deselectRecordsAfterCompletion(),

                Tables\Actions\BulkAction::make('disable')
                    ->label('Disable Selected')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->action(fn ($records) => $records->each->update(['value' => false]))
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFeatureFlags::route('/'),
            'view'  => Pages\ViewFeatureFlag::route('/{record}'),
        ];
    }

    protected static function adminActionGovernance(): AdminActionGovernance
    {
        return app(AdminActionGovernance::class);
    }

    protected static function authorizeWorkspace(): void
    {
        app(BackofficeWorkspaceAccess::class)->authorize(static::getBackofficeWorkspace());
    }
}
