<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountResource\Pages;

use App\Filament\Admin\Concerns\WithAccountTenancy;
use App\Filament\Admin\Resources\AccountResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAccount extends EditRecord
{
    use WithAccountTenancy;

    protected static string $resource = AccountResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record instanceof Model) {
            $this->initializeTenancyForRecord($this->record);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
