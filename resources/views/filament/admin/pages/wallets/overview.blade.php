<x-filament-panels::page>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <x-filament::section>
            <x-slot name="heading">Linked accounts</x-slot>
            <div class="text-3xl font-semibold">{{ $linkedActiveCount }}</div>
            <div class="text-sm text-gray-500">{{ $linkedPendingCount }} pending</div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Today's transactions</x-slot>
            <div class="text-3xl font-semibold">{{ $transactionsToday }}</div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Success rate (7 days)</x-slot>
            <div class="text-3xl font-semibold">
                {{ $successRate7d === null ? '—' : $successRate7d.'%' }}
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Last activity</x-slot>
            <div class="text-3xl font-semibold">{{ $lastActivity ?? '—' }}</div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
