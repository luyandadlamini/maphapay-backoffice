<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

trait HasBackofficeWorkspace
{
    public static function getBackofficeWorkspace(): string
    {
        return static::$backofficeWorkspace;
    }
}
