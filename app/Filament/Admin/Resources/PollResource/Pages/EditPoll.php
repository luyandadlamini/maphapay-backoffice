<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PollResource\Pages;

use App\Filament\Admin\Resources\PollResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPoll extends EditRecord
{
    protected static string $resource = PollResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
