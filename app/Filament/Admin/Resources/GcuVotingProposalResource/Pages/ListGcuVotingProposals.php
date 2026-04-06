<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GcuVotingProposalResource\Pages;

use App\Filament\Admin\Resources\GcuVotingProposalResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGcuVotingProposals extends ListRecords
{
    protected static string $resource = GcuVotingProposalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
