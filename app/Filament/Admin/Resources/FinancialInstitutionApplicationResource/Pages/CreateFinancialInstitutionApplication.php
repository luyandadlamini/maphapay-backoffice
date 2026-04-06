<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FinancialInstitutionApplicationResource\Pages;

use App\Filament\Admin\Resources\FinancialInstitutionApplicationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFinancialInstitutionApplication extends CreateRecord
{
    protected static string $resource = FinancialInstitutionApplicationResource::class;
}
