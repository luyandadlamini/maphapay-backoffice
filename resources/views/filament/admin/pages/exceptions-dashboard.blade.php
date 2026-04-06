<x-filament-panels::page>
    <div class="space-y-6">
        @livewire(\App\Filament\Admin\Widgets\FailedMomoTransactionsWidget::class)
        @livewire(\App\Filament\Admin\Widgets\PendingAdjustmentsWidget::class)
        @livewire(\App\Filament\Admin\Widgets\CommerceExceptionWidget::class)
    </div>
</x-filament-panels::page>
