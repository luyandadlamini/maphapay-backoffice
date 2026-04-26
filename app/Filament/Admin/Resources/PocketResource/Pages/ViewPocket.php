<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PocketResource\Pages;

use App\Filament\Admin\Resources\PocketResource;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewPocket extends ViewRecord
{
    protected static string $resource = PocketResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Pocket Details')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('user.name'),
                        TextEntry::make('category'),
                        TextEntry::make('color'),
                    ])->columns(2),

                Section::make('Financials')
                    ->schema([
                        TextEntry::make('current_amount')
                            ->money(config('banking.default_currency', 'SZL')),
                        TextEntry::make('target_amount')
                            ->money(config('banking.default_currency', 'SZL')),
                        TextEntry::make('target_date')
                            ->date(),
                        TextEntry::make('progress_percentage')
                            ->label('Progress %')
                            ->numeric(),
                    ])->columns(2),

                Section::make('Smart Rules')
                    ->schema([
                        IconEntry::make('smartRule.round_up_change')
                            ->label('Round Up Change'),
                        IconEntry::make('smartRule.auto_save_deposits')
                            ->label('Auto Save Deposits'),
                        IconEntry::make('smartRule.auto_save_salary')
                            ->label('Auto Save Salary'),
                        IconEntry::make('smartRule.lock_pocket')
                            ->label('Lock Pocket'),
                    ])->columns(2),

                Section::make('Status')
                    ->schema([
                        IconEntry::make('is_completed')
                            ->label('Completed'),
                        TextEntry::make('created_at')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->dateTime(),
                    ])->columns(3),
            ]);
    }
}
