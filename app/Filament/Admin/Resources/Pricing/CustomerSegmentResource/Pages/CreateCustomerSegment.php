<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pricing\CustomerSegmentResource\Pages;

use App\Filament\Admin\Resources\Pricing\CustomerSegmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomerSegment extends CreateRecord
{
    protected static string $resource = CustomerSegmentResource::class;
}
