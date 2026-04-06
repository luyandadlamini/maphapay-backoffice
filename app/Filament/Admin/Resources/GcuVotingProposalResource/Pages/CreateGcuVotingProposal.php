<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GcuVotingProposalResource\Pages;

use App\Filament\Admin\Resources\GcuVotingProposalResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGcuVotingProposal extends CreateRecord
{
    protected static string $resource = GcuVotingProposalResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['current_composition'] = config('platform.gcu.composition');

        // Calculate total GCU supply if status is active
        if ($data['status'] === 'active') {
            $data['total_gcu_supply'] = \App\Domain\Account\Models\AccountBalance::where('asset_code', 'GCU')
                ->sum('balance');
        }

        return $data;
    }
}
