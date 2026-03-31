<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PocketResource\Pages;

use App\Filament\Admin\Resources\PocketResource;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewPocket extends ViewRecord
{
    protected static string $resource = PocketResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                \Filament\Infolists\Components\Section::make('Pocket Details')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('name'),
                        \Filament\Infolists\Components\TextEntry::make('user.name'),
                        \Filament\Infolists\Components\TextEntry::make('category'),
                        \Filament\Infolists\Components\TextEntry::make('color'),
                    ])->columns(2),

                \Filament\Infolists\Components\Section::make('Financials')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('current_amount')
                            ->money('USD'),
                        \Filament\Infolists\Components\TextEntry::make('target_amount')
                            ->money('USD'),
                        \Filament\Infolists\Components\TextEntry::make('target_date')
                            ->date(),
                        \Filament\Infolists\Components\TextEntry::make('progress_percentage')
                            ->label('Progress %')
                            ->numeric(),
                    ])->columns(2),

                \Filament\Infolists\Components\Section::make('Smart Rules')
                    ->schema([
                        \Filament\Infolists\Components\IconEntry::make('smartRule.round_up_change')
                            ->label('Round Up Change'),
                        \Filament\Infolists\Components\IconEntry::make('smartRule.auto_save_deposits')
                            ->label('Auto Save Deposits'),
                        \Filament\Infolists\Components\IconEntry::make('smartRule.auto_save_salary')
                            ->label('Auto Save Salary'),
                        \Filament\Infolists\Components\IconEntry::make('smartRule.lock_pocket')
                            ->label('Lock Pocket'),
                    ])->columns(2),

                \Filament\Infolists\Components\Section::make('Status')
                    ->schema([
                        \Filament\Infolists\Components\IconEntry::make('is_completed')
                            ->label('Completed'),
                        \Filament\Infolists\Components\TextEntry::make('created_at')
                            ->dateTime(),
                        \Filament\Infolists\Components\TextEntry::make('updated_at')
                            ->dateTime(),
                    ])->columns(3),
            ]);
    }
}