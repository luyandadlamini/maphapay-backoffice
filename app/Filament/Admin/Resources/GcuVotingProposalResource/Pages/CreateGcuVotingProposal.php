<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GcuVotingProposalResource\Pages;

use App\Domain\Account\Models\AccountBalance;
use App\Filament\Admin\Resources\GcuVotingProposalResource;
use App\Models\Tenant;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\QueryException;
use Stancl\Tenancy\Tenancy;

class CreateGcuVotingProposal extends CreateRecord
{
    protected static string $resource = GcuVotingProposalResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['current_composition'] = config('platform.gcu.composition');

        // Calculate total GCU supply if status is active.
        // AccountBalance uses UsesTenantConnection — must iterate tenants.
        if ($data['status'] === 'active') {
            $totalGcu = 0;
            $tenancy = app(Tenancy::class);
            $originalDefault = config('database.default');

            Tenant::on('central')->lazy(100)->each(
                function (Tenant $tenant) use (&$totalGcu, $tenancy): void {
                    $tenancy->initialize($tenant);
                    try {
                        $totalGcu += (int) AccountBalance::where('asset_code', 'GCU')->sum('balance');
                    } catch (QueryException) {
                        // Tenant DB unreachable — skip.
                    } finally {
                        $tenancy->end();
                    }
                }
            );

            app('db')->setDefaultConnection($originalDefault);
            config(['database.default' => $originalDefault]);

            $data['total_gcu_supply'] = $totalGcu;
        }

        return $data;
    }
}
