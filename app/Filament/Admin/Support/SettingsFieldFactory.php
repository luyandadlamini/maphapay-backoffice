<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support;

use App\Services\SettingsService;
use Filament\Forms;

/**
 * Builds Filament form components from {@see SettingsService} setting definitions.
 * Single place for field shape + types (REQ-FEE-001).
 */
final class SettingsFieldFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public static function make(string $key, array $config): Forms\Components\Component
    {
        return match ($config['type']) {
            'boolean' => Forms\Components\Toggle::make($key)
                ->label($config['label'])
                ->helperText($config['description'] ?? null),
            'integer' => Forms\Components\TextInput::make($key)
                ->label($config['label'])
                ->numeric()
                ->helperText($config['description'] ?? null),
            'float' => Forms\Components\TextInput::make($key)
                ->label($config['label'])
                ->numeric()
                ->step(0.01)
                ->helperText($config['description'] ?? null),
            'string' => Forms\Components\TextInput::make($key)
                ->label($config['label'])
                ->helperText($config['description'] ?? null),
            'array' => Forms\Components\TagsInput::make($key)
                ->label($config['label'])
                ->helperText($config['description'] ?? null),
            'json' => Forms\Components\KeyValue::make($key)
                ->label($config['label'])
                ->helperText($config['description'] ?? null),
            default => Forms\Components\TextInput::make($key)
                ->label($config['label'])
                ->helperText($config['description'] ?? null),
        };
    }
}
