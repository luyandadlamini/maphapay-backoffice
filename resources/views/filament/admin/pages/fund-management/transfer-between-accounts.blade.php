<x-filament-panels::page>
    <div class="grid gap-6 max-w-4xl">
        {{-- Source Account Lookup --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Source Account</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Enter the account to transfer funds from</p>
            </div>
            <div class="p-6">
                <x-filament::input
                    type="text"
                    wire:model.live.debounce.500ms="sourceAccountUuid"
                    placeholder="Enter Source Account UUID"
                    class="w-full"
                />

                @if($sourceAccount)
                    <div class="mt-4 p-4 bg-info-50 dark:bg-info-900/20 rounded-lg border border-info-200 dark:border-info-800">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-info-100 dark:bg-info-900 flex items-center justify-center">
                                <svg class="w-5 h-5 text-info-600 dark:text-info-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="font-medium text-gray-900 dark:text-white">{{ $sourceAccount->name }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    UUID: {{ $sourceAccount->uuid }} | User: {{ $sourceAccount->user?->name ?? 'Unknown' }}
                                </p>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Destination Account Lookup --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Destination Account</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Enter the account to transfer funds to</p>
            </div>
            <div class="p-6">
                <x-filament::input
                    type="text"
                    wire:model.live.debounce.500ms="destinationAccountUuid"
                    placeholder="Enter Destination Account UUID"
                    class="w-full"
                />

                @if($destinationAccount)
                    <div class="mt-4 p-4 bg-success-50 dark:bg-success-900/20 rounded-lg border border-success-200 dark:border-success-800">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-success-100 dark:bg-success-900 flex items-center justify-center">
                                <svg class="w-5 h-5 text-success-600 dark:text-success-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="font-medium text-gray-900 dark:text-white">{{ $destinationAccount->name }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    UUID: {{ $destinationAccount->uuid }} | User: {{ $destinationAccount->user?->name ?? 'Unknown' }}
                                </p>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Transfer Form --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Transfer Details</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Enter the amount and details for the transfer</p>
            </div>
            <div class="p-6">
                @if(!$sourceAccount || !$destinationAccount)
                    <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                        <svg class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                        </svg>
                        <p>Select both source and destination accounts above to enable transfers</p>
                    </div>
                @else
                    <form wire:submit.prevent="executeTransfer">
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
                                color="primary"
                                :disabled="!$sourceAccount || !$destinationAccount"
                            >
                                Execute Transfer
                            </x-filament::button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>