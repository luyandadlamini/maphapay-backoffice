<x-filament-panels::page>
    <div class="grid gap-6 max-w-4xl">
        {{-- Quick Account Lookup --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Quick Account Lookup</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Search for an account by UUID or name</p>
            </div>
            <div class="p-6">
                <form wire:submit="lookupAccount">
                    <div class="flex gap-4">
                        <div class="flex-1">
                            <x-filament::input
                                type="text"
                                wire:model.live.debounce.500ms="accountUuid"
                                placeholder="Enter Account UUID or Name"
                                class="w-full"
                            />
                        </div>
                    </div>
                </form>

                @if($selectedAccount)
                    <div class="mt-4 p-4 bg-success-50 dark:bg-success-900/20 rounded-lg border border-success-200 dark:border-success-800">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-success-100 dark:bg-success-900 flex items-center justify-center">
                                <svg class="w-5 h-5 text-success-600 dark:text-success-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
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

        {{-- Fund Form --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Fund Account</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Add funds to a user account for testing or refunds</p>
            </div>
            <div class="p-6">
                @if(!$selectedAccount)
                    <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                        <svg class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <p>Search for an account above to enable funding</p>
                    </div>
                @else
                    <form wire:submit.prevent="fundAccount">
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
                                color="success"
                                :disabled="!$selectedAccount"
                            >
                                Fund Account
                            </x-filament::button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
