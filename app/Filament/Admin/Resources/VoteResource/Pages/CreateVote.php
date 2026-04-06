<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VoteResource\Pages;

use App\Filament\Admin\Resources\VoteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVote extends CreateRecord
{
    protected static string $resource = VoteResource::class;
}
