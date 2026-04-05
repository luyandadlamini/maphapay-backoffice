<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

trait MasksPii
{
    protected static function maskPhone(?string $value): string
    {
        if (! $value || auth()->user()?->can('view-pii')) {
            return $value ?? '';
        }
        return substr($value, 0, 4) . '****' . substr($value, -3);
    }

    protected static function maskEmail(?string $value): string
    {
        if (! $value || auth()->user()?->can('view-pii')) {
            return $value ?? '';
        }
        if (! str_contains($value, '@')) {
            return $value; // malformed — pass through unchanged
        }
        [$local, $domain] = explode('@', $value, 2);
        return substr($local, 0, 2) . '***@' . $domain;
    }

    protected static function maskNationalId(?string $value): string
    {
        if (! $value || auth()->user()?->can('view-pii')) {
            return $value ?? '';
        }
        return '***-****-' . substr($value, -3);
    }
}
