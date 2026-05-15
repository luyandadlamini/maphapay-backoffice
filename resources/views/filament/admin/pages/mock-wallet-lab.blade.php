<x-filament-panels::page>
    <div class="space-y-6">
        <form wire:submit="fundAccount" class="space-y-4">
            {{ $this->form }}

            <div class="flex flex-wrap justify-end gap-3">
                <x-filament::button type="button" color="gray" wire:click="lookupBalance">
                    Check Balance
                </x-filament::button>

                <x-filament::button type="button" color="warning" wire:click="runMovement">
                    Run Movement
                </x-filament::button>

                <x-filament::button type="submit" color="success">
                    Fund Mock Account
                </x-filament::button>
            </div>
        </form>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-filament::section>
                <x-slot name="heading">
                    Current mock balance
                </x-slot>

                @if ($balance !== null)
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">Account</dt>
                            <dd class="font-mono text-right text-gray-950 dark:text-white">{{ $balance['account_ref'] }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500">Balance</dt>
                            <dd class="font-mono text-right text-gray-950 dark:text-white">
                                {{ \App\Domain\Shared\Money\MoneyConverter::toMajorUnitString($balance['balance_minor'], 2) }} {{ $balance['currency'] }}
                            </dd>
                        </div>
                    </dl>
                @else
                    <div class="text-sm text-gray-500">No mock account loaded.</div>
                @endif
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">
                    Failure probes
                </x-slot>

                <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <div><span class="font-mono">...0003</span> always returns insufficient funds.</div>
                    <div><span class="font-mono">...0004</span> simulates a declined callback.</div>
                    <div><span class="font-mono">...0005</span> simulates a missing callback; use reconciliation or replay.</div>
                </div>
            </x-filament::section>
        </div>

        @if ($lastMovement !== null)
            <x-filament::section>
                <x-slot name="heading">
                    Last movement
                </x-slot>

                <dl class="grid gap-3 text-sm md:grid-cols-2">
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Provider request</dt>
                        <dd class="font-mono text-right text-gray-950 dark:text-white">{{ $lastMovement['provider_request_id'] }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Status</dt>
                        <dd class="font-mono text-right text-gray-950 dark:text-white">{{ $lastMovement['status'] }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Transaction row</dt>
                        <dd class="font-mono text-right text-gray-950 dark:text-white">{{ $lastMovement['transaction_id'] }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Idempotency key</dt>
                        <dd class="font-mono text-right text-gray-950 dark:text-white">{{ $lastMovement['idempotency_key'] }}</dd>
                    </div>
                </dl>

                @if (! empty($lastMovement['failure_reason']))
                    <div class="mt-4 rounded-lg border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-700 dark:border-danger-500/30 dark:bg-danger-500/10 dark:text-danger-200">
                        {{ $lastMovement['failure_reason'] }}
                    </div>
                @endif
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
