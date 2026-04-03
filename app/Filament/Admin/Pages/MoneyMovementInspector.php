<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Monitoring\Services\MoneyMovementTransactionInspector as MoneyMovementTransactionInspectorService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class MoneyMovementInspector extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationGroup = 'Banking';

    protected static ?int $navigationSort = 8;

    protected static ?string $title = 'Money Movement Inspector';

    protected static string $view = 'filament.admin.pages.money-movement-inspector';

    public ?array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $inspection = null;

    public function mount(): void
    {
        $this->form->fill([
            'lookupType' => 'trx',
            'lookupValue' => '',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Lookup')
                    ->description('Resolve a money movement by compatibility transaction ID or transfer reference.')
                    ->schema([
                        Forms\Components\Select::make('lookupType')
                            ->label('Lookup by')
                            ->options([
                                'trx' => 'TRX',
                                'reference' => 'Reference',
                            ])
                            ->required()
                            ->native(false),
                        Forms\Components\TextInput::make('lookupValue')
                            ->label('Value')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function inspect(): void
    {
        $state = $this->form->getState();
        $lookupType = (string) ($state['lookupType'] ?? 'trx');
        $lookupValue = trim((string) ($state['lookupValue'] ?? ''));

        if ($lookupValue === '') {
            Notification::make()
                ->title('Lookup value is required')
                ->danger()
                ->send();

            return;
        }

        $this->inspection = app(MoneyMovementTransactionInspectorService::class)->inspect(
            trx: $lookupType === 'trx' ? $lookupValue : null,
            reference: $lookupType === 'reference' ? $lookupValue : null,
        );

        Notification::make()
            ->title('Inspection loaded')
            ->success()
            ->send();
    }
}
