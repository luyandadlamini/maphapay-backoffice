<x-filament-panels::page>
    <div class="grid gap-6 max-w-4xl">
        {{-- Quick Account Lookup --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Quick Account Lookup</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Search for an account by UUID</p>
            </div>
            <div class="p-6">
                <div class="flex gap-4">
                    <div class="flex-1">
                        <x-filament::input
                            type="text"
                            wire:model.live.debounce.500ms="accountUuid"
                            placeholder="Enter Account UUID"
                            class="w-full"
                        />
                    </div>
                </div>

                @if($selectedAccount)
                    <div class="mt-4 p-4 bg-warning-50 dark:bg-warning-900/20 rounded-lg border border-warning-200 dark:border-warning-800">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-warning-100 dark:bg-warning-900 flex items-center justify-center">
                                <svg class="w-5 h-5 text-warning-600 dark:text-warning-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="font-medium text-gray-900 dark:text-white">{{ $selectedAccount->name }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    UUID: {{ $selectedAccount->uuid }} | User: {{ $selectedAccount->user?->name ?? 'Unknown' }}
                                </p>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Adjustment Form --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Balance Adjustment</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Apply manual balance corrections and adjustments</p>
            </div>
            <div class="p-6">
                @if(!$selectedAccount)
                    <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                        <svg class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <p>Search for an account above to enable balance adjustment</p>
                    </div>
                @else
                    <form wire:submit.prevent="adjustBalance">
                        {{ $this->form }}

                        <div class="mt-6 flex justify-end gap-3">
                            <x-filament::button
                                type="button"
                                color="gray"
                                wire:click="$refresh"
                            >
                                Cancel
                            </x-filament::button>
                            <x-filament::button
                                type="submit"
                                color="warning"
                                :disabled="!$selectedAccount"
                            >
                                Apply Adjustment
                            </x-filament::button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>